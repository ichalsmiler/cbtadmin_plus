#!/usr/bin/env bash
# Build Konvert User (folder konvert/, TypeScript + Vite) menjadi satu berkas HTML yang disajikan
# menu "Konvert User" di panel: app/Konvert/konvert.html. Jalankan di PC (butuh Node.js 22+)
# setiap kali kode di konvert/ berubah, lalu commit hasilnya dan deploy seperti biasa.
set -euo pipefail
cd "$(dirname "$0")/../konvert"
npm ci --no-audit --no-fund
npm test
npm run build
mkdir -p ../app/Konvert
cp dist/index.html ../app/Konvert/konvert.html
echo "Selesai: app/Konvert/konvert.html ($(wc -c < ../app/Konvert/konvert.html) byte)"
