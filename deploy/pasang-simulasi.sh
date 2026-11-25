#!/usr/bin/env bash
# Server perantara (Dashboard Simulasi + Panel Pengawas): nginx kecil yang HANYA meneruskan dashboard simulasi publik
# dan halaman pengawas dari server utama. Tanpa salinan database; data selalu dari server utama.
# Semua /cbtadmin/ diteruskan dengan header X-CBT-Perantara: 1; panel (Guard::bolehPerantara) hanya melayani dashboard
# simulasi, login, dan menu pengawas (PROKTOR_PATHS) untuk permintaan itu, selain itu 404. Daftar menu cukup di Guard.
# Moodle dan alamat lain di luar /cbtadmin/ ditolak 404 di server perantara.
# Pakai (Git Bash, dari folder cbtadmin):  bash deploy/pasang-simulasi.sh <host-ssh-perantara> <host-ssh-utama>
# Contoh: bash deploy/pasang-simulasi.sh cbtsim cbt100
set -euo pipefail

HOST="${1:?host ssh server perantara}"
UTAMA_SSH="${2:?host ssh server utama}"

UTAMA="$(ssh -o BatchMode=yes "$UTAMA_SSH" "hostname -I | awk '{print \$1}'")"
PERANTARA="$(ssh -o BatchMode=yes "$HOST" "hostname -I | awk '{print \$1}'")"
[[ "$UTAMA" =~ ^[0-9.]+$ && "$PERANTARA" =~ ^[0-9.]+$ ]] || { echo "IP server tidak terbaca" >&2; exit 1; }

# 1. Server utama: percayai IP asli pengunjung yang dikirim perantara (header CF-Connecting-IP, sama dengan
#    Cloudflare), supaya kunci salah-password, log, dan batas perangkat pengawas memakai IP perangkat asli.
ssh -o BatchMode=yes "$UTAMA_SSH" "PERANTARA='$PERANTARA' bash -s" <<'SKRIP'
set -euo pipefail
f=/etc/nginx/conf.d/cbt-perantara-realip.conf
{
    echo "# IP asli pengunjung di belakang server perantara simulasi/pengawas (deploy/pasang-simulasi.sh)"
    echo "set_real_ip_from ${PERANTARA};"
    # real_ip_header hanya boleh satu; cloudflare-realip.conf (HA) sudah memakai CF-Connecting-IP.
    grep -qs "real_ip_header" /etc/nginx/conf.d/cloudflare-realip.conf || echo "real_ip_header CF-Connecting-IP;"
} > "$f.baru"
mv "$f.baru" "$f"
nginx -t -q && systemctl reload nginx
echo "Server utama: mempercayai IP asli dari perantara ${PERANTARA}"
SKRIP

# 2. Server perantara.
ssh -o BatchMode=yes "$HOST" "UTAMA='$UTAMA' bash -s" <<'SKRIP'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
command -v nginx >/dev/null || { apt-get update -qq; apt-get install -y -qq nginx >/dev/null; }
timedatectl set-timezone Asia/Makassar 2>/dev/null || true
mkdir -p /var/cache/nginx/simulasi

cat > /etc/nginx/snippets/cbt-perantara.conf <<EOF
proxy_pass http://${UTAMA};
proxy_set_header Host \$http_host;
proxy_set_header CF-Connecting-IP \$remote_addr;
proxy_set_header X-Forwarded-For \$remote_addr;
# X-Forwarded-Proto TIDAK dikirim: server utama (cbt-https.conf) mengalihkan "http" ke https (aturan Cloudflare).
proxy_set_header X-Forwarded-Proto "";
proxy_set_header X-CBT-Perantara 1;
proxy_connect_timeout 5s;
proxy_read_timeout 60s;
EOF

cat > /etc/nginx/sites-available/simulasi <<EOF
# Dibuat deploy/pasang-simulasi.sh (cbtadmin_plus). Server utama: ${UTAMA}
proxy_cache_path /var/cache/nginx/simulasi levels=1 keys_zone=simulasi:10m max_size=100m inactive=1d;

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    server_tokens off;
    client_max_body_size 1m;

    location = / { return 302 /cbtadmin/simulasi; }
    location = /simulasi { return 302 /cbtadmin/simulasi\$is_args\$args; }
    location = /pengawas { return 302 /cbtadmin/; }

    # Dashboard simulasi publik (hanya GET, tanpa cookie). Cache 15 detik.
    location = /cbtadmin/simulasi {
        limit_except GET { deny all; }
        include snippets/cbt-perantara.conf;
        proxy_set_header Cookie "";
        proxy_hide_header Set-Cookie;
        proxy_ignore_headers Set-Cookie Cache-Control Expires;
        proxy_cache simulasi;
        proxy_cache_key \$request_uri;
        proxy_cache_valid 200 404 15s;
        proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
        proxy_cache_lock on;
        add_header X-Cache \$upstream_cache_status;
    }

    # Aset tampilan (berkas statis, bukan rahasia).
    location ~ ^/cbtadmin/(assets/|tools/gambar/favicon\$) {
        limit_except GET { deny all; }
        include snippets/cbt-perantara.conf;
        proxy_set_header Cookie "";
        proxy_hide_header Set-Cookie;
        proxy_ignore_headers Set-Cookie;
        proxy_cache simulasi;
        proxy_cache_key \$request_uri;
        proxy_cache_valid 200 10m;
    }

    # Panel: diteruskan apa adanya (sesi/POST, tanpa cache); panel sendiri yang menolak menu non-pengawas (404).
    location /cbtadmin/ {
        include snippets/cbt-perantara.conf;
    }

    location / { return 404; }
}
EOF
ln -sf /etc/nginx/sites-available/simulasi /etc/nginx/sites-enabled/simulasi
rm -f /etc/nginx/sites-enabled/default
rm -rf /var/cache/nginx/simulasi/*
nginx -t -q
systemctl enable -q nginx
systemctl reload nginx 2>/dev/null || systemctl restart nginx
ip=$(hostname -I | awk '{print $1}')
echo "Perantara siap -> ${UTAMA}:  simulasi http://${ip}/   pengawas http://${ip}/pengawas"
SKRIP
