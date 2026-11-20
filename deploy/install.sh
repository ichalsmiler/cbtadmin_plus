#!/usr/bin/env bash
# =============================================================================
#  Pasang / perbarui CBT Admin di server Moodle (Debian 12/13, Nginx + PHP-FPM)
#  Dijalankan sebagai root di server:  bash install.sh /path/ke/sumber
#  Aman dijalankan ulang: .env, database, dan akun admin yang sudah ada dibiarkan.
# =============================================================================
set -euo pipefail

SRC="${1:?Pakai: bash install.sh /path/ke/folder-sumber}"
APP_DIR="/var/www/cbtadmin"
MOODLE_CONFIG="/var/www/moodle/config.php"
NGINX_SITE="/etc/nginx/sites-available/moodle"
SNIPPET="/etc/nginx/snippets/cbtadmin.conf"
CRED_FILE="/root/cbtadmin-credentials.txt"
PANEL_DB="cbtadmin"
PANEL_DB_USER="cbtadmin"

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }
die() { echo -e "\033[1;31m[GAGAL] $*\033[0m" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root."
[ -d "$SRC/app" ] || die "Folder sumber tidak berisi app/."
[ -f "$MOODLE_CONFIG" ] || die "Moodle tidak ditemukan di /var/www/moodle."
[ -f "$NGINX_SITE" ] || die "Konfigurasi Nginx Moodle tidak ditemukan."

cfg() { sed -n "s/^\$CFG->$1 *= *'\(.*\)';.*/\1/p" "$MOODLE_CONFIG" | head -1; }
MOODLE_URL="$(cfg wwwroot)"
MOODLE_DB="$(cfg dbname)"
MOODLE_PREFIX="$(cfg prefix)"
[ -n "$MOODLE_URL" ] && [ -n "$MOODLE_DB" ] || die "Gagal membaca wwwroot/dbname dari config.php Moodle."
SERVER_HOST="$(echo "$MOODLE_URL" | sed -E 's#^https?://([^/:]+).*#\1#')"

# ----------------------------- 1. Paket ---------------------------------------
log "1/7 Memasang Composer dan ekstensi PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y composer php-intl php-mbstring php-mysql php-xml php-curl php-gd php-zip unzip curl >/dev/null

# ----------------------------- 2. CodeIgniter 4 -------------------------------
if [ ! -f "$APP_DIR/spark" ]; then
    log "2/7 Mengunduh CodeIgniter 4"
    [ -e "$APP_DIR" ] && die "$APP_DIR sudah ada tetapi bukan proyek CodeIgniter."
    COMPOSER_ALLOW_SUPERUSER=1 composer create-project codeigniter4/appstarter "$APP_DIR" --no-dev --no-interaction
else
    log "2/7 CodeIgniter 4 sudah ada, dilewati"
fi

# ----------------------------- 3. Kode panel ----------------------------------
log "3/7 Menyalin kode panel"
cp -r "$SRC/app/." "$APP_DIR/app/"
cp -r "$SRC/public/." "$APP_DIR/public/"
sed -i "s/public string \$indexPage = 'index.php';/public string \$indexPage = '';/" "$APP_DIR/app/Config/App.php"

VENDOR="$APP_DIR/public/assets/vendor"
if [ ! -f "$VENDOR/bootstrap-icons/fonts/bootstrap-icons.woff2" ]; then
    log "    Mengunduh Bootstrap, Bootstrap Icons, jQuery (disimpan lokal, tanpa CDN)"
    mkdir -p "$VENDOR/bootstrap-icons/fonts"
    get() { curl -fsSL --retry 3 -o "$2" "https://cdn.jsdelivr.net/npm/$1"; }
    get bootstrap@5.3.3/dist/css/bootstrap.min.css        "$VENDOR/bootstrap.min.css"
    get bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js   "$VENDOR/bootstrap.bundle.min.js"
    get jquery@3.7.1/dist/jquery.min.js                   "$VENDOR/jquery.min.js"
    get bootstrap-icons@1.11.3/font/bootstrap-icons.min.css        "$VENDOR/bootstrap-icons/bootstrap-icons.min.css"
    get bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff     "$VENDOR/bootstrap-icons/fonts/bootstrap-icons.woff"
    get bootstrap-icons@1.11.3/font/fonts/bootstrap-icons.woff2    "$VENDOR/bootstrap-icons/fonts/bootstrap-icons.woff2"
fi
if [ ! -f "$VENDOR/dataTables.bootstrap5.min.css" ]; then
    log "    Mengunduh DataTables"
    get() { curl -fsSL --retry 3 -o "$2" "https://cdn.jsdelivr.net/npm/$1"; }
    get datatables.net@2.1.8/js/dataTables.min.js                  "$VENDOR/dataTables.min.js"
    get datatables.net-bs5@2.1.8/js/dataTables.bootstrap5.min.js   "$VENDOR/dataTables.bootstrap5.min.js"
    get datatables.net-bs5@2.1.8/css/dataTables.bootstrap5.min.css "$VENDOR/dataTables.bootstrap5.min.css"
fi
if [ ! -d "$APP_DIR/vendor/phpoffice/phpspreadsheet" ]; then
    log "    Memasang PhpSpreadsheet (import/export Excel)"
    (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer require phpoffice/phpspreadsheet --update-no-dev --no-interaction)
fi
if [ ! -d "$APP_DIR/vendor/dompdf/dompdf" ]; then
    log "    Memasang dompdf (cetak PDF)"
    (cd "$APP_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer require dompdf/dompdf --update-no-dev --no-interaction)
fi
mkdir -p "$APP_DIR/writable/uploads"

# ----------------------------- 4. Database dan .env ---------------------------
if [ ! -f "$APP_DIR/.env" ]; then
    log "4/7 Membuat database panel dan berkas .env"
    DB_PASS="$(openssl rand -hex 16)"
    mariadb <<EOF
CREATE DATABASE IF NOT EXISTS \`$PANEL_DB\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$PANEL_DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$PANEL_DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$PANEL_DB\`.* TO '$PANEL_DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$MOODLE_DB\`.* TO '$PANEL_DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF
    cat > "$APP_DIR/.env" <<EOF
CI_ENVIRONMENT = production

app.baseURL = '${MOODLE_URL}/cbtadmin/'
app.indexPage = ''
app.appTimezone = '$(timedatectl show -p Timezone --value 2>/dev/null || echo Asia/Makassar)'

# Database panel
database.default.hostname = localhost
database.default.database = $PANEL_DB
database.default.username = $PANEL_DB_USER
database.default.password = $DB_PASS
database.default.DBDriver = MySQLi
database.default.charset = utf8mb4
database.default.DBCollat = utf8mb4_unicode_ci

# Database Moodle
moodle.hostname = localhost
moodle.port = 3306
moodle.database = $MOODLE_DB
moodle.username = $PANEL_DB_USER
moodle.password = $DB_PASS
moodle.prefix = ${MOODLE_PREFIX:-mdl_}
moodle.url = '${MOODLE_URL}'

# Identitas (bisa ditimpa dari menu Tools)
cbt.namaSekolah = ''
cbt.npsn = ''
cbt.webminUrl = 'https://${SERVER_HOST}:10000'
cbt.sessionTimeout = 3600
EOF
else
    log "4/7 .env sudah ada, dibiarkan"
fi

# ----------------------------- 5. Hak akses dan migrasi -----------------------
log "5/7 Mengatur hak akses dan menjalankan migrasi"
chown -R root:www-data "$APP_DIR"
chmod 640 "$APP_DIR/.env"
chown -R www-data:www-data "$APP_DIR/writable"
mkdir -p "$APP_DIR/webroot"
ln -sfn ../public "$APP_DIR/webroot/cbtadmin"
CRON_FILE=/etc/cron.d/cbtadmin
if [ "$(cat /etc/cbt-ha-peran 2>/dev/null)" = cadangan ]; then
    # Server cadangan HA: database hanya-baca dan berisi salinan server utama (migrasi ikut tersalin);
    # cron tetap dimatikan sampai server ini dipindah menjadi utama.
    CRON_FILE=/etc/cron.d/cbtadmin.cadangan-off
    rm -f /etc/cron.d/cbtadmin
else
    (cd "$APP_DIR" && runuser -u www-data -- php spark migrate --all)
fi

# Release token otomatis (menu Status Test): cek tiap menit.
cat > "$CRON_FILE" <<EOF
# CBT Admin: release token otomatis dan pasang token di quiz tes aktif.
* * * * * www-data cd $APP_DIR && flock -n /tmp/cbtadmin-token.lock php spark cbt:token >/dev/null 2>&1
EOF
chmod 644 "$CRON_FILE"

# ----------------------------- 6. Akun admin awal -----------------------------
ADMIN_COUNT="$(mariadb -N "$PANEL_DB" -e 'SELECT COUNT(*) FROM admins')"
if [ "$ADMIN_COUNT" -eq 0 ]; then
    log "6/7 Membuat akun admin awal"
    ADMIN_PASS="Cbt-$(openssl rand -hex 5)"
    HASH="$(ADMIN_PASS="$ADMIN_PASS" php -r 'echo password_hash(getenv("ADMIN_PASS"), PASSWORD_DEFAULT);')"
    mariadb "$PANEL_DB" <<EOF
INSERT INTO admins (username, password_hash, nama, role, aktif, created_at)
VALUES ('admin', '$HASH', 'Administrator', 'admin', 1, NOW());
EOF
    umask 077
    cat > "$CRED_FILE" <<EOF
CBT Admin : ${MOODLE_URL}/cbtadmin/
Username  : admin
Password  : $ADMIN_PASS
EOF
else
    log "6/7 Akun admin sudah ada, dibiarkan"
fi

# ----------------------------- 7. Nginx ---------------------------------------
log "7/7 Menyetel Nginx"
cat > "$SNIPPET" <<'EOF'
# CBT Admin (CodeIgniter 4) di /cbtadmin/. Harus dimuat sebelum blok PHP Moodle.
location = /cbtadmin { return 301 /cbtadmin/; }
location ^~ /cbtadmin/ {
    root /var/www/cbtadmin/webroot;
    index index.php;
    try_files $uri $uri/ /cbtadmin/index.php$is_args$args;

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_read_timeout 300;
    }
}
EOF
if ! grep -q "snippets/cbtadmin.conf" "$NGINX_SITE"; then
    cp "$NGINX_SITE" "$NGINX_SITE.bak-cbtadmin"
    sed -i '0,/client_max_body_size/s//include snippets\/cbtadmin.conf;\n    client_max_body_size/' "$NGINX_SITE"
    grep -q "snippets/cbtadmin.conf" "$NGINX_SITE" || die "Gagal menambahkan include ke $NGINX_SITE."
fi
if ! nginx -t; then
    [ -f "$NGINX_SITE.bak-cbtadmin" ] && cp "$NGINX_SITE.bak-cbtadmin" "$NGINX_SITE"
    die "Konfigurasi Nginx tidak valid; perubahan dibatalkan."
fi
systemctl reload nginx

# Menu Admin Tools → Server Cadangan: skrip HA + jembatan root yang boleh dijalankan panel (www-data)
# lewat sudo, hanya dengan argumen status | pindah | log.
if [ -f "$SRC/deploy/ha/cbt-ha-panel" ]; then
    install -d -m 755 /usr/local/share/cbt-ha
    install -m 755 "$SRC/deploy/ha/ha-utama.sh" "$SRC/deploy/ha/ha-cadangan.sh" /usr/local/share/cbt-ha/
    install -m 644 "$SRC/deploy/ha/ha.conf.contoh" /usr/local/share/cbt-ha/
    install -m 755 "$SRC/deploy/ha/cbt-ha-panel" /usr/local/sbin/cbt-ha-panel
    [ -f "$SRC/deploy/ha/cbt-ha" ] && install -m 755 "$SRC/deploy/ha/cbt-ha" /usr/local/sbin/cbt-ha
    printf '%s\n' '# Panel CBT Admin: menu Server Cadangan (dibuat oleh install.sh)' \
        'www-data ALL=(root) NOPASSWD: /usr/local/sbin/cbt-ha-panel status, /usr/local/sbin/cbt-ha-panel pindah, /usr/local/sbin/cbt-ha-panel log' \
        > /etc/sudoers.d/cbtadmin-ha
    chmod 440 /etc/sudoers.d/cbtadmin-ha
    visudo -cf /etc/sudoers.d/cbtadmin-ha >/dev/null || { rm -f /etc/sudoers.d/cbtadmin-ha; echo "[PERINGATAN] sudoers Server Cadangan tidak valid, dilewati."; }
fi
# Kosongkan OPcache supaya kode baru langsung dipakai.
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
sleep 1

code() { curl -s -o /dev/null -w '%{http_code}' -H "Host: $SERVER_HOST" "http://127.0.0.1$1" || true; }
echo
echo "============================================================"
echo "  CBT ADMIN TERPASANG: ${MOODLE_URL}/cbtadmin/"
echo "  Halaman login panel : HTTP $(code /cbtadmin/login)  (200 = normal)"
echo "  Halaman login Moodle: HTTP $(code /login/index.php)  (200 = normal)"
[ -f "$CRED_FILE" ] && echo "  Akun admin awal ada di $CRED_FILE (baca dengan: sudo cat $CRED_FILE)"
echo "============================================================"
