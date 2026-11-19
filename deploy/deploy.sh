#!/usr/bin/env bash
# Kirim kode panel dari komputer ini ke server lalu pasang.
# Pakai (Git Bash, dari folder cbtadmin):  bash deploy/deploy.sh [host-ssh]
set -euo pipefail

HOST="${1:-moodle}"
cd "$(dirname "$0")/.."

find app public deploy -type f \( -name '*.php' -o -name '*.sh' -o -name '*.css' -o -name '*.js' \) -exec sed -i 's/\r$//' {} +
tar -czf - app public deploy/install.sh deploy/ha | ssh -o BatchMode=yes "$HOST" 'rm -rf ~/cbtadmin-src && mkdir -p ~/cbtadmin-src && tar -xzf - -C ~/cbtadmin-src'
ssh -o BatchMode=yes "$HOST" 'sudo bash ~/cbtadmin-src/deploy/install.sh ~/cbtadmin-src'
