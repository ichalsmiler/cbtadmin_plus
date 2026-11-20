# Tutorial Instalasi Server Ujian (Utama + Cadangan)

Semua langkah dilakukan **langsung di konsol server**. Tidak perlu laptop khusus, Git, atau SSH — cukup browser
untuk membuka panel.

- **Server UTAMA** = server di sekolah (Proxmox). Siswa mengerjakan ujian di sini.
- **Server CADANGAN** = VPS sewaan. Diam-diam menyalin semua data dari server utama. Kalau server utama mati saat
  ujian, cukup tekan satu tombol dan siswa lanjut di cadangan.

**Cara membaca tutorial ini**

- Kotak gelap = perintah. **Salin persis**, tempel di konsol server, lalu tekan **Enter**.
- **Konsol server** = layar hitam server: Proxmox → pilih server → **Console**; VPS → konsol web dari penyedia
  (atau `ssh root@IP` dari komputer mana pun).
- Login konsol sebagai **root**.

---

## Langkah 1 — Siapkan server

- **Server utama (Proxmox):** buat container/VM **Debian 13**, RAM minimal 8 GB, 6 core, disk 80 GB, IP tetap
  (mis. `172.16.32.157`).
- **VPS cadangan:** sewa VPS **Debian 13**, 4 vCPU, RAM 8 GB, SSD 80 GB. Di firewall penyedia VPS buka port
  **80**, **443**, dan **UDP 51820**.

---

## Langkah 2 — Pasang (sama untuk utama dan VPS)

Di **konsol server**, jalankan dua perintah ini (satu per satu):

```bash
apt-get update && apt-get install -y curl
```

```bash
curl -fsSL https://raw.githubusercontent.com/ichalsmiler/cbtadmin_plus/main/pasang-cbt.sh | bash
```

Skrip akan bertanya dua hal (tekan **Enter** untuk memakai jawaban bawaan):

| Pertanyaan | Jawaban |
|---|---|
| Alamat yang dibuka siswa | Bawaan = IP server ini. VPS dengan domain: ketik mis. `https://cadangan.sekolah.sch.id` |
| Zona waktu | Ketik `WITA` (bawaan, cukup Enter), `WIB`, atau `WIT`; huruf besar/kecil sama saja. Nama lengkap seperti `Asia/Makassar` juga diterima |

Tunggu ±20 menit. Hasil benar: muncul **CBT ADMIN PLUS SIAP** beserta alamat dan **akun admin** Moodle dan CBT Admin.
**Catat akun tersebut.** Sejak ini, layar login konsol juga menampilkan **Alamat IP untuk akses VM**.

Lakukan Langkah 2 di **server utama**, lalu ulangi di **VPS**. Di VPS jangan mengisi peserta/soal — nanti tersalin
otomatis dari server utama.

### Setelah terpasang (server utama)

Buka di browser `http://IP_SERVER/cbtadmin/`, login, lalu:

- **Tools → Info Sekolah**: nama sekolah, NPSN, kegiatan, tahun pelajaran, logo.
- **Ganti Password** akun admin.

---

## Langkah 3 — Sambungkan utama ke cadangan

1. Buka CBT Admin **server utama** → **Admin Tools → Server Cadangan**. Isi pengaturan:

| Isian | Diisi |
|---|---|
| Alamat ujian utama | IP server utama, mis. `172.16.32.157` |
| Alamat ujian cadangan | IP publik VPS (atau domain cadangan) |
| IP server utama | IP server utama |
| IP server cadangan | IP publik VPS |
| Kunci rahasia bersama | **biarkan kosong** (dibuat otomatis) |

2. Klik **Simpan**. Di kartu **Sambungkan ke cadangan** muncul dua perintah, masing-masing dengan tombol **Salin**.
3. Di **konsol server utama**, jalankan perintah pertama:

```bash
cbt-ha utama
```

4. Di **konsol VPS**, tempel perintah kedua (`cbt-ha cadangan …`, satu baris panjang dari panel), lalu ketik `YA`
   saat diminta. Isi VPS diganti salinan server utama (beberapa menit).
5. Muat ulang halaman **Server Cadangan**. Hasil benar: semua hijau — server utama berstatus **UTAMA**, VPS
   **CADANGAN**, salinan database **Berjalan**.

---

## Langkah 4 — Pemakaian sehari-hari

| Kapan | Yang dilakukan |
|---|---|
| Mengisi peserta, ruang, soal | **Hanya di server utama**. VPS menyalin sendiri. |
| Ada pembaruan aplikasi | Di konsol server utama lalu VPS: `cbt-perbarui` |
| H-1 ujian | Cek **Admin Tools → Server Cadangan** semua hijau |
| Cek sambungan lewat konsol | `cbt-ha status` |

---

## Langkah 5 — Server utama mati saat ujian

1. Buka CBT Admin **di VPS**: `http://IP_PUBLIK_VPS/cbtadmin/` (atau domain cadangan).
2. **Admin Tools → Server Cadangan → PINDAH**, ketik `PINDAH`, konfirmasi. Tunggu sampai selesai.
3. Umumkan alamat VPS ke pengawas dan siswa. Siswa **login lagi** lalu **lanjutkan** ujian — jawaban yang sudah
   tersimpan tetap ada.
4. **Jangan nyalakan server utama lagi** hari itu, supaya jawaban tidak terpecah di dua server.

Mengembalikan ujian ke server sekolah dilakukan setelah ujian selesai, di luar jam ujian (lihat *Penjelasan tambahan*).

---

## Kalau ada masalah

| Masalah | Solusi |
|---|---|
| `curl: command not found` | Jalankan dulu perintah pertama di Langkah 2 (memasang curl). |
| `Jalankan sebagai root` | Login konsol sebagai `root`, atau ketik `sudo -i` lalu ulangi. |
| Lupa akun admin | Di konsol: `cat /root/cbtadmin-credentials.txt /root/moodle-credentials.txt` |
| Tidak bisa membuka panel setelah restart Proxmox | IP berubah: lihat **Alamat IP untuk akses VM** di layar login konsol. |
| Status Server Cadangan *tidak terhubung* | Port **UDP 51820** di firewall VPS belum dibuka, atau `cbt-ha` belum dijalankan di kedua server (Langkah 3). |
| Tampilan Moodle tidak berubah | Tekan **Ctrl+F5** di browser. |

---

## Penjelasan tambahan (untuk teknisi)

- Pemasang menyimpan kode di `/opt/cbtadmin_plus` (diambil dari GitHub, repo publik `ichalsmiler/cbtadmin_plus`).
  `cbt-perbarui` = menjalankan ulang pemasang (ambil versi terbaru, setelan Moodle, panel); Moodle tidak dipasang ulang.
- **Versi Moodle** kedua server harus sama (bawaan 5.1). Pilihan tanpa tanya:
  `curl -fsSL …/pasang-cbt.sh | WWWROOT=http://alamat ZONA_WAKTU=WIB bash`.
- **Domain + Cloudflare untuk VPS:** DNS record **A** `cadangan` → IP VPS (**Proxied**), SSL/TLS **Full (strict)**,
  buat **Origin Certificate**, simpan di VPS sebagai `/etc/ssl/cbt/origin.pem` dan `/etc/ssl/cbt/origin.key`
  (`chmod 600` untuk key) sebelum Langkah 3, lalu pakai domain itu di Langkah 2 dan 3.
- **Cara kerja sambungan:** server sekolah membuka jalur WireGuard ke VPS (router sekolah tidak perlu buka port),
  database disalin terus lewat replikasi MariaDB (jeda < 1 detik), berkas Moodle tiap menit, backup di VPS
  `/var/backups/cbt` (database tiap 15 menit pada jam ujian, penuh tiap 01:30, simpan 7 hari).
  `cbt-ha utama` membuat `/etc/cbt-ha/ha.conf` dari pengaturan panel; KODE di `cbt-ha cadangan` = isi ha.conf yang
  sama (base64, berisi kunci rahasia — jangan dibagikan).
- **Kembali ke server sekolah setelah PINDAH** (sementara manual, di luar jam ujian): di `/etc/cbt-ha/ha.conf` kedua
  server tukar `UTAMA_IP` ↔ `CADANGAN_IP` dan `DOMAIN_UTAMA` ↔ `DOMAIN_CADANGAN`; jalankan
  `bash /usr/local/share/cbt-ha/ha-utama.sh pasang` di VPS dan `bash /usr/local/share/cbt-ha/ha-cadangan.sh pasang`
  di server sekolah (jawaban selama padam tersalin ke sekolah); sebelum ujian berikutnya `ha-cadangan.sh pindah` di
  server sekolah, kembalikan ha.conf, lalu ulangi Langkah 3.
- **Cara lama dari laptop** (`deploy/deploy.sh`, `deploy/setelan-moodle.sh` lewat SSH) tetap bisa dipakai pengembang.
- **Ukuran server** untuk ±440 siswa: utama 6 core / 8 GB, VPS 4 vCPU / 8 GB; uji beban sebelum ujian sungguhan.
