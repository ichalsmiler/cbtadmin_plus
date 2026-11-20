# Tutorial Instalasi Server Utama dan Server Cadangan

Panduan ini memasang **Moodle + CBT Admin Plus** di dua server:

- **Server utama** di sekolah (container/VM Proxmox, IP LAN, mis. `172.16.32.157`). Siswa di lab mengerjakan
  ujian di sini.
- **Server cadangan** di VPS (IP publik). Terus menyalin database dan berkas dari server utama. Bila server
  utama padam/rusak saat ujian, cadangan dibuka dengan satu tombol dan siswa melanjutkan di sana.

```
   PC admin (Git Bash) ──ssh──► Server UTAMA (sekolah)  ══ WireGuard (UDP 51820) ══►  Server CADANGAN (VPS)
                                  Moodle + CBT Admin        replikasi MariaDB           Moodle + CBT Admin
   Siswa di lab ──http──►         Nginx/PHP/MariaDB/Redis   + rsync moodledata          menunggu, "belum aktif"
```

Server sekolah yang menghubungi VPS, jadi **router sekolah tidak perlu membuka port**. Semua perintah di bawah
dijalankan dari **PC admin** di folder proyek (`F:\apps\cbtadmin_plus`, buka dengan **Git Bash**), kecuali yang
ditandai *di server*.

---

## 0. Persiapan

| | Server utama (sekolah) | Server cadangan (VPS) |
|---|---|---|
| Sistem | Debian 12 / 13 (64-bit) | Debian 12 / 13 (64-bit) |
| Saran untuk ±440 siswa serentak | 6 core, 8 GB RAM, SSD 80 GB | 4 vCPU, 8 GB RAM, SSD 80 GB |
| Alamat | IP LAN tetap, mis. `172.16.32.157` | IP publik, mis. `103.x.x.x` |
| Port yang dibuka | 80 (dari LAN) | 80/443 dan **UDP 51820** di firewall penyedia VPS |

Siapkan juga:

1. **Kunci SSH** di PC admin. Bila belum ada: `ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519_moodle`.
2. **Domain untuk cadangan** (disarankan, mis. `cadangan.sekolah.sch.id` lewat Cloudflare). Selama belum ada,
   cadangan bisa memakai IP publik VPS (http tanpa enkripsi, **hanya untuk uji coba**).
3. Zona waktu: bawaan **WITA** (`Asia/Makassar`). Untuk WIB tambahkan `ZONA_WAKTU=Asia/Jakarta` di langkah 2.
4. Versi Moodle **kedua server harus sama** (bawaan 5.1). Jangan memperbarui satu server saja.

---

## 1. Akses SSH dari PC admin (lakukan di kedua server)

*Di server*, login sebagai root (konsol Proxmox / panel VPS, atau `ssh user@IP` lalu `su -`). Buat user `smiler`
dengan kunci SSH PC admin dan sudo tanpa kata sandi:

```bash
apt-get update && apt-get install -y sudo
id smiler || adduser --gecos "" smiler
mkdir -p /home/smiler/.ssh
echo 'ISI DENGAN ISI BERKAS ~/.ssh/id_ed25519_moodle.pub DI PC ADMIN' >> /home/smiler/.ssh/authorized_keys
chown -R smiler:smiler /home/smiler/.ssh && chmod 700 /home/smiler/.ssh && chmod 600 /home/smiler/.ssh/authorized_keys
echo 'smiler ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/90-smiler && chmod 440 /etc/sudoers.d/90-smiler
```

Di PC admin, tambahkan alias ke `~/.ssh/config` (ganti IP):

```
Host utama
    HostName 172.16.32.157
    User smiler
    IdentityFile ~/.ssh/id_ed25519_moodle

Host cadangan
    HostName 103.x.x.x
    User smiler
    IdentityFile ~/.ssh/id_ed25519_moodle
```

Uji: `ssh utama 'sudo whoami'` dan `ssh cadangan 'sudo whoami'` harus menjawab `root` tanpa meminta kata sandi.

---

## 2. Pasang server UTAMA

### 2.1 Moodle

```bash
scp deploy/install-moodle.sh utama:~/
ssh utama 'sudo WWWROOT=http://172.16.32.157 bash ~/install-moodle.sh'
```

`WWWROOT` = alamat yang dibuka siswa. Skrip memasang Nginx, PHP-FPM, MariaDB, Redis (sesi login), lalu Moodle
(±10–20 menit). Kata sandi admin Moodle dan database tersimpan *di server*: `sudo cat /root/moodle-credentials.txt`.

### 2.2 Setelan ujian Moodle

```bash
bash deploy/setelan-moodle.sh utama
```

Memasang plugin CBT (`local_cbtujian`, `cbtblokir`, `cbtnama`), Bahasa Indonesia, tampilan siswa, quiz 1 percobaan,
cache Redis, dan pengiriman gambar soal lewat Nginx. Aman dijalankan berulang.
Setelah ini layar login konsol (Proxmox/VPS) menampilkan **Alamat IP untuk akses VM** beserta alamat Moodle dan
CBT Admin, jadi IP server tidak perlu dicek manual.

### 2.3 CBT Admin Plus

```bash
bash deploy/deploy.sh utama
```

Memasang panel di `/cbtadmin/`. Akun admin panel *di server*: `sudo cat /root/cbtadmin-credentials.txt`.
Segera ganti kata sandinya di panel (**Tools → Info Sekolah → Ganti Password**).

### 2.4 Cek

- Buka `http://172.16.32.157` → halaman login Moodle tampil.
- Buka `http://172.16.32.157/cbtadmin/` → login panel.
- Di panel: **Tools → Info Sekolah** (nama sekolah, NPSN, nama kegiatan, tahun pelajaran, logo).

---

## 3. Pasang server CADANGAN (VPS)

Sama seperti server utama, tetapi dengan alamat VPS sendiri. **Jangan isi data peserta/soal di sini**; semuanya
nanti disalin dari server utama.

```bash
scp deploy/install-moodle.sh cadangan:~/
ssh cadangan 'sudo WWWROOT=https://cadangan.sekolah.sch.id bash ~/install-moodle.sh'
bash deploy/setelan-moodle.sh cadangan
bash deploy/deploy.sh cadangan
```

Belum punya domain? Pakai `WWWROOT=http://IP_PUBLIK_VPS` (uji coba saja).

Di panel penyedia VPS buka **UDP 51820** (WireGuard) serta TCP 80/443.

### 3.1 Bila memakai domain + Cloudflare (https)

1. Cloudflare → DNS: record **A** `cadangan` → IP publik VPS, status **Proxied** (awan oranye).
2. SSL/TLS → Overview: mode **Full (strict)**.
3. SSL/TLS → Origin Server → **Create Certificate** untuk `cadangan.sekolah.sch.id`. *Di server cadangan* simpan:

```bash
sudo mkdir -p /etc/ssl/cbt
sudo nano /etc/ssl/cbt/origin.pem    # tempel Origin Certificate
sudo nano /etc/ssl/cbt/origin.key    # tempel Private Key
sudo chmod 600 /etc/ssl/cbt/origin.key
```

---

## 4. Hubungkan utama → cadangan

### 4.1 Isi pengaturan dan unduh `ha.conf`

Di panel **server utama**: **Admin Tools → Server Cadangan → Pengaturan**. Isi:

| Isian | Contoh |
|---|---|
| Alamat ujian utama | `172.16.32.157` (IP LAN, http) |
| Alamat ujian cadangan | `cadangan.sekolah.sch.id` (atau IP publik VPS untuk uji coba) |
| IP server utama | `172.16.32.157` |
| IP server cadangan | IP publik VPS |
| Kunci rahasia bersama | biarkan kosong: dibuat otomatis saat Simpan (jangan dibagikan) |
| Simpan backup / Jam backup 15 menitan | bawaan 7 hari / `6-17` |

Simpan, lalu klik **Unduh ha.conf**. Isi berkas ini harus **sama persis** di kedua server.
(Tanpa panel: salin `deploy/ha/ha.conf.contoh` menjadi `ha.conf`, isi, buat `HA_SECRET` dengan `openssl rand -hex 32`.)

### 4.2 Salin `ha.conf` ke kedua server

Dari folder tempat `ha.conf` diunduh:

```bash
scp ha.conf utama:~/   && ssh utama   'sudo install -D -m 600 ~/ha.conf /etc/cbt-ha/ha.conf && rm ~/ha.conf'
scp ha.conf cadangan:~/ && ssh cadangan 'sudo install -D -m 600 ~/ha.conf /etc/cbt-ha/ha.conf && rm ~/ha.conf'
```

### 4.3 Pasang (urutannya penting: utama dulu)

```bash
ssh utama    'sudo bash /usr/local/share/cbt-ha/ha-utama.sh pasang'
ssh cadangan 'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh pasang'
```

- `ha-utama.sh pasang`: WireGuard, binlog MariaDB, user replikasi, sumber rsync.
- `ha-cadangan.sh pasang`: **mengganti database dan berkas cadangan dengan salinan utama**, lalu replikasi berjalan
  terus (jeda biasanya < 1 detik), berkas Moodle disalin tiap menit, backup otomatis di `/var/backups/cbt`.
  Selama belum dipakai, alamat cadangan menampilkan *"Server cadangan, belum aktif"*.

### 4.4 Cek hubungan

- Panel utama → **Admin Tools → Server Cadangan → Status**: peran, hubungan, jeda replikasi, salinan berkas,
  backup terakhir harus hijau.
- Atau lewat terminal: `ssh cadangan 'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh status'`.
- Uji: tambah satu peserta uji di panel utama, lihat jumlahnya ikut bertambah di status cadangan, lalu hapus lagi.

---

## 5. Sehari-hari dan sebelum ujian

| Kapan | Lakukan |
|---|---|
| Ada pembaruan kode panel | `bash deploy/deploy.sh utama` lalu `bash deploy/deploy.sh cadangan` |
| Ada pembaruan setelan Moodle | `bash deploy/setelan-moodle.sh utama` lalu `bash deploy/setelan-moodle.sh cadangan` (di cadangan hanya plugin yang dipasang; setelan ikut replikasi) |
| H-1 ujian | Cek **Server Cadangan → Status** semua hijau; cek backup terbaru; uji login siswa di utama |
| Saat ujian | Pantau Dashboard dan Status Peserta di utama; jangan mengubah apa pun di cadangan |

Peserta, ruang, sesi, soal, dan jawaban **hanya diisi di server utama**; cadangan mendapatkannya lewat replikasi.

---

## 6. Server utama padam saat ujian (pindah ke cadangan)

1. Pastikan server utama memang mati/tidak bisa dipakai (listrik, jaringan, hardware).
2. Buka panel **di server cadangan** (`https://cadangan.sekolah.sch.id/cbtadmin/`) → **Admin Tools → Server Cadangan
   → PINDAH** (ketik `PINDAH` untuk konfirmasi). (Terminal: `ssh cadangan 'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh pindah'`.)
3. Cadangan berubah menjadi server ujian: database dibuka untuk menulis, cron Moodle dan token dinyalakan.
4. Umumkan alamat cadangan ke pengawas/siswa. Siswa **login lagi** dan **melanjutkan** quiz; jawaban yang sudah
   tersimpan tetap ada, sisa waktu tetap berjalan.
5. **Jangan nyalakan lagi server utama** selama ujian hari itu berlangsung di cadangan, supaya tidak ada dua server
   yang menerima jawaban.

Akses siswa dari lab ke VPS lewat internet sekolah, jadi uji dulu bandwidth-nya sebelum hari ujian.

---

## 7. Setelah ujian di cadangan: kembali ke server sekolah

Tombol "Kembalikan ke server sekolah" belum tersedia di panel. Sementara, lakukan di luar jam ujian:

1. Di `ha.conf` (**kedua** server, isi sama): tukar `UTAMA_IP` ↔ `CADANGAN_IP` dan `DOMAIN_UTAMA` ↔ `DOMAIN_CADANGAN`.
2. Jadikan VPS utama dan server sekolah cadangannya (jawaban selama padam ikut tersalin ke sekolah):

```bash
ssh cadangan 'sudo bash /usr/local/share/cbt-ha/ha-utama.sh pasang'
ssh utama    'sudo bash /usr/local/share/cbt-ha/ha-cadangan.sh pasang'
```

3. Sebelum ujian berikutnya, pindahkan terencana kembali ke sekolah: `ha-cadangan.sh pindah` di server sekolah,
   kembalikan `ha.conf` seperti semula, lalu ulangi langkah 4.3.

---

## 8. Masalah yang sering muncul

| Gejala | Penyebab / perbaikan |
|---|---|
| `Permission denied (publickey)` saat `ssh utama` | Kunci di `authorized_keys` belum benar; ulangi langkah 1, cek `chmod 600`. |
| Server tidak bisa diakses setelah restart Proxmox | IP container berubah/hilang; atur IP statis di Proxmox (Network) lalu restart container. |
| Status Server Cadangan: *tidak terhubung* | UDP 51820 belum dibuka di firewall VPS, atau `ha.conf` tidak sama di kedua server. |
| Jeda replikasi terus naik | Internet sekolah putus/lambat; cek `ha-cadangan.sh status`. Replikasi menyusul sendiri setelah tersambung. |
| Tampilan Moodle tidak berubah setelah setelan | Tekan **Ctrl+F5** di browser. |
| Lupa kata sandi admin | *Di server*: `sudo cat /root/cbtadmin-credentials.txt` (panel) atau `/root/moodle-credentials.txt` (Moodle). |
