#!/usr/bin/env bash
# Kirim setelan Moodle CBT (plugin, setelan, CSS tema, bahasa) dari komputer ini ke server lalu pasang.
# Jalankan setelah install-moodle.sh di server baru, atau kapan saja untuk menyamakan setelan.
# Pakai (Git Bash, dari folder cbtadmin):  bash deploy/setelan-moodle.sh [host-ssh]
set -euo pipefail

HOST="${1:-moodle}"
cd "$(dirname "$0")/.."

tar -czf - deploy/moodle moodle | ssh -o BatchMode=yes "$HOST" 'rm -rf ~/cbt-setelan && mkdir -p ~/cbt-setelan && tar --no-same-owner -xzf - -C ~/cbt-setelan'
ssh -o BatchMode=yes "$HOST" 'sed -i "s/\r$//" ~/cbt-setelan/deploy/moodle/pasang.sh && sudo bash ~/cbt-setelan/deploy/moodle/pasang.sh ~/cbt-setelan'
