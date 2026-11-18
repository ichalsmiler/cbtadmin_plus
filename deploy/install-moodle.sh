#!/usr/bin/env bash
# =============================================================================
#  Instalasi Moodle sekali jalan untuk Debian 12 / 13
#  Nginx + PHP-FPM + MariaDB + Redis, disetel untuk ujian ~500 peserta
#
#  Cara pakai (sebagai root):   bash install-moodle.sh
# =============================================================================
set -euo pipefail

# ----------------------------- PENGATURAN ------------------------------------
WWWROOT="${WWWROOT:-http://$(hostname -I | awk '{print $1}')}"   # alamat yang dibuka peserta (bawaan: IP server ini)
MOODLE_BRANCH="${MOODLE_BRANCH:-501}"        # 501 = Moodle 5.1, 405 = 4.5 LTS
SITE_FULLNAME="${SITE_FULLNAME:-Ujian Online}"
SITE_SHORTNAME="${SITE_SHORTNAME:-Ujian}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ZONA_WAKTU="${ZONA_WAKTU:-Asia/Makassar}"     # WITA; Asia/Jakarta = WIB, Asia/Jayapura = WIT

MOODLE_DIR="/var/www/moodle"
DATA_DIR="/var/moodledata"
DB_NAME="moodle"
DB_USER="moodle"
CRED_FILE="/root/moodle-credentials.txt"
# -----------------------------------------------------------------------------

log()  { echo -e "\n\033[1;32m==> $*\033[0m"; }
warn() { echo -e "\033[1;33m[PERINGATAN] $*\033[0m"; }
die()  { echo -e "\033[1;31m[GAGAL] $*\033[0m" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root (su - lalu bash install-moodle.sh)."
[ -f /etc/debian_version ] || die "Skrip ini hanya untuk Debian."
[ -f "$MOODLE_DIR/config.php" ] && die "Moodle sudah terpasang di $MOODLE_DIR. Skrip dihentikan agar tidak menimpa."

export DEBIAN_FRONTEND=noninteractive
APT_OPTS=(-y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold)

# ----------------------------- 1. UPDATE & UPGRADE ---------------------------
log "1/9 Update dan upgrade sistem"
apt-get update
apt-get "${APT_OPTS[@]}" full-upgrade

# Zona waktu dan jam server: waktu quiz dan jadwal backup bergantung pada jam yang benar.
[ -f "/usr/share/zoneinfo/$ZONA_WAKTU" ] || die "Zona waktu $ZONA_WAKTU tidak dikenal."
ln -sf "/usr/share/zoneinfo/$ZONA_WAKTU" /etc/localtime
echo "$ZONA_WAKTU" > /etc/timezone
if systemd-detect-virt --container --quiet 2>/dev/null; then
    log "Container: jam mengikuti host (pastikan jam host tersinkron)"
else
    apt-get "${APT_OPTS[@]}" install systemd-timesyncd
    systemctl enable --now systemd-timesyncd >/dev/null 2>&1 || true
    timedatectl set-ntp true 2>/dev/null || true
fi
systemctl restart cron 2>/dev/null || true
log "Zona waktu: $ZONA_WAKTU, jam server: $(date '+%d-%m-%Y %H:%M:%S %Z')"

# ----------------------------- 2. PAKET --------------------------------------
log "2/9 Memasang Nginx, PHP, MariaDB, Redis"
if systemctl is-active --quiet apache2 2>/dev/null; then
    warn "Apache sedang berjalan di port 80, dimatikan karena skrip ini memakai Nginx."
    systemctl disable --now apache2
fi

apt-get "${APT_OPTS[@]}" install \
    nginx mariadb-server redis-server \
    php-fpm php-cli php-mysql php-xml php-mbstring php-curl php-zip php-gd \
    php-intl php-soap php-redis \
    curl unzip git cron openssl ca-certificates ghostscript

PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);' \
    || die "PHP $PHPV terlalu lama. Moodle 5.x butuh PHP 8.2+, pakai Debian 12 atau 13."
log "PHP $PHPV terdeteksi"

# ----------------------------- 3. HITUNG KAPASITAS ---------------------------
RAM_MB="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)"
CPU="$(nproc)"
FPM_CHILDREN=$(( RAM_MB / 2 / 70 ))          # ~50% RAM untuk PHP, ~70 MB/proses
[ "$FPM_CHILDREN" -lt 10 ]  && FPM_CHILDREN=10
[ "$FPM_CHILDREN" -gt 400 ] && FPM_CHILDREN=400
FPM_START=$(( FPM_CHILDREN / 4 ));  [ "$FPM_START" -lt 4 ] && FPM_START=4
FPM_SPARE_MAX=$(( FPM_CHILDREN / 2 )); [ "$FPM_SPARE_MAX" -le "$FPM_START" ] && FPM_SPARE_MAX=$(( FPM_START + 2 ))
[ "$FPM_SPARE_MAX" -gt "$FPM_CHILDREN" ] && FPM_SPARE_MAX="$FPM_CHILDREN"
DB_POOL_MB=$(( RAM_MB / 4 ));       [ "$DB_POOL_MB" -lt 256 ] && DB_POOL_MB=256
DB_MAXCONN=$(( FPM_CHILDREN + 50 ))

log "3/9 Server: ${CPU} core, ${RAM_MB} MB RAM -> PHP-FPM ${FPM_CHILDREN} proses, InnoDB ${DB_POOL_MB} MB"
if [ "$RAM_MB" -lt 7500 ] || [ "$CPU" -lt 4 ]; then
    warn "Untuk 500 peserta ujian serentak disarankan minimal 8 core / 16 GB RAM."
    warn "Server ini di bawah itu; instalasi tetap dilanjutkan."
fi

# ----------------------------- 4. PHP ----------------------------------------
log "4/9 Menyetel PHP"
for sapi in fpm cli; do
cat > "/etc/php/$PHPV/$sapi/conf.d/99-moodle.ini" <<EOF
max_input_vars = 5000
date.timezone = $ZONA_WAKTU
memory_limit = 256M
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 60
EOF
done

POOL="/etc/php/$PHPV/fpm/pool.d/www.conf"
sed -i \
    -e "s/^pm = .*/pm = dynamic/" \
    -e "s/^pm.max_children = .*/pm.max_children = $FPM_CHILDREN/" \
    -e "s/^pm.start_servers = .*/pm.start_servers = $FPM_START/" \
    -e "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $FPM_START/" \
    -e "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $FPM_SPARE_MAX/" \
    -e "s/^;\?pm.max_requests = .*/pm.max_requests = 1000/" \
    "$POOL"
systemctl restart "php$PHPV-fpm"

# ----------------------------- 5. MARIADB ------------------------------------
log "5/9 Menyetel MariaDB dan membuat database"
cat > /etc/mysql/mariadb.conf.d/99-moodle.cnf <<EOF
[mysqld]
innodb_buffer_pool_size = ${DB_POOL_MB}M
innodb_log_file_size = 256M
innodb_flush_method = O_DIRECT
max_connections = $DB_MAXCONN
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
EOF
systemctl enable mariadb >/dev/null 2>&1 || true
systemctl restart mariadb

DB_PASS="$(openssl rand -hex 16)"
ADMIN_PASS="Mdl-$(openssl rand -hex 5)-Uj9"
mariadb <<EOF
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
EOF

# ----------------------------- 6. UNDUH MOODLE -------------------------------
log "6/9 Mengunduh Moodle (cabang $MOODLE_BRANCH)"
TMP_TGZ="$(mktemp --suffix=.tgz)"
curl -fL --retry 3 -o "$TMP_TGZ" \
    "https://download.moodle.org/download.php/direct/stable${MOODLE_BRANCH}/moodle-latest-${MOODLE_BRANCH}.tgz"
mkdir -p /var/www
[ -d "$MOODLE_DIR" ] && mv "$MOODLE_DIR" "${MOODLE_DIR}.lama.$(date +%s)"
tar -xzf "$TMP_TGZ" -C /var/www
rm -f "$TMP_TGZ"
[ -d "$MOODLE_DIR" ] || die "Ekstraksi gagal: $MOODLE_DIR tidak ada."

# Moodle 5.1+ menaruh berkas web di subfolder public/
if [ -d "$MOODLE_DIR/public" ]; then DOCROOT="$MOODLE_DIR/public"; else DOCROOT="$MOODLE_DIR"; fi
if [ -f "$DOCROOT/r.php" ]; then TRY_FILES='try_files $uri $uri/ /r.php;'; else TRY_FILES='try_files $uri $uri/ =404;'; fi

# Paket resmi sudah berisi vendor/. Jaga-jaga bila tidak ada.
if [ -f "$MOODLE_DIR/composer.json" ] && [ ! -d "$MOODLE_DIR/vendor" ]; then
    log "Folder vendor/ tidak ada, memasang dependensi dengan Composer"
    apt-get "${APT_OPTS[@]}" install composer
    (cd "$MOODLE_DIR" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative --no-interaction)
fi

mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$DATA_DIR" "$MOODLE_DIR"
chmod 770 "$DATA_DIR"

# ----------------------------- 7. NGINX --------------------------------------
log "7/9 Menyetel Nginx (document root: $DOCROOT)"
sed -i 's/worker_connections [0-9]*;/worker_connections 4096;/' /etc/nginx/nginx.conf
cat > /etc/nginx/sites-available/moodle <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root $DOCROOT;
    index index.php index.html;
    client_max_body_size 100M;

    location / {
        $TRY_FILES
    }

    location ~ [^/]\.php(/|\$) {
        fastcgi_split_path_info ^(.+\.php)(/.+)\$;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php$PHPV-fpm.sock;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known) { deny all; }
}
EOF
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/moodle /etc/nginx/sites-enabled/moodle
nginx -t
systemctl enable nginx redis-server >/dev/null 2>&1 || true
systemctl restart nginx redis-server

# ----------------------------- 8. INSTAL MOODLE ------------------------------
log "8/9 Menjalankan installer Moodle (beberapa menit)"
if   [ -f "$MOODLE_DIR/admin/cli/install.php" ]; then CLI_DIR="$MOODLE_DIR/admin/cli"
elif [ -f "$DOCROOT/admin/cli/install.php" ];    then CLI_DIR="$DOCROOT/admin/cli"
else die "admin/cli/install.php tidak ditemukan."; fi

sudo_www() { runuser -u www-data -- "$@"; }
sudo_www php "$CLI_DIR/install.php" \
    --non-interactive --agree-license \
    --lang=en \
    --wwwroot="$WWWROOT" \
    --dataroot="$DATA_DIR" \
    --dbtype=mariadb --dbhost=localhost \
    --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
    --fullname="$SITE_FULLNAME" --shortname="$SITE_SHORTNAME" \
    --adminuser="$ADMIN_USER" --adminpass="$ADMIN_PASS" --adminemail="$ADMIN_EMAIL"

CONFIG="$MOODLE_DIR/config.php"
[ -f "$CONFIG" ] || die "config.php tidak terbentuk, instalasi Moodle gagal."

# Sesi login disimpan di Redis (jauh lebih ringan saat 500 peserta masuk bersamaan)
EXTRA="$(mktemp)"
cat > "$EXTRA" <<'EOF'
// --- Ditambahkan oleh install-moodle.sh: sesi di Redis ---
$CFG->session_handler_class = '\core\session\redis';
$CFG->session_redis_host = '127.0.0.1';
$CFG->session_redis_port = 6379;
$CFG->session_redis_acquire_lock_timeout = 120;
$CFG->session_redis_lock_expire = 7200;

EOF
sed -i "/lib\/setup\.php/e cat $EXTRA" "$CONFIG"
rm -f "$EXTRA"
php -l "$CONFIG" >/dev/null || die "config.php rusak setelah diubah."

# Kode Moodle dibuat hanya-baca untuk web server
chown -R root:root "$MOODLE_DIR"
chown root:www-data "$CONFIG"
chmod 640 "$CONFIG"

# ----------------------------- 9. CRON ---------------------------------------
log "9/9 Memasang cron Moodle (tiap menit)"
cat > /etc/cron.d/moodle <<EOF
* * * * * www-data /usr/bin/php $CLI_DIR/cron.php >/dev/null 2>&1
EOF
chmod 644 /etc/cron.d/moodle
systemctl enable --now cron >/dev/null 2>&1 || true
systemctl restart "php$PHPV-fpm"

umask 077
cat > "$CRED_FILE" <<EOF
Moodle       : $WWWROOT
Admin user   : $ADMIN_USER
Admin pass   : $ADMIN_PASS
Database     : $DB_NAME
DB user      : $DB_USER
DB pass      : $DB_PASS
Folder kode  : $MOODLE_DIR
Folder data  : $DATA_DIR
EOF

HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/login/index.php || true)"

echo
echo "============================================================"
echo "  MOODLE SIAP DIPAKAI"
echo "  Alamat      : $WWWROOT"
echo "  Admin user  : $ADMIN_USER"
echo "  Admin pass  : $ADMIN_PASS"
echo "  Cek halaman login: HTTP $HTTP_CODE (200 = normal)"
echo "  Data login tersimpan di $CRED_FILE"
echo
echo "  Berikutnya, dari komputer admin (Git Bash, folder cbtadmin):"
echo "    bash deploy/setelan-moodle.sh <host-ssh>   # setelan ujian CBT"
echo "    bash deploy/deploy.sh <host-ssh>           # CBT Admin"
echo "============================================================"
