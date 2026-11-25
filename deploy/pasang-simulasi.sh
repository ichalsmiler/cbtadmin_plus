#!/usr/bin/env bash
# Server Dashboard Simulasi: nginx kecil yang HANYA meneruskan dashboard simulasi publik dari server utama.
# Data selalu sama dengan server utama (tanpa salinan database); semua alamat lain ditolak (404).
# Pakai (Git Bash, dari folder cbtadmin):  bash deploy/pasang-simulasi.sh <host-ssh-server-simulasi> <ip-server-utama>
# Contoh: bash deploy/pasang-simulasi.sh cbtsim 172.16.16.100
set -euo pipefail

HOST="${1:?host ssh server simulasi}"
UTAMA="${2:?ip server utama}"
[[ "$UTAMA" =~ ^[0-9A-Za-z.-]+$ ]] || { echo "IP/nama server utama tidak valid" >&2; exit 1; }

ssh -o BatchMode=yes "$HOST" "UTAMA='$UTAMA' bash -s" <<'SKRIP'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
command -v nginx >/dev/null || { apt-get update -qq; apt-get install -y -qq nginx >/dev/null; }
timedatectl set-timezone Asia/Makassar 2>/dev/null || true
mkdir -p /var/cache/nginx/simulasi

cat > /etc/nginx/sites-available/simulasi <<EOF
# Dibuat deploy/pasang-simulasi.sh (cbtadmin_plus). Server utama: ${UTAMA}
proxy_cache_path /var/cache/nginx/simulasi levels=1 keys_zone=simulasi:10m max_size=100m inactive=1d;

server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    server_tokens off;

    proxy_set_header Host \$http_host;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    # X-Forwarded-Proto TIDAK dikirim: server utama (cbt-https.conf) mengalihkan "http" ke https (aturan Cloudflare).
    proxy_set_header X-Forwarded-Proto "";
    proxy_set_header Cookie "";
    proxy_hide_header Set-Cookie;
    proxy_ignore_headers Set-Cookie Cache-Control Expires;
    proxy_connect_timeout 5s;
    proxy_read_timeout 30s;

    location = / { return 302 /cbtadmin/simulasi; }
    location = /simulasi { return 302 /cbtadmin/simulasi\$is_args\$args; }

    # Halaman dashboard (hanya GET). Cache 15 detik: banyak pengunjung = satu permintaan ke server utama.
    location = /cbtadmin/simulasi {
        limit_except GET { deny all; }
        proxy_pass http://${UTAMA};
        proxy_cache simulasi;
        proxy_cache_key \$request_uri;
        proxy_cache_valid 200 404 15s;
        proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;
        proxy_cache_lock on;
        add_header X-Cache \$upstream_cache_status;
    }

    # Aset tampilan dan ikon saja.
    location ~ ^/cbtadmin/assets/(css|vendor)/ {
        limit_except GET { deny all; }
        proxy_pass http://${UTAMA};
        proxy_cache simulasi;
        proxy_cache_valid 200 1h;
    }
    location = /cbtadmin/tools/gambar/favicon {
        limit_except GET { deny all; }
        proxy_pass http://${UTAMA};
        proxy_cache simulasi;
        proxy_cache_valid 200 1h;
    }

    location / { return 404; }
}
EOF
ln -sf /etc/nginx/sites-available/simulasi /etc/nginx/sites-enabled/simulasi
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
systemctl enable -q nginx
systemctl reload nginx 2>/dev/null || systemctl restart nginx
echo "Server simulasi siap: http://$(hostname -I | awk '{print $1}')/  ->  ${UTAMA}/cbtadmin/simulasi"
SKRIP
