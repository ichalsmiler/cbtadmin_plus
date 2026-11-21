#!/usr/bin/env bash
# =============================================================================
#  CBT Admin Plus — pasang / perbarui LANGSUNG DI SERVER (Debian 12/13), tanpa laptop.
#
#  Pasang pertama (konsol server, sebagai root):
#    apt-get update && apt-get install -y curl && curl -fsSL https://raw.githubusercontent.com/ichalsmiler/cbtadmin_plus/main/pasang-cbt.sh | bash
#
#  Perbarui kemudian hari:   cbt-perbarui
#  Daftarkan domain Cloudflare: cbt-alamat ujian.sekolah.sch.id (alamat IP tetap jalan)
#
#  Yang dikerjakan: ambil kode terbaru dari GitHub ke /opt/cbtadmin_plus, pasang Moodle (bila belum ada),
#  setelan ujian Moodle + plugin CBT, lalu panel CBT Admin. Aman dijalankan berulang.
#  Pilihan tanpa tanya:  DOMAIN_CBT=ujian.sekolah.sch.id ZONA_WAKTU=WIB (sebelum "bash").
# =============================================================================
set -euo pipefail

REPO="https://github.com/ichalsmiler/cbtadmin_plus.git"
SRC="/opt/cbtadmin_plus"

hijau() { echo -e "\n\033[1;32m==> $*\033[0m"; }
gagal() { echo -e "\033[1;31m[GAGAL] $*\033[0m" >&2; exit 1; }
tanya() { # tanya "Pertanyaan" "bawaan" -> jawaban (dari keyboard, juga saat skrip dijalankan lewat curl | bash)
    local jawab=""
    if [ -r /dev/tty ]; then read -r -p "$1 [$2]: " jawab < /dev/tty || true; fi
    echo "${jawab:-$2}"
}

[ "$(id -u)" -eq 0 ] || gagal "Jalankan sebagai root (login root di konsol, atau ketik: sudo -i)."
grep -qE '^ID=debian' /etc/os-release 2>/dev/null || gagal "Hanya untuk Debian 12/13."
export DEBIAN_FRONTEND=noninteractive

hijau "1/4 Mengambil CBT Admin Plus terbaru dari GitHub"
apt-get update -qq
apt-get install -y -qq git curl ca-certificates sudo >/dev/null
if [ -d "$SRC/.git" ]; then
    git -C "$SRC" fetch -q --depth 1 origin main
    git -C "$SRC" reset -q --hard origin/main
else
    rm -rf "$SRC"
    git clone -q --depth 1 "$REPO" "$SRC"
fi
echo "  - versi: $(git -C "$SRC" log -1 --format='%h %ad %s' --date=format:'%d-%m-%Y')"

IP="$(hostname -I | awk '{print $1}')"
if [ ! -f /var/www/moodle/config.php ]; then
    hijau "2/4 Memasang Moodle (pertama kali, ±15 menit)"
    # VPS (mis. IDCloudHost) hanya "melihat" IP privatnya; IP publik ditanyakan ke luar supaya bisa ditunjukkan.
    PUBLIK="$(curl -4 -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)"
    if [ -z "${WWWROOT:-}" ] && [ -z "${DOMAIN_CBT:-}" ]; then
        echo
        echo "  Alamat Moodle = IP server ini (siswa di sekolah membuka http://IP)."
        echo "  Domain Cloudflare (Tunnel / Proxied) cukup didaftarkan: Moodle mengikuti alamat yang dibuka,"
        echo "  https://domain dari luar dan http://IP di sekolah. Kosongkan bila belum ada (nanti: cbt-alamat <domain>)."
        echo "    IP server ini : $IP (lokal)"
        if [ -n "$PUBLIK" ] && [ "$PUBLIK" != "$IP" ]; then
            echo "    IP publik     : $PUBLIK"
        fi
        DOMAIN_CBT="$(tanya "Domain Cloudflare (kosongkan bila belum ada)" "")"
    fi
    WWWROOT="${WWWROOT:-http://$IP}"
    ZONA_WAKTU="${ZONA_WAKTU:-$(tanya "Zona waktu: WITA, WIB, atau WIT" "WITA")}"
    WWWROOT="$WWWROOT" ZONA_WAKTU="$ZONA_WAKTU" bash "$SRC/deploy/install-moodle.sh"
else
    hijau "2/4 Moodle sudah terpasang, dilewati"
fi

hijau "3/4 Setelan ujian Moodle dan plugin CBT"
bash "$SRC/deploy/moodle/pasang.sh" "$SRC"

hijau "4/4 Panel CBT Admin"
bash "$SRC/deploy/install.sh" "$SRC"

# Perintah singkat untuk pembaruan berikutnya dan untuk menyambungkan server cadangan.
install -m 755 "$SRC/pasang-cbt.sh" /usr/local/sbin/cbt-perbarui
install -m 755 "$SRC/deploy/ha/cbt-ha" /usr/local/sbin/cbt-ha
install -m 755 "$SRC/deploy/cbt-alamat" /usr/local/sbin/cbt-alamat
# Alamat Moodle selalu IP; domain hanya didaftarkan (Moodle mengikuti alamat yang dibuka). Alamat lama berupa
# domain (versi sebelumnya) dikembalikan ke IP dan domainnya didaftarkan.
LAMA="$(sed -n "s/^\$CFG->wwwroot *= *'https*:\/\/\([^/']*\).*/\1/p" /var/www/moodle/config.php | head -1)"
[[ "$LAMA" =~ ^[0-9.:]*$ ]] && LAMA=""
if [ -n "${DOMAIN_CBT:-}$LAMA" ]; then
    /usr/local/sbin/cbt-alamat ${DOMAIN_CBT:-} $LAMA >/dev/null || echo "[PERINGATAN] Domain gagal didaftarkan; jalankan: cbt-alamat <domain>"
else
    # Tanpa domain pun blok alamat dipasang: IP publik VPS (di balik NAT) atau IP LAN mana pun langsung bisa dibuka.
    /usr/local/sbin/cbt-alamat pasang >/dev/null || echo "[PERINGATAN] Blok alamat gagal dipasang; jalankan: cbt-alamat pasang"
fi

ALAMAT="$(sed -n "s/^\$CFG->wwwroot *= *'\(.*\)';.*/\1/p" /var/www/moodle/config.php | head -1)"
# Ringkasan akhir: dibaca dari berkas kredensial yang ditulis install-moodle.sh dan install.sh.
nilai() { [ -f "$1" ] && sed -n "s/^$2 *: *//p" "$1" | head -1; }
CBT=/root/cbtadmin-credentials.txt; MDL=/root/moodle-credentials.txt
ALAMAT="${ALAMAT:-http://$IP}"
GARIS="============================================================"
baris() { printf '    %-16s: %s\n' "$1" "$2"; }
echo
echo "$GARIS"
echo "  CBT ADMIN PLUS SIAP"
echo "$GARIS"
echo
echo "  ALAMAT"
baris "Ujian (siswa)" "$ALAMAT"
baris "CBT Admin"     "$ALAMAT/cbtadmin/"
echo
echo "  AKUN CBT ADMIN"
baris "Username" "$(nilai "$CBT" Username)"
baris "Password" "$(nilai "$CBT" Password)"
echo
echo "  AKUN MOODLE (admin situs)"
baris "Username" "$(nilai "$MDL" 'Admin user')"
baris "Password" "$(nilai "$MDL" 'Admin pass')"
echo
echo "  DATABASE MOODLE"
baris "Nama database" "$(nilai "$MDL" Database)"
baris "User"          "$(nilai "$MDL" 'DB user')"
baris "Password"      "$(nilai "$MDL" 'DB pass')"
echo
echo "  FOLDER"
baris "Kode Moodle" "$(nilai "$MDL" 'Folder kode')"
baris "Data Moodle" "$(nilai "$MDL" 'Folder data')"
baris "CBT Admin"   "/var/www/cbtadmin"
echo
echo "$GARIS"
echo "  PENTING"
echo "   - Catat data di atas. Salinannya tersimpan di:"
echo "       $CBT"
echo "       $MDL"
echo "   - Ganti password kedua admin setelah login pertama."
echo "   - Perbarui ke versi terbaru : cbt-perbarui"
echo "   - Daftarkan domain Cloudflare : cbt-alamat <domain>"
echo "   - Server cadangan           : CBT Admin -> Admin Tools -> Server Cadangan"
echo "$GARIS"
