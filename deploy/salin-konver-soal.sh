#!/usr/bin/env bash
# Salin aplikasi Konver Soal (repo ichalsmiler/konver-soal, Word .docx → RTF) ke app/KonverSoal/ untuk menu
# Soal → Konvert Soal. Jalankan di PC setiap kali repo konver-soal berubah, lalu commit hasilnya dan deploy.
# Pakai: bash deploy/salin-konver-soal.sh [folder-konver-soal]   (bawaan: ../konver-soal di samping repo ini)
set -euo pipefail
cd "$(dirname "$0")/.."
SRC="${1:-../konver-soal}"
[ -f "$SRC/index.html" ] && [ -d "$SRC/js" ] || { echo "Folder konver-soal tidak ditemukan: $SRC" >&2; exit 1; }
rm -rf app/KonverSoal
mkdir -p app/KonverSoal/js app/KonverSoal/lib
cp "$SRC/index.html" "$SRC/styles.css" app/KonverSoal/
[ -f "$SRC/VERSION" ] && cp "$SRC/VERSION" app/KonverSoal/
cp "$SRC"/js/*.js app/KonverSoal/js/
cp "$SRC"/lib/*.js "$SRC"/lib/*.txt app/KonverSoal/lib/ 2>/dev/null || cp "$SRC"/lib/*.js app/KonverSoal/lib/
echo "Selesai: app/KonverSoal (versi $(cat app/KonverSoal/VERSION 2>/dev/null || echo '?'), $(du -sh app/KonverSoal | cut -f1))"
