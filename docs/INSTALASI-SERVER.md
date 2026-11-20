# Tutorial Instalasi Server Ujian (Utama + Cadangan)

Tutorial ini untuk memasang **Moodle + CBT Admin Plus** di dua server, tanpa perlu paham Linux:

- **Server UTAMA** = server di sekolah (Proxmox). Siswa mengerjakan ujian di sini.
- **Server CADANGAN** = VPS sewaan. Diam-diam menyalin semua data dari server utama. Kalau server utama mati saat
  ujian, cukup tekan satu tombol dan siswa lanjut di cadangan.

**Cara membaca tutorial ini**

- Kotak gelap = perintah. **Salin persis**, tempel, lalu tekan **Enter**.
- Ganti tulisan `IP_SERVER` dengan alamat IP server Anda, mis. `172.16.32.157`.
- Setiap langkah ada keterangan **di mana** perintah diketik dan **hasil yang benar**.
- **Konsol server** = layar hitam server (Proxmox: pilih server → **Console**; VPS: konsol web dari penyedia).
- **Git Bash PC admin** = buka folder proyek `cbtadmin_plus` (dari langkah 0), klik kanan → **Open Git Bash here**.

---

## Langkah 0 — Siapkan PC admin (sekali per komputer)

PC admin = komputer Windows yang dipakai untuk memasang dan memperbarui server. Lewati langkah ini bila PC sudah
punya folder `cbtadmin_plus` (mis. `F:\apps\cbtadmin_plus`).

1. **Pasang Git for Windows** dari **git-scm.com/download/win** (klik Next sampai selesai). Git Bash ikut terpasang.
2. **Pastikan akun GitHub Anda sudah diberi akses** ke repositori `ichalsmiler/cbtadmin_plus` (repositori ini privat;
   minta pemilik menambahkan akun Anda di *Settings → Collaborators*).
3. **Ambil proyek dari GitHub.** Buka folder tempat menyimpan proyek (mis. drive `F:`), klik kanan → **Open Git Bash
   here**, lalu:

```bash
git clone https://github.com/ichalsmiler/cbtadmin_plus.git
```

Bila muncul jendela login GitHub, pilih **Sign in with your browser** dan masuk dengan akun yang diberi akses.
Hasil benar: muncul folder baru `cbtadmin_plus`.

4. **Buat kunci SSH PC ini** (cukup sekali; lewati bila sudah ada). Di Git Bash:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_moodle
```

Tekan **Enter** terus sampai selesai (tanpa mengisi apa pun).

5. **Mengambil pembaruan proyek** di kemudian hari: buka Git Bash di folder `cbtadmin_plus`, lalu `git pull`.

---

## Bagian A — Siapkan server (sekali per server)

Lakukan Bagian A untuk server utama, lalu ulangi untuk VPS cadangan.

### A1. Buat server Debian

- **Server utama (Proxmox):** buat container/VM **Debian 13**, RAM minimal 8 GB, 6 core, disk 80 GB, IP tetap
  (mis. `172.16.32.157`).
- **VPS cadangan:** sewa VPS **Debian 13**, 4 vCPU, RAM 8 GB, SSD 80 GB. Di pengaturan firewall penyedia VPS,
  buka port **80**, **443**, dan **UDP 51820**.

### A2. Buat user untuk PC admin

**Di konsol server**, login sebagai `root`, lalu ketik satu per satu:

```bash
apt-get update && apt-get install -y sudo openssh-server
```

```bash
adduser --gecos "" smiler
```

Diminta password: ketik password baru untuk `smiler` dua kali (huruf tidak terlihat saat diketik, itu normal).

```bash
echo 'smiler ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-smiler && chmod 440 /etc/sudoers.d/90-smiler
```

Lihat IP server (catat angkanya, mis. `172.16.32.157`):

```bash
hostname -I
```

### A3. Hubungkan PC admin ke server

**Di Git Bash PC admin.** Kirim kunci SSH PC (dari langkah 0) ke server (ganti `IP_SERVER`):

```bash
ssh-copy-id -i ~/.ssh/id_ed25519_moodle.pub smiler@IP_SERVER
```

Ditanya *"Are you sure…"* → ketik `yes`. Ditanya password → ketik password `smiler` dari langkah A2.
Setelah ini PC tidak perlu password lagi.


### A4. Beri nama singkat untuk server

**Di Git Bash PC admin:**

```bash
notepad ~/.ssh/config
```

Tambahkan di paling bawah, lalu **Simpan** (Ctrl+S). Nama setelah `Host` bebas, contoh `utama` untuk server sekolah
dan `vps` untuk cadangan:

```
Host utama
    HostName 172.16.32.157
    User smiler
    IdentityFile ~/.ssh/id_ed25519_moodle
    IdentitiesOnly yes

Host vps
    HostName IP_PUBLIK_VPS
    User smiler
    IdentityFile ~/.ssh/id_ed25519_moodle
    IdentitiesOnly yes
```

**Cek:**

```bash
ssh utama 'sudo whoami'
```

Hasil benar: muncul `root` **tanpa** ditanya password. Lakukan juga `ssh vps 'sudo whoami'`.

---

## Bagian B — Pasang server UTAMA

Semua perintah di **Git Bash PC admin**, satu per satu, tunggu sampai selesai.

### B1. Pasang Moodle (±15 menit)

```bash
scp deploy/install-moodle.sh utama:~/
```

```bash
ssh utama 'sudo WWWROOT=http://172.16.32.157 bash ~/install-moodle.sh'
```

`WWWROOT` = alamat yang dibuka siswa (IP server utama). Hasil benar: muncul *MOODLE SIAP DIPAKAI* dan *HTTP 200*.

### B2. Pasang setelan ujian

```bash
bash deploy/setelan-moodle.sh utama
```

Hasil benar: muncul *SETELAN MOODLE CBT TERPASANG*. Sejak ini, layar login konsol server menampilkan
**Alamat IP untuk akses VM**, jadi IP tidak perlu dicek manual lagi.

### B3. Pasang CBT Admin Plus

```bash
bash deploy/deploy.sh utama
```

Hasil benar: muncul *CBT ADMIN TERPASANG* dan *HTTP 200*.

### B4. Ambil password admin

```bash
ssh utama 'sudo cat /root/cbtadmin-credentials.txt /root/moodle-credentials.txt'
```

Catat username/password **CBT Admin** dan **Moodle**. Lalu buka di browser:

- Moodle: `http://172.16.32.157`
- CBT Admin: `http://172.16.32.157/cbtadmin/`

Setelah login CBT Admin: **Tools → Info Sekolah** (isi nama sekolah, NPSN, kegiatan, tahun pelajaran, logo) dan
**Ganti Password**.

---

## Bagian C — Pasang server CADANGAN (VPS)

Sama seperti Bagian B, tetapi pakai `vps` dan alamat VPS. **Jangan isi peserta/soal di VPS** — nanti tersalin
otomatis dari server utama.

```bash
scp deploy/install-moodle.sh vps:~/
```

```bash
ssh vps 'sudo WWWROOT=http://IP_PUBLIK_VPS bash ~/install-moodle.sh'
```

```bash
bash deploy/setelan-moodle.sh vps
```

```bash
bash deploy/deploy.sh vps
```

> Punya domain (mis. `cadangan.sekolah.sch.id`)? Pakai `WWWROOT=https://cadangan.sekolah.sch.id` dan siapkan
> sertifikat Cloudflare (lihat *Penjelasan tambahan* di akhir). Tanpa domain, IP VPS cukup untuk uji coba.

---

## Bagian D — Sambungkan utama ke cadangan

### D1. Isi pengaturan di panel UTAMA

Buka CBT Admin **server utama** → **Admin Tools → Server Cadangan**. Isi:

| Isian | Diisi |
|---|---|
| Alamat ujian utama | IP server utama, mis. `172.16.32.157` |
| Alamat ujian cadangan | IP publik VPS (atau domain cadangan) |
| IP server utama | IP server utama |
| IP server cadangan | IP publik VPS |
| Kunci rahasia bersama | **biarkan kosong** (dibuat otomatis) |

Klik **Simpan**, lalu **Unduh ha.conf**. Berkas `ha.conf` tersimpan di folder *Downloads*.

### D2. Kirim ha.conf ke kedua server

**Di Git Bash**, pindah ke folder Downloads lalu kirim:

```bash
cd ~/Downloads
```

```bash
scp ha.conf utama:~/ && ssh utama 'sudo install -D -m 600 ~/ha.conf /etc/cbt-ha/ha.conf && rm ~/ha.conf'
```

```bash
scp ha.conf vps:~/ && ssh vps 'sudo install -D -m 600 ~/ha.conf /etc/cbt-ha/ha.conf && rm ~/ha.conf'
```

### D3. Nyalakan sambungan (urutan: utama dulu, baru VPS)

```bash
ssh utama 'sudo bash /usr/local/share/cbt-ha/ha-utama.sh pasang'
```

```bash
ssh vps 'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh pasang'
```

Langkah kedua mengganti isi VPS dengan salinan server utama (beberapa menit).

### D4. Cek

Panel **server utama** → **Admin Tools → Server Cadangan → Status**. Hasil benar: semua hijau (terhubung,
replikasi berjalan, salinan berkas dan backup ada). Mulai sekarang setiap data di server utama otomatis tersalin
ke VPS.

---

## Bagian E — Pemakaian sehari-hari

| Kapan | Yang dilakukan |
|---|---|
| Mengisi peserta, ruang, soal | **Hanya di server utama**. VPS menyalin sendiri. |
| Ada pembaruan aplikasi | `bash deploy/deploy.sh utama` lalu `bash deploy/deploy.sh vps` |
| Ada pembaruan setelan Moodle | `bash deploy/setelan-moodle.sh utama` lalu `bash deploy/setelan-moodle.sh vps` |
| H-1 ujian | Cek **Server Cadangan → Status** semua hijau |

---

## Bagian F — Server utama mati saat ujian

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
| `ssh-copy-id` gagal / `Permission denied` | Ulangi A2 (cek password `smiler`), lalu A3. |
| `ssh utama` masih minta password | Ulangi A3. Pastikan baris `IdentityFile` di A4 benar. |
| Tidak bisa ke server setelah restart Proxmox | IP berubah. Lihat IP di layar login konsol, perbarui `HostName` di A4. |
| Status Server Cadangan *tidak terhubung* | Port **UDP 51820** di firewall VPS belum dibuka, atau ha.conf belum dikirim ke kedua server (D2). |
| Tampilan Moodle tidak berubah | Tekan **Ctrl+F5** di browser. |
| Lupa password admin | Ulangi B4. |

---

## Penjelasan tambahan (untuk teknisi)

- **Versi Moodle** kedua server harus sama (bawaan 5.1). Zona waktu bawaan WITA; untuk WIB tambahkan
  `ZONA_WAKTU=Asia/Jakarta` sebelum `bash ~/install-moodle.sh`.
- **Domain + Cloudflare untuk VPS:** DNS record **A** `cadangan` → IP VPS (**Proxied**), SSL/TLS **Full (strict)**,
  buat **Origin Certificate**, simpan di VPS sebagai `/etc/ssl/cbt/origin.pem` dan `/etc/ssl/cbt/origin.key`
  (`chmod 600` untuk key), lalu pakai domain itu di B1/C dan D1.
- **Cara kerja sambungan:** server sekolah membuka jalur WireGuard ke VPS (router sekolah tidak perlu buka port),
  database disalin terus lewat replikasi MariaDB (jeda < 1 detik), berkas Moodle tiap menit, backup di VPS
  `/var/backups/cbt` (database tiap 15 menit pada jam ujian, penuh tiap 01:30, simpan 7 hari).
  Status lewat terminal: `ssh vps 'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh status'`.
- **Kembali ke server sekolah setelah PINDAH** (sementara manual, di luar jam ujian): di `ha.conf` kedua server tukar
  `UTAMA_IP` ↔ `CADANGAN_IP` dan `DOMAIN_UTAMA` ↔ `DOMAIN_CADANGAN`; jalankan `ha-utama.sh pasang` di VPS dan
  `ha-cadangan.sh pasang` di server sekolah (jawaban selama padam tersalin ke sekolah); sebelum ujian berikutnya
  `ha-cadangan.sh pindah` di server sekolah, kembalikan `ha.conf`, ulangi D3.
- **Ukuran server** untuk ±440 siswa: utama 6 core / 8 GB, VPS 4 vCPU / 8 GB; uji beban sebelum ujian sungguhan.
