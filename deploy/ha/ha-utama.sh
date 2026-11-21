#!/usr/bin/env bash
# =============================================================================
#  CBT HA - SERVER UTAMA
#  Menyiapkan server utama supaya semua data (termasuk jawaban siswa) terus
#  tersalin ke server cadangan:
#    - WireGuard: jalur pribadi terenkripsi antar-VPS (10.77.0.1 <-> 10.77.0.2)
#    - MariaDB: binlog + user replikasi untuk server cadangan
#    - rsync: sumber salinan moodledata dan upload CBT Admin
#    - Nginx: HTTPS dengan sertifikat Cloudflare Origin + IP asli siswa
#    - Moodle: alamat https://DOMAIN_UTAMA, autosave quiz tiap 60 detik
#
#  Syarat: Moodle (install-moodle.sh) dan CBT Admin sudah terpasang,
#          ha.conf sudah diisi, sertifikat Cloudflare Origin sudah disalin.
#
#  Pakai (sebagai root):
#    bash ha-utama.sh pasang    # sekali, SEBELUM ha-cadangan.sh pasang
#    bash ha-utama.sh status    # cek WireGuard dan replikasi
# =============================================================================
set -euo pipefail

PERAN="utama"; PEER_PERAN="cadangan"
WG_IF="wg-cbt"; WG_PORT=51820; WG_IP="10.77.0.1"; PEER_WG_IP="10.77.0.2"
MOODLE_DIR="/var/www/moodle"; CONFIG="$MOODLE_DIR/config.php"
DATA_DIR="/var/moodledata"; CBT_DIR="/var/www/cbtadmin"
HA_DIR="/etc/cbt-ha"; ROLE_FILE="/etc/cbt-ha-peran"; SSL_DIR="/etc/ssl/cbt"
DB_CNF="/etc/mysql/mariadb.conf.d/90-cbt-ha.cnf"

log()  { echo -e "\n\033[1;32m==> $*\033[0m"; }
warn() { echo -e "\033[1;33m[PERINGATAN] $*\033[0m"; }
die()  { echo -e "\033[1;31m[GAGAL] $*\033[0m" >&2; exit 1; }

# IP jaringan lokal (RFC 1918): server di belakang NAT sekolah, tidak bisa dihubungi dari internet.
ip_lokal() { [[ "$1" =~ ^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|127\.) ]]; }

# ----------------------------------------------------------------- umum
load_conf() {
    local src=""
    for f in "$HA_DIR/ha.conf" "$(dirname "$(readlink -f "$0")")/ha.conf" ./ha.conf; do
        if [ -f "$f" ]; then src="$f"; break; fi
    done
    [ -n "$src" ] || die "ha.conf tidak ditemukan. Salin ha.conf.contoh menjadi ha.conf (di folder skrip ini) lalu isi."
    # shellcheck disable=SC1090
    . "$src"
    local v
    for v in DOMAIN_UTAMA DOMAIN_CADANGAN UTAMA_IP CADANGAN_IP HA_SECRET; do
        [ -n "${!v:-}" ] || die "$v di ha.conf belum diisi."
    done
    [ "${#HA_SECRET}" -ge 32 ] || die "HA_SECRET terlalu pendek. Buat dengan: openssl rand -hex 32"
    # Alamat berupa IP memakai http biasa; domain Cloudflare memakai https. Dihitung per server,
    # jadi utama di LAN (IP, http) dan cadangan di VPS (domain, https) boleh dicampur.
    skema() { if [ -n "${PAKAI_HTTPS:-}" ]; then [ "$PAKAI_HTTPS" = ya ] && echo https || echo http;
              elif [[ "$1" =~ ^[0-9.]+$ ]]; then echo http; else echo https; fi; }
    URL_UTAMA="$(skema "$DOMAIN_UTAMA")://$DOMAIN_UTAMA"; URL_CADANGAN="$(skema "$DOMAIN_CADANGAN")://$DOMAIN_CADANGAN"
    local domain_sini="$DOMAIN_UTAMA"; [ "$PERAN" = cadangan ] && domain_sini="$DOMAIN_CADANGAN"
    [ "$(skema "$domain_sini")" = https ] && PAKAI_HTTPS=ya || PAKAI_HTTPS=tidak
    # Kedua server di jaringan lokal: terhubung langsung lewat IP LAN, tanpa WireGuard.
    # Salah satu di internet (mis. utama di sekolah, cadangan di VPS): lewat WireGuard.
    if [ -z "${PAKAI_WIREGUARD:-}" ]; then
        if ip_lokal "$UTAMA_IP" && ip_lokal "$CADANGAN_IP"; then PAKAI_WIREGUARD=tidak; else PAKAI_WIREGUARD=ya; fi
    fi
    if [ "$PAKAI_HTTPS" != ya ] && ! ip_lokal "$([ "$PERAN" = utama ] && echo "$UTAMA_IP" || echo "$CADANGAN_IP")"; then
        warn "Server ini di internet tetapi memakai http biasa ($domain_sini). Pakai domain + Cloudflare sebelum ujian."
    fi
    if [ "$PAKAI_WIREGUARD" != ya ]; then WG_IP="$UTAMA_IP"; PEER_WG_IP="$CADANGAN_IP"; fi
    mkdir -p "$HA_DIR"; chmod 700 "$HA_DIR"
    if [ "$(readlink -f "$src")" != "$HA_DIR/ha.conf" ]; then install -m 600 "$src" "$HA_DIR/ha.conf"; fi
}

# Kunci dan password diturunkan dari HA_SECRET, jadi kedua server bisa
# menghitungnya sendiri tanpa saling bertukar kunci.
derive()      { printf '%s' "$1:$HA_SECRET" | openssl dgst -sha256 -binary | base64; }
derive_pass() { derive "$1" | tr -dc 'A-Za-z0-9' | cut -c1-32; }

check_prereq() {
    [ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root."
    [ -f "$CONFIG" ] || die "Moodle belum terpasang ($CONFIG tidak ada). Jalankan install-moodle.sh dulu."
    [ -f "$CBT_DIR/.env" ] || die "CBT Admin belum terpasang ($CBT_DIR/.env tidak ada)."
    local ver
    ver="$(mariadb -N -e 'SELECT VERSION()' | cut -d. -f1-2)"
    printf '%s\n%s\n' "10.11" "$ver" | sort -V -C || die "MariaDB $ver terlalu lama; butuh 10.11+ (Debian 12/13)."
}

setup_wireguard() {
    local peer_ip="$1" peer_pub
    log "WireGuard: $WG_IP (server ini) <-> $PEER_WG_IP ($peer_ip)"
    apt-get install -y wireguard-tools rsync >/dev/null
    peer_pub="$(derive "wg-$PEER_PERAN" | wg pubkey)"
    # Pasangan di belakang NAT (IP lokal, mis. utama di sekolah) tidak bisa dihubungi dari sini:
    # tanpa Endpoint, dialah yang menghubungi server ini (keepalive 25 dtk menjaga jalur NAT tetap terbuka).
    local endpoint="Endpoint = $peer_ip:$WG_PORT"
    if ip_lokal "$peer_ip"; then
        endpoint="# Endpoint tidak diisi: $peer_ip di belakang NAT, ia yang menghubungi server ini."
    fi
    cat > "/etc/wireguard/$WG_IF.conf" <<EOF
# Jalur pribadi CBT HA (dibuat oleh ha-$PERAN.sh)
[Interface]
Address = $WG_IP/24
ListenPort = $WG_PORT
PrivateKey = $(derive "wg-$PERAN")

[Peer]
PublicKey = $peer_pub
$endpoint
AllowedIPs = $PEER_WG_IP/32
PersistentKeepalive = 25
EOF
    chmod 600 "/etc/wireguard/$WG_IF.conf"
    systemctl enable "wg-quick@$WG_IF" >/dev/null 2>&1
    systemctl restart "wg-quick@$WG_IF"
    if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
        # Pasangan di belakang NAT datang dari IP publik sekolah yang bisa berubah; kunci WireGuard
        # yang menyaring (paket tanpa kunci yang benar diabaikan diam-diam).
        if ip_lokal "$peer_ip"; then ufw allow "$WG_PORT"/udp >/dev/null
        else ufw allow from "$peer_ip" to any port "$WG_PORT" proto udp >/dev/null; fi
    fi
    # Layanan yang mendengarkan di IP WireGuard boleh start sebelum jalurnya naik.
    echo "net.ipv4.ip_nonlocal_bind = 1" > /etc/sysctl.d/90-cbt-ha.conf
    sysctl -q -p /etc/sysctl.d/90-cbt-ha.conf
    local svc
    for svc in mariadb rsync; do
        mkdir -p "/etc/systemd/system/$svc.service.d"
        printf '[Unit]\nAfter=wg-quick@%s.service\nWants=wg-quick@%s.service\n' "$WG_IF" "$WG_IF" \
            > "/etc/systemd/system/$svc.service.d/cbt-ha.conf"
    done
    systemctl daemon-reload
}

# Mode situs: "aktif" melayani ujian; "cadangan" menampilkan halaman "server cadangan, belum aktif"
# (database cadangan hanya-baca, jadi login di sana pasti gagal).
# Peran server ini dibaca server pasangan lewat http://<IP server ini>/cbt-ha-peran (hanya dari IP pasangan).
# Server utama memakainya untuk berhenti melayani siswa bila cadangan sudah aktif (cbt-ha-jaga).
pasang_peran_web() {
    local snip=/etc/nginx/snippets/cbt-ha-peran.conf site=/etc/nginx/sites-available/moodle
    mkdir -p /etc/nginx/snippets
    cat > "$snip" <<EOP
# CBT HA: peran server ini untuk server pasangan (dibuat oleh skrip ha)
location = /cbt-ha-peran { allow $PEER_WG_IP; deny all; default_type text/plain; alias $ROLE_FILE; }
EOP
    if ! grep -q "snippets/cbt-ha-peran.conf" "$site"; then
        sed -i "0,/server_name _;/s##server_name _;\n    include snippets/cbt-ha-peran.conf;#" "$site"
    fi
    nginx -t >/dev/null 2>&1 || { nginx -t; die "Konfigurasi Nginx tidak valid."; }
    systemctl reload nginx
}


set_site_mode() {
    local mode="$1" snip=/etc/nginx/snippets/cbt-ha-mode.conf
    mkdir -p /var/www/cbt-ha /etc/nginx/snippets
    cat > /var/www/cbt-ha/cbt-cadangan.html <<'EOF'
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Server cadangan</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f3f7;color:#2a2e3d;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
main{background:#fff;border-radius:12px;padding:32px;max-width:420px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .5rem}</style>
</head><body><main><h1>Server cadangan</h1>
<p>Server ini menyimpan salinan data ujian dan belum aktif. Silakan kerjakan ujian di alamat server utama.</p></main></body></html>
EOF
    if [ "$mode" = cadangan ]; then
        cat > "$snip" <<'EOF'
# CBT HA: server cadangan belum aktif (dibuat oleh skrip ha)
error_page 503 /cbt-cadangan.html;
location = /cbt-cadangan.html { root /var/www/cbt-ha; internal; }
if ($uri != /cbt-cadangan.html) { return 503; }
EOF
    else
        echo "# CBT HA: server aktif melayani ujian (dibuat oleh skrip ha)" > "$snip"
    fi
    local site=/etc/nginx/sites-available/moodle
    if ! grep -q "snippets/cbt-ha-mode.conf" "$site"; then
        sed -i "0,/server_name _;/s##server_name _;\\n    include snippets/cbt-ha-mode.conf;#" "$site"
    fi
    nginx -t >/dev/null 2>&1 || { nginx -t; die "Konfigurasi Nginx tidak valid."; }
    systemctl reload nginx
}

setup_web() {
    local url="$1"
    if [ "$PAKAI_HTTPS" != ya ]; then
        log "Nginx: HTTP biasa (jaringan lokal); alamat $url"
        set_wwwroot "$url"
        return
    fi
    log "Nginx: HTTPS lewat Cloudflare dan IP asli siswa; alamat $url"
    local site=/etc/nginx/sites-available/moodle
    if [ -s "$SSL_DIR/origin.pem" ] && [ -s "$SSL_DIR/origin.key" ]; then
        chmod 600 "$SSL_DIR/origin.key"
        if ! grep -q "listen 443" "$site"; then
            # Baris IPv6 hanya bila server mendukungnya (sebagian VPS tanpa IPv6).
            local l6=""; [ -e /proc/net/if_inet6 ] && l6="\n    listen [::]:443 ssl default_server;"
            sed -i "0,/listen 80 default_server;/s||listen 80 default_server;\n    listen 443 ssl default_server;$l6\n    ssl_certificate $SSL_DIR/origin.pem;\n    ssl_certificate_key $SSL_DIR/origin.key;|" "$site"
        fi
    else
        log "Sertifikat Cloudflare Origin tidak ada: https ditangani Cloudflare (Tunnel / Proxied), server tetap http"
    fi

    local tmp; tmp="$(mktemp)"
    if { echo "# IP asli siswa di belakang Cloudflare (dibuat oleh ha-$PERAN.sh)"
         curl -fsS --retry 3 https://www.cloudflare.com/ips-v4
         echo
         curl -fsS --retry 3 https://www.cloudflare.com/ips-v6
       } > "$tmp.raw"; then
        { head -1 "$tmp.raw"; sed -n '2,$p' "$tmp.raw" | sed -E '/^\s*$/d; s/^(.*)$/set_real_ip_from \1;/'
          echo "real_ip_header CF-Connecting-IP;"; } > "$tmp"
        install -m 644 "$tmp" /etc/nginx/conf.d/cloudflare-realip.conf
    else
        warn "Daftar IP Cloudflare gagal diunduh; log Moodle akan mencatat IP Cloudflare, bukan IP siswa."
    fi
    rm -f "$tmp" "$tmp.raw"
    nginx -t >/dev/null 2>&1 || { nginx -t; die "Konfigurasi Nginx tidak valid."; }
    systemctl reload nginx

    set_wwwroot "$url"
}

# Alamat Moodle dan CBT Admin di server ini.
set_wwwroot() {
    # Alamat Moodle tetap IP server ini; domain (Cloudflare Tunnel / Proxied) hanya didaftarkan lewat cbt-alamat,
    # jadi Moodle mengikuti alamat yang dibuka: http://IP di sekolah, https://domain dari luar.
    local d daftar=()
    for d in "$DOMAIN_UTAMA" "$DOMAIN_CADANGAN"; do [[ "$d" =~ ^[0-9.]+$ ]] || daftar+=("$d"); done
    [ ${#daftar[@]} -gt 0 ] || return 0
    if [ -x /usr/local/sbin/cbt-alamat ]; then
        /usr/local/sbin/cbt-alamat "${daftar[@]}" >/dev/null || warn "Domain gagal didaftarkan; jalankan: cbt-alamat ${daftar[*]}"
    else
        warn "cbt-alamat belum terpasang (jalankan cbt-perbarui), domain ${daftar[*]} belum didaftarkan."
    fi
}

mdl_cfg() { runuser -u www-data -- php "$MOODLE_DIR/admin/cli/cfg.php" "$@"; }

# ----------------------------------------------------------------- pasang
# Pengaman server utama: tiap menit (dan saat server menyala) tanya peran server cadangan. Bila cadangan sudah
# aktif (setelah PINDAH), server ini berhenti melayani siswa supaya jawaban tidak tercecer di dua server:
# peran "nonaktif", database hanya-baca, cron mati, halaman siswa menunjuk alamat cadangan, panel tetap terbuka.
tulis_jaga() {
    cat > /usr/local/sbin/cbt-ha-jaga <<EOJ
#!/usr/bin/env bash
# Pengaman server UTAMA (dibuat oleh ha-utama.sh). Pakai: cbt-ha-jaga [tunggu]
set -uo pipefail
[ "\$(cat $ROLE_FILE 2>/dev/null)" = utama ] || exit 0
coba=1; [ "\${1:-}" = tunggu ] && coba=12          # saat menyala: tunggu terowongan, sampai ±2 menit
jawab=""
for i in \$(seq 1 \$coba); do
    jawab="\$(curl -fsS --max-time 5 http://$PEER_WG_IP/cbt-ha-peran 2>/dev/null | tr -d '[:space:]')" && break
    [ "\$i" -lt "\$coba" ] && sleep 10
done
[ "\$jawab" = utama ] || exit 0

echo nonaktif > $ROLE_FILE
mariadb -e "SET GLOBAL read_only = 1;" 2>/dev/null
sed -i 's/^read_only = 0/read_only = 1/' $DB_CNF 2>/dev/null
for f in moodle cbtadmin; do
    [ -f /etc/cron.d/\$f ] && mv /etc/cron.d/\$f /etc/cron.d/\$f.cadangan-off
done
mkdir -p /var/www/cbt-ha
cat > /var/www/cbt-ha/cbt-cadangan.html <<'EOH'
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ujian dipindah</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f3f7;color:#2a2e3d;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
main{background:#fff;border-radius:12px;padding:32px;max-width:440px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .5rem}a{font-weight:600}</style>
</head><body><main><h1>Ujian dipindah ke server cadangan</h1>
<p>Server ini sedang tidak dipakai. Lanjutkan ujian di:</p>
<p><a href="$URL_CADANGAN/">$URL_CADANGAN</a></p></main></body></html>
EOH
cat > /etc/nginx/snippets/cbt-ha-mode.conf <<'EON'
# CBT HA: server utama nonaktif, cadangan sedang dipakai (dibuat oleh cbt-ha-jaga)
error_page 503 /cbt-cadangan.html;
location = /cbt-cadangan.html { root /var/www/cbt-ha; internal; }
set \$cbt_siaga 1;
if (\$uri = /cbt-cadangan.html) { set \$cbt_siaga 0; }
if (\$uri ~ ^/cbtadmin(/|\$)) { set \$cbt_siaga 0; }
if (\$cbt_siaga) { return 503; }
EON
nginx -t >/dev/null 2>&1 && systemctl reload nginx
php_v="\$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
systemctl reload "php\$php_v-fpm" 2>/dev/null
pesan="server cadangan $URL_CADANGAN sudah aktif: server ini dinonaktifkan (siswa diarahkan ke cadangan)"
logger -t cbt-ha "\$pesan"
echo "\$(date '+%Y-%m-%d %H:%M:%S') \$pesan" >> /var/log/cbt-ha-jaga.log
EOJ
    chmod 700 /usr/local/sbin/cbt-ha-jaga
    cat > /etc/cron.d/cbt-ha-utama <<EOC
# CBT HA - pengaman server utama (dibuat oleh ha-utama.sh)
* * * * * root /usr/local/sbin/cbt-ha-jaga >/dev/null 2>&1
@reboot root /usr/local/sbin/cbt-ha-jaga tunggu >/dev/null 2>&1
EOC
    chmod 644 /etc/cron.d/cbt-ha-utama
}


pasang() {
    check_prereq
    load_conf
    echo "Server ini disiapkan sebagai UTAMA: $URL_UTAMA ($UTAMA_IP)."
    echo "Cadangan: $URL_CADANGAN ($CADANGAN_IP)."

    if [ "$PAKAI_WIREGUARD" = ya ]; then
        setup_wireguard "$CADANGAN_IP"
    else
        log "Jaringan lokal: terhubung langsung $WG_IP <-> $PEER_WG_IP (tanpa WireGuard)"
        apt-get install -y rsync >/dev/null
    fi
    setup_web "$URL_UTAMA"
    set_site_mode aktif

    log "MariaDB: binlog untuk replikasi ke cadangan"
    install -d -o mysql -g adm -m 2750 /var/log/mysql   # folder binlog; tidak ada bawaan di Debian 13
    cat > "$DB_CNF" <<EOF
# Replikasi CBT HA - server UTAMA (dibuat oleh ha-utama.sh)
[mysqld]
server_id = 1
log_bin = /var/log/mysql/mariadb-bin
binlog_format = ROW
binlog_expire_logs_seconds = 259200
log_slave_updates = 1
gtid_strict_mode = 1
read_only = 0
bind_address = 127.0.0.1,$WG_IP
EOF
    systemctl restart mariadb
    # Bila server ini dulunya cadangan (setelah pindah), lepas sisa replikasinya.
    mariadb -e "STOP SLAVE; RESET SLAVE ALL;" 2>/dev/null || true
    mariadb -e "SET GLOBAL read_only = 0;"
    local repl_pass; repl_pass="$(derive_pass repl)"
    mariadb <<EOF
CREATE USER IF NOT EXISTS 'cbt_repl'@'$PEER_WG_IP' IDENTIFIED BY '$repl_pass';
ALTER USER 'cbt_repl'@'$PEER_WG_IP' IDENTIFIED BY '$repl_pass';
GRANT SELECT, RELOAD, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER, REPLICATION SLAVE, REPLICATION CLIENT
    ON *.* TO 'cbt_repl'@'$PEER_WG_IP';
FLUSH PRIVILEGES;
EOF

    log "rsync: sumber salinan file untuk cadangan (hanya untuk server cadangan)"
    cat > /etc/rsyncd.conf <<EOF
# CBT HA - sumber salinan file untuk server cadangan (dibuat oleh ha-utama.sh)
address = $WG_IP
uid = www-data
gid = www-data
use chroot = yes
read only = yes
hosts allow = $PEER_WG_IP
hosts deny = *
auth users = cbtsync
secrets file = /etc/rsyncd.secrets
max connections = 4

[moodledata]
path = $DATA_DIR
exclude = cache/ localcache/ sessions/ temp/ trashdir/ lock/

[cbtupload]
path = $CBT_DIR/writable/uploads
EOF
    echo "cbtsync:$(derive_pass rsync)" > /etc/rsyncd.secrets
    chmod 600 /etc/rsyncd.secrets
    systemctl enable rsync >/dev/null 2>&1
    systemctl restart rsync

    log "Peran server dan cron"
    echo "$PERAN" > "$ROLE_FILE"; chmod 644 "$ROLE_FILE"
    rm -f /etc/cron.d/cbt-ha-cadangan
    local f
    for f in moodle cbtadmin; do
        if [ -f "/etc/cron.d/$f.cadangan-off" ]; then mv "/etc/cron.d/$f.cadangan-off" "/etc/cron.d/$f"; fi
    done
    pasang_peran_web
    log "Pengaman: server ini berhenti melayani siswa bila cadangan sudah aktif (cbt-ha-jaga tiap menit)"
    tulis_jaga

    log "Moodle: autosave jawaban quiz tiap 60 detik"
    mdl_cfg --component=quiz --name=autosaveperiod --set=60
    runuser -u www-data -- php "$MOODLE_DIR/admin/cli/purge_caches.php"
    systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"

    echo
    echo "============================================================"
    echo "  SERVER UTAMA SIAP"
    echo "  Alamat ujian : $URL_UTAMA"
    echo "  Berikutnya   : jalankan  bash ha-cadangan.sh pasang  di $CADANGAN_IP"
    [ "$PAKAI_WIREGUARD" = ya ] && echo "  Pastikan port UDP $WG_PORT terbuka di firewall kedua server."
    echo "============================================================"
}

# ----------------------------------------------------------------- status
status() {
    load_conf
    echo "Peran server ini : $(cat "$ROLE_FILE" 2>/dev/null || echo 'belum diatur')"
    if [ "$(cat "$ROLE_FILE" 2>/dev/null)" = nonaktif ]; then
        warn "Server ini NONAKTIF: server cadangan ($URL_CADANGAN) sudah dipakai ujian, data terbaru ada di sana.
  Siswa yang membuka server ini diarahkan ke alamat cadangan. Riwayat: /var/log/cbt-ha-jaga.log"
    fi
    echo
    echo "--- Jalur ke server lain"
    local hs=""
    if [ "$PAKAI_WIREGUARD" != ya ]; then
        if ping -c1 -W2 "$PEER_WG_IP" >/dev/null 2>&1; then hs=$(date +%s); fi
    else
        hs="$(wg show "$WG_IF" latest-handshakes 2>/dev/null | awk '{print $2}')"
    fi
    if [ -n "$hs" ] && [ "$hs" -gt 0 ] && [ $(( $(date +%s) - hs )) -le 180 ]; then
        echo "Terhubung ke cadangan, kontak terakhir $(( $(date +%s) - hs )) detik lalu"
    elif [ -n "$hs" ] && [ "$hs" -gt 0 ]; then
        echo "PUTUS dari cadangan: kontak terakhir $(( ($(date +%s) - hs) / 60 )) menit lalu ($PEER_WG_IP)"
    else
        echo "BELUM terhubung ke cadangan ($PEER_WG_IP)"
    fi
    echo
    echo "--- Replikasi (sumber)"
    mariadb -N -e "SELECT CONCAT('Posisi GTID       : ', @@gtid_binlog_pos)"
    mariadb -N -e "SELECT CONCAT('Cadangan tersambung: ', COUNT(*)) FROM information_schema.PROCESSLIST WHERE COMMAND LIKE 'Binlog Dump%'"
    echo "Ukuran binlog      : $(du -sh /var/log/mysql 2>/dev/null | cut -f1)"
}

case "${1:-}" in
    pasang) pasang ;;
    status) status ;;
    *) echo "Pakai: bash $0 pasang | status"; exit 1 ;;
esac
