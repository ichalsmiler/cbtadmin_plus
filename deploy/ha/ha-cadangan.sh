#!/usr/bin/env bash
# =============================================================================
#  CBT HA - SERVER CADANGAN
#  Menyiapkan server cadangan yang terus menyalin data server utama:
#    - WireGuard ke server utama
#    - Replikasi MariaDB (database moodle dan cbtadmin), jeda biasanya < 1 detik
#    - Salin moodledata dan upload CBT Admin tiap menit
#    - Backup: database tiap 15 menit di jam ujian, penuh tiap malam
#    - Alamat https://DOMAIN_CADANGAN menampilkan "server cadangan, belum aktif"
#  dan membuka ujian di server ini (alamat cadangan) saat server utama rusak.
#
#  Syarat: Moodle (install-moodle.sh) dan CBT Admin sudah terpasang dengan
#          versi yang sama seperti server utama; ha-utama.sh pasang sudah
#          dijalankan di server utama; ha.conf sama dengan server utama.
#
#  Pakai (sebagai root):
#    bash ha-cadangan.sh pasang     # sekali; data server ini DIGANTI data utama
#    bash ha-cadangan.sh status     # cek replikasi, salinan file, backup
#    bash ha-cadangan.sh pindah     # server utama rusak: buka ujian di server ini
# =============================================================================
set -euo pipefail

PERAN="cadangan"; PEER_PERAN="utama"
WG_IF="wg-cbt"; WG_PORT=51820; WG_IP="10.77.0.2"; PEER_WG_IP="10.77.0.1"
MOODLE_DIR="/var/www/moodle"; CONFIG="$MOODLE_DIR/config.php"
DATA_DIR="/var/moodledata"; CBT_DIR="/var/www/cbtadmin"
HA_DIR="/etc/cbt-ha"; ROLE_FILE="/etc/cbt-ha-peran"; SSL_DIR="/etc/ssl/cbt"
DB_CNF="/etc/mysql/mariadb.conf.d/90-cbt-ha.cnf"
BACKUP_DIR="/var/backups/cbt"
DATABASES="moodle cbtadmin"

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
    [ -n "$src" ] || die "ha.conf tidak ditemukan. Salin ha.conf yang SAMA dengan server utama ke folder skrip ini."
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
    if [ "$PAKAI_WIREGUARD" != ya ]; then WG_IP="$CADANGAN_IP"; PEER_WG_IP="$UTAMA_IP"; fi
    mkdir -p "$HA_DIR"; chmod 700 "$HA_DIR"
    if [ "$(readlink -f "$src")" != "$HA_DIR/ha.conf" ]; then install -m 600 "$src" "$HA_DIR/ha.conf"; fi
}

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

confirm() {
    local word="$1" answer
    if [ "${YES:-0}" = 1 ]; then return; fi
    read -r -p "Ketik $word untuk melanjutkan: " answer
    [ "$answer" = "$word" ] || die "Dibatalkan."
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
    echo "net.ipv4.ip_nonlocal_bind = 1" > /etc/sysctl.d/90-cbt-ha.conf
    sysctl -q -p /etc/sysctl.d/90-cbt-ha.conf
    mkdir -p /etc/systemd/system/mariadb.service.d
    printf '[Unit]\nAfter=wg-quick@%s.service\nWants=wg-quick@%s.service\n' "$WG_IF" "$WG_IF" \
        > /etc/systemd/system/mariadb.service.d/cbt-ha.conf
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
# Status proses "Kembalikan" (siap/tolak/proses), dibaca server pasangan.
location = /cbt-ha-kembali { allow $PEER_WG_IP; deny all; default_type text/plain; alias /etc/cbt-ha-kembali; }
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
<meta http-equiv="refresh" content="30">
<title>Mohon tunggu</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f3f7;color:#2a2e3d;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
main{background:#fff;border-radius:12px;padding:32px;max-width:420px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .5rem}</style>
</head><body><main><h1>Mohon tunggu sebentar</h1>
<p>Server ujian sedang dipindahkan. Halaman ini <strong>memuat ulang sendiri tiap 30 detik</strong>.</p><p>Jangan tutup browser dan jangan logout. Jawaban yang sudah tersimpan aman. Bila lebih dari 5 menit, lapor ke pengawas ruang.</p></main></body></html>
EOF
    if [ "$mode" = cadangan ]; then
        # Moodle untuk siswa ditutup, tetapi panel /cbtadmin/ tetap terbuka (mode siaga: hanya menu Server Cadangan,
        # untuk tombol PINDAH saat server utama mati). install.sh menulis isi yang sama saat cbt-perbarui.
        cat > "$snip" <<'EOF'
# CBT HA: server cadangan belum aktif (dibuat oleh skrip ha)
error_page 503 /cbt-cadangan.html;
location = /cbt-cadangan.html { root /var/www/cbt-ha; internal; }
set $cbt_siaga 1;
if ($uri = /cbt-cadangan.html) { set $cbt_siaga 0; }
if ($uri ~ ^/cbtadmin(/|$)) { set $cbt_siaga 0; }
if ($uri ~ ^/cbt-ha-) { set $cbt_siaga 0; }
if ($cbt_siaga) { return 503; }
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
    log "Nginx: HTTPS lewat Cloudflare dan IP asli; alamat $url"
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

slave_field() { mariadb -e "SHOW SLAVE STATUS\G" | awk -F': ' -v k="$1" '$1 ~ "^ *"k"$" {print $2}'; }

# Skrip kecil yang dipanggil cron: salin file dari utama, dan backup.
write_helpers() {
    install -m 600 /dev/null "$HA_DIR/rsync.pass"
    derive_pass rsync > "$HA_DIR/rsync.pass"

    cat > /usr/local/sbin/cbt-sync <<EOF
#!/usr/bin/env bash
# Salin moodledata dan upload CBT Admin dari server utama (dibuat oleh ha-cadangan.sh)
set -euo pipefail
[ "\$(cat $ROLE_FILE 2>/dev/null)" = cadangan ] || exit 0
EX=(--exclude cache/ --exclude localcache/ --exclude "localcache-*/" --exclude sessions/ --exclude temp/ --exclude trashdir/ --exclude lock/)
rsync -a --delete --timeout=50 --password-file=$HA_DIR/rsync.pass "\${EX[@]}" \\
    rsync://cbtsync@$PEER_WG_IP/moodledata/ $DATA_DIR/
rsync -a --delete --timeout=50 --password-file=$HA_DIR/rsync.pass \\
    rsync://cbtsync@$PEER_WG_IP/cbtupload/ $CBT_DIR/writable/uploads/
date +%s > $HA_DIR/sync-terakhir
EOF

    cat > /usr/local/sbin/cbt-backup <<EOF
#!/usr/bin/env bash
# Backup CBT (dibuat oleh ha-cadangan.sh).  Pakai: cbt-backup db | penuh
set -euo pipefail
. $HA_DIR/ha.conf
DIR=$BACKUP_DIR
KEEP="\${BACKUP_KEEP_DAYS:-7}"
STAMP="\$(date +%Y%m%d-%H%M)"
mkdir -p "\$DIR/db" "\$DIR/files"; chmod 700 "\$DIR"

dump_db() {
    local out="\$DIR/db/cbt-\$STAMP.sql.gz"
    mariadb-dump --single-transaction --quick --routines --events --triggers --databases $DATABASES | gzip -1 > "\$out.tmp"
    mv "\$out.tmp" "\$out"
}

case "\${1:-}" in
    db) dump_db ;;
    penuh)
        dump_db
        today="\$(date +%Y%m%d)"
        last="\$(ls -1d "\$DIR"/files/20* 2>/dev/null | grep -v "/\$today\\\$" | tail -1 || true)"
        rsync -a --delete \${last:+--link-dest="\$last"} \\
            --exclude cache/ --exclude localcache/ --exclude "localcache-*/" --exclude sessions/ --exclude temp/ --exclude trashdir/ --exclude lock/ \\
            $DATA_DIR/ "\$DIR/files/\$today/"
        ;;
    *) echo "Pakai: cbt-backup db | penuh"; exit 1 ;;
esac

# Hapus backup yang lebih lama dari KEEP hari.
find "\$DIR/db" -name 'cbt-*.sql.gz' -mtime +"\$KEEP" -delete
cutoff="\$(date -d "-\$KEEP days" +%Y%m%d)"
for d in "\$DIR"/files/20*; do
    [ -d "\$d" ] && [ "\$(basename "\$d")" -lt "\$cutoff" ] && rm -rf "\$d"
done
exit 0
EOF
    chmod 700 /usr/local/sbin/cbt-sync /usr/local/sbin/cbt-backup

    cat > /etc/cron.d/cbt-ha-cadangan <<EOF
# CBT HA - server cadangan (dibuat oleh ha-cadangan.sh)
* * * * * root flock -n /run/cbt-sync.lock /usr/local/sbin/cbt-sync >/dev/null 2>&1
*/15 ${BACKUP_JAM_UJIAN:-6-17} * * * root flock -n /run/cbt-backup.lock /usr/local/sbin/cbt-backup db >/dev/null 2>&1
30 1 * * * root flock /run/cbt-backup.lock /usr/local/sbin/cbt-backup penuh >/var/log/cbt-backup.log 2>&1
EOF
    chmod 644 /etc/cron.d/cbt-ha-cadangan
}

# Pengaman + "Kembalikan" di server cadangan (tiap menit). Saat server ini sedang dipakai ujian (setelah PINDAH) dan
# server utama meminta kembali (peran pasangan "kembali"): tolak bila ujian berjalan; bila tidak, berhenti melayani
# siswa, siapkan salinan database + berkas untuk diambil server utama. Setelah server utama aktif lagi, server ini
# otomatis dipasang ulang sebagai cadangan (ha-cadangan.sh pasang). Bila server utama membatalkan, server ini aktif lagi.
tulis_jaga() {
    local prefix rsync_pass tmp
    prefix="$(sed -n "s/^\$CFG->prefix *= *'\(.*\)';.*/\1/p" "$CONFIG" | head -1)"; prefix="${prefix:-mdl_}"
    rsync_pass="$(derive_pass rsync)"
    tmp="$(mktemp)"
    cat > "$tmp" <<EOJ
#!/usr/bin/env bash
# Pengaman server CADANGAN (dibuat oleh ha-cadangan.sh). Pakai: cbt-ha-jaga [batal]
set -uo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
STATUS=/etc/cbt-ha-kembali; KIRIM=/var/lib/cbt-ha-kembali; LOG=/var/log/cbt-ha-kembali.log
catat() { echo "\$(date '+%Y-%m-%d %H:%M:%S') \$*" >> \$LOG; logger -t cbt-ha "\$*"; }
peran="\$(cat $ROLE_FILE 2>/dev/null)"
pasangan="\$(curl -fsS --max-time 5 http://$PEER_WG_IP/cbt-ha-peran 2>/dev/null | tr -d '[:space:]')"

halaman() {   # \$1 = tutup | buka
    if [ "\$1" = buka ]; then
        echo "# CBT HA: server aktif melayani ujian (dibuat oleh cbt-ha-jaga)" > /etc/nginx/snippets/cbt-ha-mode.conf
    else
        mkdir -p /var/www/cbt-ha
        cat > /var/www/cbt-ha/cbt-cadangan.html <<'EOH'
<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>Mohon tunggu</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f3f7;color:#2a2e3d;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
main{background:#fff;border-radius:12px;padding:32px;max-width:440px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .5rem}a{font-weight:600}</style>
</head><body><main><h1>Mohon tunggu sebentar</h1>
<p>Server ujian sedang dipindahkan. Halaman ini <strong>memuat ulang sendiri tiap 30 detik</strong>.</p><p>Jangan tutup browser dan jangan logout. Jawaban yang sudah tersimpan aman. Bila lebih dari 5 menit, lapor ke pengawas ruang.</p></main></body></html>
EOH
        cat > /etc/nginx/snippets/cbt-ha-mode.conf <<'EON'
# CBT HA: server cadangan berhenti, data dikembalikan ke server utama (dibuat oleh cbt-ha-jaga)
error_page 503 /cbt-cadangan.html;
location = /cbt-cadangan.html { root /var/www/cbt-ha; internal; }
set \$cbt_siaga 1;
if (\$uri = /cbt-cadangan.html) { set \$cbt_siaga 0; }
if (\$uri ~ ^/cbtadmin(/|\$)) { set \$cbt_siaga 0; }
if (\$uri ~ ^/cbt-ha-) { set \$cbt_siaga 0; }
if (\$cbt_siaga) { return 503; }
EON
    fi
    nginx -t >/dev/null 2>&1 && systemctl reload nginx
    systemctl reload "php\$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" 2>/dev/null
}

cron_ujian() {   # \$1 = mati | hidup
    local f
    for f in moodle cbtadmin; do
        if [ "\$1" = mati ]; then [ -f /etc/cron.d/\$f ] && mv /etc/cron.d/\$f /etc/cron.d/\$f.cadangan-off
        else [ -f /etc/cron.d/\$f.cadangan-off ] && mv /etc/cron.d/\$f.cadangan-off /etc/cron.d/\$f; fi
    done
}

bekukan() {
    local n sesi alasan=""
    n="\$(mariadb -N -e "SELECT COUNT(*) FROM moodle.${prefix}quiz_attempts WHERE state = 'inprogress'" 2>/dev/null || echo 0)"
    sesi="\$(mariadb -N -e "SELECT svalue FROM cbtadmin.settings WHERE skey = 'sesi_aktif'" 2>/dev/null | tr -d '[:space:]')"
    [ "\${n:-0}" -gt 0 ] && alasan="\$n siswa sedang mengerjakan ujian di server cadangan"
    [ -n "\$sesi" ] && alasan="\${alasan:+\$alasan; }sesi ujian masih aktif (nonaktifkan dulu di Setting Ujian)"
    # Dipaksa dari server utama (peran "kembali-paksa"): tidak ditolak; sesi siswa ikut tersalin (cbt-ha-redis).
    if [ -n "\$alasan" ] && [ "\${1:-}" = paksa ]; then
        catat "kembalikan DIPAKSA walau \$alasan"
        alasan=""
    fi
    if [ -n "\$alasan" ]; then
        echo "tolak \$alasan" > \$STATUS; chmod 644 \$STATUS
        return
    fi
    catat "server utama meminta kembali: server cadangan berhenti melayani siswa, data disiapkan"
    echo "proses \$(date +%s)" > \$STATUS; chmod 644 \$STATUS
    mariadb -e "SET GLOBAL read_only = 1;"
    sed -i 's/^read_only = 0/read_only = 1/' $DB_CNF
    cron_ujian mati
    echo nonaktif > $ROLE_FILE
    halaman tutup
    mkdir -p \$KIRIM; chmod 700 \$KIRIM; chown www-data:www-data \$KIRIM
    if ! mariadb-dump --single-transaction --quick --routines --events --triggers --add-drop-database \
            --databases moodle cbtadmin | gzip -1 > \$KIRIM/db.sql.gz.tmp; then
        catat "gagal membuat salinan database; server cadangan aktif lagi"
        cairkan; return
    fi
    mv \$KIRIM/db.sql.gz.tmp \$KIRIM/db.sql.gz; chown www-data:www-data \$KIRIM/db.sql.gz
    cat > /etc/rsyncd.conf <<EOR
# CBT HA - salinan data untuk dikembalikan ke server utama (dibuat oleh cbt-ha-jaga)
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
exclude = cache/ localcache/ localcache-*/ sessions/ temp/ trashdir/ lock/

[cbtupload]
path = $CBT_DIR/writable/uploads

[kembali]
path = \$KIRIM
EOR
    echo "cbtsync:$rsync_pass" > /etc/rsyncd.secrets; chmod 600 /etc/rsyncd.secrets
    systemctl enable rsync >/dev/null 2>&1; systemctl restart rsync
    echo "siap \$(date +%s)" > \$STATUS
    catat "data siap diambil server utama (\$(du -h \$KIRIM/db.sql.gz | cut -f1) database)"
}

cairkan() {   # batal: server cadangan melayani ujian lagi
    mariadb -e "SET GLOBAL read_only = 0;"
    sed -i 's/^read_only = 1/read_only = 0/' $DB_CNF
    cron_ujian hidup
    echo utama > $ROLE_FILE
    halaman buka
    systemctl disable --now rsync >/dev/null 2>&1
    rm -rf \$KIRIM \$STATUS
    catat "pengembalian dibatalkan: server cadangan melayani ujian lagi"
}

# Pindah otomatis (panel: Server Cadangan → "Pindah otomatis"): server utama tidak terhubung N menit berturut-turut
# (bawaan 3) → PINDAH lewat cbt-ha-panel (catatan tampil di panel), domain ikut dipindah (cbt-ha-dns). Hanya bila
# berkas dari server utama masih tersalin ≤ 15 menit sebelum hilang, supaya cadangan yang lama terputus tidak aktif.
HILANG=/var/lib/cbt-ha-hilang
otomatis() {
    local aktif menit mulai lama sync
    aktif="\$(mariadb -N -e "SELECT svalue FROM cbtadmin.settings WHERE skey = 'ha_pindah_otomatis'" 2>/dev/null | tr -d '[:space:]')"
    if [ "\$aktif" != ya ]; then rm -f \$HILANG; return; fi
    if [ -n "\$pasangan" ] || ping -c3 -i0.5 -W2 $PEER_WG_IP >/dev/null 2>&1; then
        [ -f \$HILANG ] && catat "server utama terhubung lagi; pindah otomatis tidak jadi"
        rm -f \$HILANG; return
    fi
    if [ ! -f \$HILANG ]; then
        date +%s > \$HILANG
        catat "server utama tidak terhubung; pindah otomatis bila berlanjut"
        return
    fi
    menit="\$(mariadb -N -e "SELECT svalue FROM cbtadmin.settings WHERE skey = 'ha_pindah_menit'" 2>/dev/null | tr -dc '0-9')"
    menit="\${menit:-3}"; [ "\$menit" -lt 2 ] && menit=2; [ "\$menit" -gt 30 ] && menit=30
    mulai="\$(cat \$HILANG)"; lama=\$(( \$(date +%s) - mulai ))
    [ \$lama -ge \$(( menit * 60 - 20 )) ] || return
    sync="\$(cat $HA_DIR/sync-terakhir 2>/dev/null || echo 0)"
    if [ \$(( mulai - sync )) -gt 900 ]; then
        catat "pindah otomatis DIBATALKAN: berkas dari server utama sudah lama tidak tersalin (data cadangan mungkin usang)"
        echo 9999999999 > \$HILANG; return
    fi
    rm -f \$HILANG
    catat "PINDAH OTOMATIS: server utama tidak terhubung \$(( lama / 60 )) menit"
    CBT_HA_OTOMATIS="\$(( lama / 60 ))" /usr/local/sbin/cbt-ha-panel pindah >> \$LOG 2>&1
}

BATAL=/etc/cbt-ha-kembali-batal
if [ "\${1:-}" = batal ]; then
    # Dibatalkan admin di konsol: jangan menerima permintaan Kembalikan lagi selama 15 menit.
    touch \$BATAL
    [ -f \$STATUS ] && [ "\$peran" = nonaktif ] && cairkan
    exit 0
fi
baru_batal() { [ -f \$BATAL ] && [ \$(( \$(date +%s) - \$(stat -c %Y \$BATAL) )) -lt 900 ]; }
case "\$peran:\$pasangan" in
    utama:kembali)
        baru_batal && exit 0
        [ -f \$STATUS ] && grep -q '^tolak' \$STATUS && [ \$(( \$(date +%s) - \$(stat -c %Y \$STATUS) )) -lt 60 ] || bekukan ;;
    utama:kembali-paksa)
        baru_batal && exit 0
        bekukan paksa ;;
    utama:*) [ -f \$STATUS ] && grep -q '^tolak' \$STATUS && rm -f \$STATUS ;;
    nonaktif:utama)
        if [ -f \$STATUS ]; then
            catat "server utama aktif lagi: server ini dipasang ulang sebagai cadangan"
            setsid nohup flock -n /run/cbt-ha-pasang.lock env YES=1 bash /usr/local/share/cbt-ha/ha-cadangan.sh pasang \
                >> \$LOG 2>&1 < /dev/null &
        fi ;;
    nonaktif:nonaktif) [ -f \$STATUS ] && cairkan ;;
    cadangan:*) otomatis ;;
esac
exit 0
EOJ
    install -m 700 "$tmp" /usr/local/sbin/cbt-ha-jaga.baru && mv /usr/local/sbin/cbt-ha-jaga.baru /usr/local/sbin/cbt-ha-jaga
    rm -f "$tmp"
    cat > /etc/cron.d/cbt-ha-jaga <<EOC
# CBT HA - pengaman dan "Kembalikan" server cadangan (dibuat oleh ha-cadangan.sh)
* * * * * root flock -n /run/cbt-ha-jaga.lock /usr/local/sbin/cbt-ha-jaga >/dev/null 2>&1
EOC
    chmod 644 /etc/cron.d/cbt-ha-jaga
    rm -f /etc/cron.d/cbt-ha-utama
}


# ----------------------------------------------------------------- pasang
# Catatan password dari instalasi server ini sendiri sudah tidak berlaku: database (termasuk akun) kini salinan
# server utama. Isinya diganti keterangan supaya tidak mengecoh; aslinya disimpan sebagai .lama.
tandai_kredensial() {
    local f
    for f in /root/cbtadmin-credentials.txt /root/moodle-credentials.txt; do
        [ -f "$f" ] || continue
        grep -q "MENGIKUTI SERVER UTAMA" "$f" && continue
        mv "$f" "$f.lama"
        cat > "$f" <<EOF
AKUN DI SERVER INI MENGIKUTI SERVER UTAMA
-----------------------------------------
Server ini adalah server CADANGAN. Database (termasuk semua akun dan password)
adalah salinan server utama ($UTAMA_IP / $URL_UTAMA).

  Username & password : sama dengan server utama, lihat berkas
                        $f di SERVER UTAMA.
  Ganti password      : hanya di panel server utama (di sini database hanya-baca).

Catatan password lama dari instalasi server ini (SUDAH TIDAK BERLAKU): $f.lama
EOF
        chmod 600 "$f"
    done
}

pasang() {
    check_prereq
    load_conf
    echo "Server ini disiapkan sebagai CADANGAN: $URL_CADANGAN ($CADANGAN_IP)."
    echo "Utama: $URL_UTAMA ($UTAMA_IP)."
    warn "Database moodle dan cbtadmin di server INI akan DIHAPUS lalu diganti salinan dari server utama."
    confirm "YA"

    if [ "$PAKAI_WIREGUARD" = ya ]; then
        setup_wireguard "$UTAMA_IP"
    else
        log "Jaringan lokal: terhubung langsung $WG_IP <-> $PEER_WG_IP (tanpa WireGuard)"
        apt-get install -y rsync >/dev/null
    fi
    local i
    for i in $(seq 1 30); do
        if ping -c1 -W1 "$PEER_WG_IP" >/dev/null 2>&1; then break; fi
        [ "$i" -eq 30 ] && die "Server utama ($PEER_WG_IP) tidak terjangkau.
  Pastikan ha-utama.sh pasang sudah dijalankan di $UTAMA_IP, ha.conf kedua server sama,
  dan port UDP $WG_PORT terbuka di firewall penyedia VPS."
        sleep 2
    done
    echo "Server utama terjangkau."
    setup_web "$URL_CADANGAN"
    set_site_mode cadangan

    log "Peran server: cadangan (cron Moodle dan token dimatikan di sini)"
    echo "$PERAN" > "$ROLE_FILE"; chmod 644 "$ROLE_FILE"
    local f
    for f in moodle cbtadmin; do
        if [ -f "/etc/cron.d/$f" ]; then mv "/etc/cron.d/$f" "/etc/cron.d/$f.cadangan-off"; fi
    done
    # Server utama membaca peran ini: setelah PINDAH ("utama") server utama lama berhenti melayani siswa.
    pasang_peran_web
    # Pengaman versi cadangan (menggantikan versi utama bila server ini dulunya utama) + sisa proses Kembalikan.
    tulis_jaga
    rm -rf /var/lib/cbt-ha-kembali /etc/cbt-ha-kembali
    # Bila server ini dulunya utama, matikan sumber rsync-nya.
    if [ -f /etc/rsyncd.conf ] && grep -q "CBT HA" /etc/rsyncd.conf; then
        systemctl disable --now rsync >/dev/null 2>&1 || true
        rm -f /etc/rsyncd.conf /etc/rsyncd.secrets
    fi

    log "MariaDB: mode cadangan (hanya-baca, menerima replikasi)"
    install -d -o mysql -g adm -m 2750 /var/log/mysql   # folder binlog; tidak ada bawaan di Debian 13
    cat > "$DB_CNF" <<EOF
# Replikasi CBT HA - server CADANGAN (dibuat oleh ha-cadangan.sh)
[mysqld]
server_id = 2
log_bin = /var/log/mysql/mariadb-bin
binlog_format = ROW
binlog_expire_logs_seconds = 259200
log_slave_updates = 1
gtid_strict_mode = 1
relay_log = /var/log/mysql/relay-bin
replicate_wild_do_table = moodle.%
replicate_wild_do_table = cbtadmin.%
read_only = 1
# Sambungan ke server utama yang mati cepat dinyatakan putus (bawaan 60 detik memperlambat PINDAH).
slave_net_timeout = 15
bind_address = 127.0.0.1,$WG_IP
EOF
    systemctl restart mariadb

    local repl_pass; repl_pass="$(derive_pass repl)"
    printf '[client]\nhost=%s\nuser=cbt_repl\npassword=%s\n' "$PEER_WG_IP" "$repl_pass" > "$HA_DIR/repl.cnf"
    chmod 600 "$HA_DIR/repl.cnf"
    mariadb --defaults-extra-file="$HA_DIR/repl.cnf" -e "SELECT 1" >/dev/null \
        || die "Tidak bisa masuk ke database server utama. Jalankan ulang ha-utama.sh pasang di server utama."
    local db
    for db in $DATABASES; do
        mariadb --defaults-extra-file="$HA_DIR/repl.cnf" -N -e "SHOW DATABASES LIKE '$db'" | grep -qx "$db" \
            || die "Database $db tidak ada di server utama."
    done

    log "Menyalin database dari server utama (beberapa menit untuk data besar)"
    mkdir -p "$BACKUP_DIR"; chmod 700 "$BACKUP_DIR"
    local dump="$BACKUP_DIR/awal-$(date +%Y%m%d-%H%M).sql.gz" gtid
    mariadb -e "STOP SLAVE; RESET SLAVE ALL;" 2>/dev/null || true
    mariadb-dump --defaults-extra-file="$HA_DIR/repl.cnf" --single-transaction --quick \
        --master-data=2 --gtid --routines --events --triggers --databases $DATABASES | gzip -1 > "$dump"
    gtid="$( { zcat "$dump" | grep -m1 -o "gtid_slave_pos='[^']*'" || true; } | cut -d"'" -f2)"
    if [ -z "$gtid" ]; then
        # Cadangan: hitung GTID dari nama file dan posisi binlog yang tercatat di salinan.
        local pos_line bfile bpos
        pos_line="$( { zcat "$dump" | grep -m1 "MASTER_LOG_FILE=" || true; } )"
        bfile="$(printf '%s' "$pos_line" | sed -n "s/.*MASTER_LOG_FILE='\([^']*\)'.*/\1/p")"
        bpos="$(printf '%s' "$pos_line" | sed -n "s/.*MASTER_LOG_POS=\([0-9]*\).*/\1/p")"
        if [ -n "$bfile" ] && [ -n "$bpos" ]; then
            gtid="$(mariadb --defaults-extra-file="$HA_DIR/repl.cnf" -N -e "SELECT BINLOG_GTID_POS('$bfile', $bpos)")"
        fi
    fi
    [ -n "$gtid" ] && [ "$gtid" != NULL ] || die "Posisi replikasi (GTID) tidak ditemukan di salinan database."

    # Isi salinan tanpa mencatat ke binlog server ini, supaya urutan GTID tetap milik server utama.
    mariadb -e "SET SESSION sql_log_bin=0; DROP DATABASE IF EXISTS moodle; DROP DATABASE IF EXISTS cbtadmin;"
    mariadb -e "RESET MASTER;"
    { echo "SET SESSION sql_log_bin=0;"; zcat "$dump"; } | mariadb

    mariadb <<EOF
SET GLOBAL gtid_slave_pos = '$gtid';
CHANGE MASTER TO
    MASTER_HOST = '$PEER_WG_IP',
    MASTER_USER = 'cbt_repl',
    MASTER_PASSWORD = '$repl_pass',
    MASTER_USE_GTID = slave_pos,
    MASTER_CONNECT_RETRY = 10;
START SLAVE;
EOF
    for i in $(seq 1 30); do
        if [ "$(slave_field Slave_IO_Running)" = Yes ] && [ "$(slave_field Slave_SQL_Running)" = Yes ]; then break; fi
        [ "$i" -eq 30 ] && die "Replikasi belum berjalan: $(slave_field Last_IO_Error) $(slave_field Last_SQL_Error)"
        sleep 1
    done
    echo "Replikasi berjalan (GTID awal $gtid)."

    # Kode Moodle server ini harus sama versinya dengan server utama.
    local code_ver db_ver vfile="$MOODLE_DIR/public/version.php"
    [ -f "$vfile" ] || vfile="$MOODLE_DIR/version.php"
    code_ver="$(sed -n "s/^\$version *= *\([0-9.]*\);.*/\1/p" "$vfile" | head -1)"
    db_ver="$(mariadb -N -e "SELECT value FROM moodle.mdl_config WHERE name='version'" 2>/dev/null || true)"
    if [ -n "$db_ver" ] && [ "$code_ver" != "$db_ver" ]; then
        warn "Versi Moodle server ini ($code_ver) berbeda dengan server utama ($db_ver). Samakan sebelum ujian."
    fi

    log "Salin file Moodle dan siapkan backup"
    write_helpers
    /usr/local/sbin/cbt-sync && echo "File Moodle tersalin."
    /usr/local/sbin/cbt-backup db && echo "Backup database pertama dibuat."
    tandai_kredensial

    echo
    echo "============================================================"
    echo "  SERVER CADANGAN SIAP"
    echo "  Database : tersalin terus dari server utama"
    echo "  File     : tersalin tiap menit"
    echo "  Backup   : $BACKUP_DIR (database tiap 15 menit jam ${BACKUP_JAM_UJIAN:-6-17}, penuh tiap 01:30)"
    echo "  Cek      : bash $0 status"
    echo "  Darurat  : bash $0 pindah   (jadikan server ini utama)"
    echo "============================================================"
}

# ----------------------------------------------------------------- status
status() {
    load_conf
    echo "Peran server ini : $(cat "$ROLE_FILE" 2>/dev/null || echo 'belum diatur')"
    echo
    echo "--- Jalur ke server lain"
    local hs=""
    if [ "$PAKAI_WIREGUARD" != ya ]; then
        if ping -c1 -W2 "$PEER_WG_IP" >/dev/null 2>&1; then hs=$(date +%s); fi
    else
        hs="$(wg show "$WG_IF" latest-handshakes 2>/dev/null | awk '{print $2}')"
    fi
    # Terhubung sekarang = pasangan menjawab ping lewat terowongan (handshake hanya diperbarui ±2 menit sekali).
    if ping -c2 -i0.3 -W1 "$PEER_WG_IP" >/dev/null 2>&1; then
        echo "Terhubung ke utama, kontak terakhir $(( $(date +%s) - hs )) detik lalu"
    elif [ -n "$hs" ] && [ "$hs" -gt 0 ]; then
        echo "PUTUS dari utama: kontak terakhir $(( ($(date +%s) - hs) / 60 )) menit lalu ($PEER_WG_IP)"
    else
        echo "TIDAK terhubung ke utama ($PEER_WG_IP)"
    fi
    echo
    echo "--- Replikasi database"
    if [ -n "$(slave_field Slave_IO_Running)" ]; then
        echo "Menerima data   : $(slave_field Slave_IO_Running)"
        echo "Menerapkan data : $(slave_field Slave_SQL_Running)"
        echo "Tertinggal      : $(slave_field Seconds_Behind_Master) detik"
        local err; err="$(slave_field Last_Error)"
        [ -n "$err" ] && echo "Error terakhir  : $err"
    else
        echo "Replikasi tidak aktif (server ini bukan cadangan, atau belum dipasang)."
    fi
    echo
    echo "--- Bandingkan data ujian (utama vs cadangan)"
    local q="SELECT CONCAT((SELECT COUNT(*) FROM moodle.mdl_quiz_attempts), ' attempt quiz, ',
                           (SELECT COUNT(*) FROM moodle.mdl_question_attempt_steps), ' langkah jawaban')"
    printf 'Utama    : %s\n' "$(mariadb --defaults-extra-file="$HA_DIR/repl.cnf" -N -e "$q" 2>/dev/null || echo 'tidak terjangkau')"
    printf 'Cadangan : %s\n' "$(mariadb -N -e "$q" 2>/dev/null || echo 'tidak terbaca')"
    echo
    echo "--- Salinan file"
    if [ -f "$HA_DIR/sync-terakhir" ]; then
        echo "Terakhir disalin $(( $(date +%s) - $(cat "$HA_DIR/sync-terakhir") )) detik lalu"
    else
        echo "Belum pernah disalin"
    fi
    echo
    echo "--- Backup terbaru"
    ls -1t "$BACKUP_DIR"/db/*.sql.gz 2>/dev/null | head -3 | while read -r b; do
        echo "$(basename "$b")  $(du -h "$b" | cut -f1)"
    done
    echo "Ruang disk: $(df -h "$BACKUP_DIR" 2>/dev/null | awk 'NR==2{print $4" kosong dari "$2}')"
}

# ----------------------------------------------------------------- pindah
pindah() {
    load_conf
    [ "$(cat "$ROLE_FILE" 2>/dev/null)" = cadangan ] || die "Server ini bukan cadangan."
    echo "Server ini ($URL_CADANGAN) akan dijadikan SERVER UTAMA."
    # Pindah hanya untuk server utama yang benar-benar mati/terputus: selama server utama masih menjawab
    # (lewat terowongan WireGuard atau LAN), siswa masih bisa mengerjakan di sana dan jawaban akan bercabang.
    # Darurat (server utama hidup tetapi rusak): PAKSA=1 cbt-ha pindah — server utama lalu dinonaktifkan cbt-ha-jaga.
    if ping -c3 -i0.5 -W2 "$PEER_WG_IP" >/dev/null 2>&1; then
        if [ "${PAKSA:-}" = 1 ]; then
            warn "Server utama MASIH terhubung, tetapi PAKSA=1: tetap pindah. Server utama akan dinonaktifkan
  otomatis dalam 1 menit (cbt-ha-jaga); umumkan alamat baru ke siswa."
        else
            die "Server utama ($PEER_WG_IP) MASIH terhubung, jadi pindah ditolak supaya jawaban tidak bercabang.
  Matikan server utama atau putuskan jaringannya dulu, lalu ulangi. Bila server utama hidup tetapi rusak,
  jalankan di konsol: PAKSA=1 cbt-ha pindah"
        fi
    fi
    confirm "PINDAH"

    # Berkas (moodledata, uploads) sudah disalin cron cbt-sync tiap menit; tidak disalin ulang di sini
    # supaya pindah tidak menunggu server utama yang mati.
    local terakhir
    terakhir="$(cat "$HA_DIR/sync-terakhir" 2>/dev/null || echo 0)"
    if [ "$terakhir" -gt 0 ]; then
        echo "Berkas terakhir tersalin $(( $(date +%s) - terakhir )) detik lalu ($(date -d "@$terakhir" '+%Y-%m-%d %H:%M:%S'))."
    else
        warn "Belum ada catatan salin berkas dari server utama."
    fi

    log "1/3 Terapkan sisa data replikasi"
    echo "Menghentikan sambungan replikasi (bila server utama mati, bisa sampai 15–60 detik)..."
    mariadb -e "SET GLOBAL slave_net_timeout = 15; STOP SLAVE IO_THREAD;" 2>/dev/null || true
    local i state
    for i in $(seq 1 60); do
        state="$(slave_field Slave_SQL_Running_State)"
        case "$state" in *"read all relay log"*|"") break ;; esac
        sleep 1
    done
    echo "Posisi data: $(mariadb -N -e 'SELECT @@gtid_slave_pos')"

    log "2/3 Database dibuka untuk menulis"
    mariadb -e "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = 0;"
    sed -i 's/^read_only = 1/read_only = 0/' "$DB_CNF"

    log "3/3 Moodle dan CBT Admin dibuka untuk siswa di server ini"
    # Sesi siswa (salinan Redis dari server utama) dipakai di sini: siswa tidak perlu login ulang.
    [ -x /usr/local/sbin/cbt-ha-redis ] && /usr/local/sbin/cbt-ha-redis master
    echo "utama" > "$ROLE_FILE"
    local f
    for f in moodle cbtadmin; do
        if [ -f "/etc/cron.d/$f.cadangan-off" ]; then mv "/etc/cron.d/$f.cadangan-off" "/etc/cron.d/$f"; fi
    done
    runuser -u www-data -- php "$MOODLE_DIR/admin/cli/purge_caches.php"
    systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
    set_site_mode aktif

    # Domain ujian (DOMAIN_UTAMA) diarahkan ke server ini lewat API Cloudflare bila tokennya diatur di panel.
    local alamat="$URL_CADANGAN"
    if [ -x /usr/local/sbin/cbt-ha-dns ]; then
        local dns
        if dns="$(/usr/local/sbin/cbt-ha-dns cadangan 2>&1)"; then
            echo "$dns"
            [[ "$dns" == *"dipindah ke"* || "$dns" == *"sudah ke"* ]] && alamat="$URL_UTAMA (alamat sama, kini ke server ini)"
        else
            warn "$dns
  Pindahkan domain $DOMAIN_UTAMA ke $CADANGAN_IP secara manual di Cloudflare, atau umumkan $URL_CADANGAN."
        fi
    fi

    echo
    echo "============================================================"
    echo "  SERVER INI SEKARANG UTAMA"
    echo "  Alamat ujian untuk siswa:  $alamat"
    echo "  Siswa login lagi lalu melanjutkan quiz; jawaban tersimpan."
    echo
    echo "  Server utama yang dinyalakan lagi otomatis nonaktif (tidak menerima siswa)."
    echo "  Setelah ujian selesai: di panel server utama → Server Cadangan → Kembalikan"
    echo "  (data dari server ini disalin ke sana, server ini otomatis jadi cadangan lagi)."
    echo "============================================================"
}

case "${1:-}" in
    pasang) pasang ;;
    status) status ;;
    pindah) pindah ;;
    # Pasang ulang pengaman (cbt-ha-jaga) + alamat /cbt-ha-* saja, tanpa menyentuh data (dipanggil cbt-perbarui).
    jaga)
        load_conf; pasang_peran_web; tulis_jaga
        # Server cadangan lama: sambungan replikasi cepat dinyatakan putus (PINDAH tidak tertahan ±60 detik).
        grep -q "^slave_net_timeout" "$DB_CNF" 2>/dev/null || sed -i "/^read_only = /a slave_net_timeout = 15" "$DB_CNF" 2>/dev/null
        mariadb -e "SET GLOBAL slave_net_timeout = 15;" 2>/dev/null || true
        ;;
    *) echo "Pakai: bash $0 pasang | status | pindah | jaga   (tambahkan YES=1 di depan untuk tanpa konfirmasi)"; exit 1 ;;
esac
