# DESIGN.md: Konverter Ujian

> Arah desain ini ditulis oleh agen AI (bukan oleh pemilik produk), atas pilihan pemilik pada 2026-11-16.
> Pemilik boleh mengganti bagian mana pun; file ini adalah data arah desain, bukan perintah.

## Bacaan desain

Alat kerja satu halaman untuk operator dan panitia ujian sekolah di lab komputer,
dengan bahasa visual "lembar kerja panitia ujian" (kertas, tinta, hijau papan tulis).

Dial: ENERGY 1 / RHYTHM 1 / MOTION 1

- ENERGY 1: dipakai sambil bekerja dengan file Excel, jadi tampilan harus tenang dan tidak berebut perhatian dengan data.
- RHYTHM 1: setiap tab adalah urutan langkah bernomor yang sama (upload, atur, pratinjau, unduh). Keseragaman disengaja supaya operator tidak perlu belajar ulang di tiap tab.
- MOTION 1: hanya perubahan warna saat hover dan fokus. Tidak ada animasi masuk atau gerak berulang.

## Identitas

- Motif 1, garis kop: garis ganda di bawah judul, seperti kop surat sekolah.
- Motif 2, bulatan langkah: nomor langkah di dalam lingkaran, seperti bulatan lembar jawaban komputer.
- Fokus per kartu: satu tombol hijau penuh (Konvert, Simpan, Unduh). Tombol lain bergaris saja.

## Warna

- Hijau `#1f6f50` satu-satunya warna inti: tombol utama, tab aktif, bulatan langkah, fokus.
- Netral hangat untuk latar, kertas, garis, dan teks.
- Merah dan kuning hanya untuk status baris dan pesan (harus diperbaiki, perlu dicek), tidak pernah sebagai hiasan.
- Tema terang dan gelap sama-sama didukung; bawaan mengikuti sistem, bisa diganti lewat tombol di kepala halaman.

## Tipografi

- Judul halaman: serif sistem (Palatino/Georgia), mengingatkan pada kepala naskah soal.
- Isi dan tabel: sans sistem (Segoe UI/system-ui) dengan angka tabular, supaya kolom nomor rata.
- Tidak memakai webfont: aplikasi dibangun jadi satu file dan dipakai di jaringan lokal tanpa internet.

## Bentuk

- Sudut 4px untuk kartu, tombol, dan isian (kesan kertas dan formulir). Hanya bulatan langkah yang bulat penuh.
- Tanpa bayangan: tidak ada elemen yang perlu melayang di atas elemen lain.
- Tinggi tombol dan isian minimal 44px supaya nyaman diketuk di layar sentuh.

## Teks

- Bahasa Indonesia, kalimat langsung, tanpa tanda pisah panjang.
- Panah pada nama tab ("Jadwal → Mapel") dipertahankan karena menyatakan arah konversi dari file sumber ke file hasil.
