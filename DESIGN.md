# DESIGN.md: CBT Admin

Arah tampilan panel, disalin dari jawaban pengguna (admin IT SMAN 2 Makassar).

- 15 November 2026: warna logo sekolah, terang bawaan + tombol gelap.
- 16 November 2026: pengguna memilih saran skill ui-ux-pro-max ("Real-Time / Operations",
  slate gelap + hijau status, Fira Sans/Fira Code), dengan batasan antislop yang disetujui pengguna:
  glassmorphism hanya aksen (R-10), Fira Code hanya untuk angka/token/kode (R-06).

- 21 November 2026 (berlaku sekarang): pengguna meminta tampilan meniru Dens-UI (dendense.github.io/dens-ui):
  Mars Green + satu aksen oranye, netral kehijauan, sudut lebih bulat, bayangan lembut. Font Figtree (teks) + JetBrains Mono (angka/token), woff2 lokal.

## Identitas
- Panel operasional ujian sekolah; dipakai admin dan pengawas ruang selama ujian berlangsung (sering dibuka lama
  di ruang lab); tema gelap tersedia lewat tombol untuk mengurangi silau. Logo sekolah tetap tampil di bilah atas.

## Karakter
- Seimbang dan modern, rapat seperti dasbor (density 8).
- Tema bawaan TERANG (16 Nov 2026, permintaan pengguna); gelap lewat tombol.
- Dial: ENERGY 2 / RHYTHM 2 / MOTION 1 (hanya transisi hover/focus; tanpa scroll-reveal agar data tidak berkedip
  saat refresh otomatis).

## Palet
- Terang (bawaan): latar `#EDF0EF`, kartu putih, permukaan kedua `#F5F7F6`, garis `#DBE2DF`, teks `#0F1716`,
  teks redup `#566361`, bilah atas tinta `#0F1716`.
- Gelap (lewat tombol): latar `#0C0B0B`, kartu `#161918`, permukaan kedua `#1E2322`, teks `#ECF2F0`, redup `#9AA8A5`.
- Aksen utama Mars Green: tombol `#00797A` berteks putih (5,2:1; `#008C8D` asli hanya 4,1:1), teks/tautan `#00696A`;
  di gelap `#19A3A4` berteks `#031414`. Satu aksen kedua oranye `#FF8A00` (garis kartu token, login, peringatan).
- Status: sudah = Mars Green/mint, sedang berjalan = biru Klein `#0B3289` (gelap `#6C8FF0`), perlu perhatian = oranye,
  kritis = merah `#A42423` (gelap `#F74835`), belum = abu kehijauan.

## Tipografi
- Figtree (teks, variabel 300–900) dan JetBrains Mono (token, angka statistik, kode), seperti Dens-UI; woff2 variabel disimpan lokal di
  `public/assets/fonts` (lisensi SIL OFL 1.1) agar tidak bergantung CDN.

## Efek
- Kaca (backdrop blur) hanya di bilah atas dan kartu token. Bayangan lembut `--shadow-panel` di kartu, `--shadow-raise` di kartu statistik; radius `.875rem`.
