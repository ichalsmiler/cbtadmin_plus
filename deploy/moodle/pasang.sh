#!/usr/bin/env bash
# =============================================================================
#  Pasang setelan Moodle CBT di server ini (dijalankan oleh deploy/setelan-moodle.sh).
#    - plugin Moodle dari folder moodle/local (mis. local_cbtblokir)
#    - setelan, izin, tampilan, CSS tema, bahasa (deploy/moodle/setelan.php)
#  Aman dijalankan berulang kali. Di server cadangan HA hanya kode plugin yang
#  dipasang; setelan ikut tersalin dari server utama lewat replikasi database.
#
#  Pakai (sebagai root):  bash pasang.sh [folder-sumber]
# =============================================================================
set -euo pipefail

SRC="${1:-$(cd "$(dirname "$0")/../.." && pwd)}"
MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
CONFIG="$MOODLE_DIR/config.php"

log() { echo -e "\n\033[1;32m==> $*\033[0m"; }
die() { echo -e "\033[1;31m[GAGAL] $*\033[0m" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Jalankan sebagai root."
[ -f "$CONFIG" ] || die "Moodle tidak ditemukan di $MOODLE_DIR (jalankan install-moodle.sh dulu)."
[ -f "$SRC/deploy/moodle/setelan.php" ] || die "Berkas setelan tidak ditemukan di $SRC."

# Moodle 5.x menyimpan kode web di public/; versi lama langsung di folder Moodle.
WEB_DIR="$MOODLE_DIR/public"; [ -d "$WEB_DIR/local" ] || WEB_DIR="$MOODLE_DIR"

log "Plugin Moodle"
for plugin in "$SRC"/moodle/local/*/; do
    [ -f "$plugin/version.php" ] || continue
    name="$(basename "$plugin")"
    rm -rf "$WEB_DIR/local/$name"
    cp -r "$plugin" "$WEB_DIR/local/$name"
    find "$WEB_DIR/local/$name" -type f -exec sed -i 's/\r$//' {} +
    chown -R root:root "$WEB_DIR/local/$name"
    chmod -R a+rX "$WEB_DIR/local/$name"
    echo "  - local_$name disalin"
done

log "Layar login konsol: alamat IP server"
# /etc/issue tampil di konsol (Proxmox/VPS) sebelum login; \4 diganti agetty dengan IPv4 server saat itu,
# jadi alamat akses tidak perlu dicek manual (ip a) setelah IP berubah.
if ! grep -q "CBT Admin Plus" /etc/issue 2>/dev/null; then
    cat > /etc/issue <<'ISSUE'
Debian GNU/Linux \r \n \l

  CBT Admin Plus
  Alamat IP untuk akses VM => \4
  Moodle (ujian)  : http://\4
  CBT Admin       : http://\4/cbtadmin/

ISSUE
    echo "  - /etc/issue: alamat IP tampil di layar login"
fi

log "Berkas Moodle (gambar soal dll.) dikirim langsung oleh Nginx (X-Accel-Redirect)"
# PHP hanya memeriksa izin lalu menyerahkan pengiriman berkas ke Nginx, jadi worker PHP cepat bebas saat ratusan
# siswa membuka soal bergambar bersamaan.
DATAROOT="$(sed -n "s/^\$CFG->dataroot *= *'\(.*\)';.*/\1/p" "$CONFIG" | head -1)"
SITE=/etc/nginx/sites-available/moodle
if [ -n "$DATAROOT" ] && [ -f "$SITE" ]; then
    if ! grep -q "location /dataroot/" "$SITE"; then
        cp "$SITE" "$SITE.bak-cbt"
        sed -i "0,/^    location \/ {/s##    location /dataroot/ {\n        internal;\n        alias $DATAROOT/;\n    }\n\n    location / {#" "$SITE"
        if nginx -t >/dev/null 2>&1; then
            systemctl reload nginx
            echo "  - Nginx: location /dataroot/ (internal) ditambahkan"
        else
            mv "$SITE.bak-cbt" "$SITE"
            echo "  [PERINGATAN] konfigurasi Nginx gagal diuji; dikembalikan, X-Accel-Redirect tidak dipasang"
        fi
        rm -f "$SITE.bak-cbt"
    fi
    if grep -q "location /dataroot/" "$SITE" && ! grep -q "xsendfile" "$CONFIG"; then
        cp "$CONFIG" "$CONFIG.bak-cbt"
        sed -i "s#^require_once(__DIR__ . '/lib/setup.php');#// --- Ditambahkan oleh pasang.sh: berkas dikirim Nginx ---\n\$CFG->xsendfile = 'X-Accel-Redirect';\n\$CFG->xsendfilealiases = ['/dataroot/' => \$CFG->dataroot];\n\nrequire_once(__DIR__ . '/lib/setup.php');#" "$CONFIG"
        if php -l "$CONFIG" >/dev/null 2>&1 && grep -q "xsendfile" "$CONFIG"; then
            echo "  - config.php: \$CFG->xsendfile = X-Accel-Redirect"
        else
            mv "$CONFIG.bak-cbt" "$CONFIG"
            echo "  [PERINGATAN] config.php tidak bisa diubah; dikembalikan"
        fi
        rm -f "$CONFIG.bak-cbt"
    fi
fi

if [ "$(cat /etc/cbt-ha-peran 2>/dev/null)" = cadangan ]; then
    log "Server cadangan HA: setelan ikut tersalin dari server utama, tidak dipasang di sini"
    exit 0
fi

PHP=(env --chdir=/tmp runuser -u www-data -- php)   # dari /tmp: www-data tidak boleh masuk /root
log "Pasang/perbarui plugin di database Moodle"
"${PHP[@]}" "$MOODLE_DIR/admin/cli/upgrade.php" --non-interactive | tail -2

log "Setelan Moodle"
# Salin ke folder sementara yang bisa dibaca www-data (folder home tidak selalu bisa).
TMP="$(mktemp -d /tmp/cbt-setelan.XXXXXX)"
mkdir -p "$TMP/lang"
for f in setelan.php boost.scss; do sed 's/\r$//' "$SRC/deploy/moodle/$f" > "$TMP/$f"; done
for f in "$SRC"/deploy/moodle/lang/*.php; do sed 's/\r$//' "$f" > "$TMP/lang/$(basename "$f")"; done
chown -R www-data:www-data "$TMP"
"${PHP[@]}" "$TMP/setelan.php" "$CONFIG"
rm -rf "$TMP"

echo
echo "============================================================"
echo "  SETELAN MOODLE CBT TERPASANG"
echo "  Buka Moodle dan tekan Ctrl+F5 supaya CSS baru dimuat."
echo "============================================================"
