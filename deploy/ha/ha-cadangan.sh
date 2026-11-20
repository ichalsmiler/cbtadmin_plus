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
        # Moodle untuk siswa ditutup, tetapi panel /cbtadmin/ tetap terbuka (mode siaga: hanya menu Server Cadangan,
        # untuk tombol PINDAH saat server utama mati). install.sh menulis isi yang sama saat cbt-perbarui.
        cat > "$snip" <<'EOF'
# CBT HA: server cadangan belum aktif (dibuat oleh skrip ha)
error_page 503 /cbt-cadangan.html;
location = /cbt-cadangan.html { root /var/www/cbt-ha; internal; }
set $cbt_siaga 1;
if ($uri = /cbt-cadangan.html) { set $cbt_siaga 0; }
if ($uri ~ ^/cbtadmin(/|$)) { set $cbt_siaga 0; }
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
    log "Nginx: HTTPS (Cloudflare Origin) dan IP asli; alamat $url"
    [ -s "$SSL_DIR/origin.pem" ] && [ -s "$SSL_DIR/origin.key" ] || die "Sertifikat Cloudflare Origin belum ada.
  Salin file yang SAMA dengan server utama ke:
    $SSL_DIR/origin.pem   (Origin Certificate)
    $SSL_DIR/origin.key   (Private Key)"
    chmod 600 "$SSL_DIR/origin.key"

    local site=/etc/nginx/sites-available/moodle
    if ! grep -q "listen 443" "$site"; then
        # Baris IPv6 hanya bila server mendukungnya (sebagian VPS tanpa IPv6).
        local l6=""; [ -e /proc/net/if_inet6 ] && l6="\\n    listen [::]:443 ssl default_server;"
        sed -i "0,/listen 80 default_server;/s||listen 80 default_server;\\n    listen 443 ssl default_server;$l6\\n    ssl_certificate $SSL_DIR/origin.pem;\\n    ssl_certificate_key $SSL_DIR/origin.key;|" "$site"
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
    local url="$1" pat
    pat='^([$]CFG->wwwroot[[:space:]]*=).*'
    sed -i -E "s#$pat#\\1 '$url';#" "$CONFIG"
    php -l "$CONFIG" >/dev/null || die "config.php rusak setelah alamat diganti."
    sed -i -E "s#^app\.baseURL[[:space:]]*=.*#app.baseURL = '$url/cbtadmin/'#; s#^moodle\.url[[:space:]]*=.*#moodle.url = '$url'#" "$CBT_DIR/.env"
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
EX=(--exclude cache/ --exclude localcache/ --exclude sessions/ --exclude temp/ --exclude trashdir/ --exclude lock/)
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
            --exclude cache/ --exclude localcache/ --exclude sessions/ --exclude temp/ --exclude trashdir/ --exclude lock/ \\
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
    if [ -n "$hs" ] && [ "$hs" -gt 0 ]; then
        echo "Terhubung ke utama, kontak terakhir $(( $(date +%s) - hs )) detik lalu"
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
    if ping -c1 -W2 "$PEER_WG_IP" >/dev/null 2>&1; then
        warn "Server utama MASIH terhubung. Matikan server utama (atau hapus DNS $DOMAIN_UTAMA di Cloudflare)
  supaya siswa tidak lagi mengerjakan di sana; kalau dua server sama-sama menerima jawaban, datanya bercabang."
    fi
    confirm "PINDAH"

    log "1/4 Salin file terakhir dari server utama (bila masih bisa)"
    timeout 120 /usr/local/sbin/cbt-sync || warn "Server utama tidak terjangkau; memakai salinan file terakhir."

    log "2/4 Terapkan sisa data replikasi"
    mariadb -e "STOP SLAVE IO_THREAD;" 2>/dev/null || true
    local i state
    for i in $(seq 1 60); do
        state="$(slave_field Slave_SQL_Running_State)"
        case "$state" in *"read all relay log"*|"") break ;; esac
        sleep 1
    done
    echo "Posisi data: $(mariadb -N -e 'SELECT @@gtid_slave_pos')"

    log "3/4 Database dibuka untuk menulis"
    mariadb -e "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only = 0;"
    sed -i 's/^read_only = 1/read_only = 0/' "$DB_CNF"

    log "4/4 Moodle dan CBT Admin dibuka untuk siswa di server ini"
    echo "utama" > "$ROLE_FILE"
    local f
    for f in moodle cbtadmin; do
        if [ -f "/etc/cron.d/$f.cadangan-off" ]; then mv "/etc/cron.d/$f.cadangan-off" "/etc/cron.d/$f"; fi
    done
    runuser -u www-data -- php "$MOODLE_DIR/admin/cli/purge_caches.php"
    systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
    set_site_mode aktif

    echo
    echo "============================================================"
    echo "  SERVER INI SEKARANG UTAMA"
    echo "  Umumkan ke siswa alamat baru:  $URL_CADANGAN"
    echo "  Siswa login lagi lalu melanjutkan quiz; jawaban tersimpan."
    echo
    echo "  JANGAN hidupkan server lama sebagai utama lagi. Untuk menjadikannya"
    echo "  cadangan baru: di ha.conf KEDUA server tukar UTAMA_IP <-> CADANGAN_IP"
    echo "  dan DOMAIN_UTAMA <-> DOMAIN_CADANGAN, lalu jalankan"
    echo "  ha-utama.sh pasang  di server ini dan  ha-cadangan.sh pasang  di server lama."
    echo "============================================================"
}

case "${1:-}" in
    pasang) pasang ;;
    status) status ;;
    pindah) pindah ;;
    *) echo "Pakai: bash $0 pasang | status | pindah   (tambahkan YES=1 di depan untuk tanpa konfirmasi)"; exit 1 ;;
esac
