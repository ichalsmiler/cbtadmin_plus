// Tab "User → CBTAdmin": upload data peserta → pratinjau → unduh template CBTAdmin terisi.
// Aturan: Nama Rombel & Kelas / Jurusan = KELAS, Ruang = RG., Kel. Sesi = per tingkat (bawaan 1),
// Username = nomor UJIAN, Password = acak 2 huruf + 2 angka + 2 huruf (mis. HJ86KL).

import templateUrl from '../assets/template-cbtadmin.xlsx?url';
import { GalatFormat, type PesertaCBT, type PesertaMasuk, type Peringatan } from '../core/tipe';
import { urutTingkat } from '../core/jadwal';
import { bacaUser, cariPeserta, isiPassword, keCbt, kodeTidakCocok, nomorSesi, tingkatDariSheet, tingkatPeserta, tulisCbtCsv, tulisCbtXlsx, type SesiPerTingkat } from '../core/user';
import { ambilHasilMapel, ambilPeserta, catatPeserta, muatSesi, saatHasilMapelBerubah, simpanSesi, unduhCsv, unduhFile } from './simpan';
import { daftarPesan, el, tabel } from './tabel';

interface Status {
  namaFile: string;
  /** Peserta seperti terbaca dari file (password kosong untuk format DATA PESERTA). */
  asli: PesertaMasuk[];
  /** Peserta dengan password terisi; dibuat sekali agar pratinjau = file unduhan. */
  peserta: PesertaMasuk[];
  peringatan: Peringatan[];
  galat: Peringatan[];
  sheet: string[];
  /** '' = semua sheet. */
  /** Filter angkatan (tingkat X/XI/XII); '' = semua. Berlaku untuk pratinjau dan file unduhan. */
  angkatan: string;
  /** Filter kelas (nilai kolom KELAS); '' = semua. Berlaku untuk pratinjau dan file unduhan. */
  kelas: string;
  tampilkanPassword: boolean;
  /** Kata kunci pencarian di pratinjau (tidak memengaruhi file unduhan). */
  cari: string;
  /** Kel. Sesi per tingkat; diingat di browser. */
  sesi: SesiPerTingkat;
  /** Isian di kartu Pengaturan sesi yang belum disimpan. */
  sesiDraf: SesiPerTingkat;
}

const BATAS_PRATINJAU = 200;
const SEMUA = '';

function tanggalHariIni(): string {
  const d = new Date();
  const dua = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${dua(d.getMonth() + 1)}-${dua(d.getDate())}`;
}

let templateCache: Promise<ArrayBuffer> | null = null;
function muatTemplate(): Promise<ArrayBuffer> {
  templateCache ??= fetch(templateUrl).then((r) => {
    if (!r.ok) throw new Error(`template tidak bisa dimuat (${r.status})`);
    return r.arrayBuffer();
  });
  return templateCache;
}

/** Gabungkan banyak keterangan "tanpa nama" menjadi satu pesan agar daftar tidak panjang. */
function ringkasPesan(p: Peringatan[]): Peringatan[] {
  const info = p.filter((x) => x.tingkat === 'info');
  const lain = p.filter((x) => x.tingkat !== 'info');
  if (info.length <= 3) return p;
  const contoh = info.slice(0, 5).map((x) => x.lokasi).join('; ');
  return [
    ...lain,
    { tingkat: 'info', pesan: `${info.length} nomor ujian tanpa nama siswa dilewati (mis. ${contoh}${info.length > 5 ? '; …' : ''})` },
  ];
}

export function pasangTabUser(akar: HTMLElement): void {
  const s: Status = {
    namaFile: '',
    asli: [],
    peserta: [],
    peringatan: [],
    galat: [],
    sheet: [],
    angkatan: SEMUA,
    kelas: SEMUA,
    tampilkanPassword: false,
    cari: '',
    sesi: muatSesi(),
    sesiDraf: {},
  };
  s.sesiDraf = { ...s.sesi };

  // Data peserta yang tersimpan dari kunjungan sebelumnya: pakai lagi tanpa upload ulang.
  const lama = ambilPeserta();
  if (lama) {
    Object.assign(s, {
      namaFile: lama.namaFile,
      peserta: lama.peserta,
      asli: lama.asli ?? lama.peserta,
      peringatan: lama.peringatan ?? [],
      sheet: lama.sheet ?? [],
    });
  }

  /** Catat peserta untuk tab lain (Kelas Moving, User Moodle) dan simpan di browser. */
  function catatSemua(): void {
    catatPeserta({ namaFile: s.namaFile, peserta: s.peserta, asli: s.asli, peringatan: s.peringatan, sheet: s.sheet });
  }

  saatHasilMapelBerubah(() => {
    if (s.peserta.some((p) => p.course.length)) gambar();
  });

  /** Teks status selama file dibaca; '' = tidak sedang membaca. */
  let memuat = '';

  async function pilihFile(f: File): Promise<void> {
    Object.assign(s, { namaFile: f.name, asli: [], peserta: [], peringatan: [], galat: [], sheet: [], angkatan: SEMUA, kelas: SEMUA, tampilkanPassword: false, cari: '' });
    memuat = `Membaca ${f.name}…`;
    gambar();
    try {
      const hasil = await bacaUser(await f.arrayBuffer(), f.name);
      s.asli = hasil.peserta;
      s.peserta = isiPassword(hasil.peserta);
      s.peringatan = hasil.peringatan;
      s.sheet = hasil.sheet;
      catatSemua();
    } catch (e) {
      const pesan = e instanceof GalatFormat ? e.message : `Terjadi kesalahan tak terduga: ${String(e)}`;
      s.galat = [{ tingkat: 'galat', pesan }];
      catatPeserta(null);
    }
    memuat = '';
    gambar();
  }

  function terpilih(): PesertaMasuk[] {
    return s.peserta.filter((p) => (!s.angkatan || tingkatPeserta(p) === s.angkatan) && (!s.kelas || p.department.trim() === s.kelas));
  }

  /** Nilai kolom KELAS yang ada di angkatan terpilih, urut alami (XI-2 sebelum XI-10). */
  function daftarKelas(): string[] {
    const dalam = s.peserta.filter((p) => !s.angkatan || tingkatPeserta(p) === s.angkatan);
    return [...new Set(dalam.map((p) => p.department.trim()).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'id', { numeric: true }));
  }

  /** Sesi tersimpan dan isian draf sama (tingkat yang tidak diisi = 1). */
  function sesiTersimpan(): boolean {
    const kunci = new Set([...Object.keys(s.sesi), ...Object.keys(s.sesiDraf)]);
    return [...kunci].every((t) => (s.sesi[t] ?? 1) === (s.sesiDraf[t] ?? 1));
  }

  function kartuUpload(): HTMLElement {
    const input = el('input', { type: 'file', accept: '.xlsx,.csv', id: 'file-user', class: 'sr-only' });
    input.addEventListener('change', () => {
      const f = input.files?.[0];
      if (f) void pilihFile(f);
      input.value = '';
    });
    const hapusTersimpan = el('button', { class: 'tombol', type: 'button', id: 'hapus-peserta-tersimpan' }, 'Hapus data peserta tersimpan');
    hapusTersimpan.addEventListener('click', () => {
      if (!confirm('Hapus data peserta (termasuk password) yang tersimpan di browser ini? Tab Kelas Moving dan User Moodle perlu upload ulang.')) return;
      Object.assign(s, { namaFile: '', asli: [], peserta: [], peringatan: [], galat: [], sheet: [], angkatan: SEMUA, kelas: SEMUA, cari: '' });
      catatPeserta(null);
      gambar();
    });
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '1'), 'Upload data peserta'),
      el(
        'div',
        { class: 'baris-kontrol' },
        el('label', { class: 'tombol', for: 'file-user' }, s.namaFile ? 'Ganti file…' : 'Pilih file peserta (.xlsx)…'),
        input,
        s.namaFile ? el('span', {}, s.namaFile) : null,
        s.namaFile ? hapusTersimpan : null,
      ),
      s.namaFile
        ? el('p', { class: 'bantu' }, 'Data peserta disimpan di browser ini, jadi tab Kelas Moving dan User Moodle tetap bisa memakainya setelah halaman dimuat ulang. Di komputer bersama, tekan "Hapus data peserta tersimpan" setelah selesai.')
        : null,
      el(
        'p',
        { class: 'bantu' },
        'Format seperti "DATA PESERTA NO UJIAN": satu sheet per kelas dengan kolom UJIAN, NAMA SISWA, KELAS, RG. (File lama seperti USER MOODLE.csv juga masih bisa.)',
      ),
      el(
        'p',
        { class: 'bantu' },
        'Hasil: Nama Rombel dan Kelas / Jurusan = KELAS · Ruang = RG. · Kel. Sesi = sesuai pengaturan sesi per tingkat · Username = nomor UJIAN · Password = acak, mis. HJ86KL.',
      ),
      memuat ? el('p', { class: 'memuat', role: 'status' }, memuat) : null,
      daftarPesan(s.galat),
    );
  }

  function kartuSesi(langkah: string): HTMLElement {
    const tingkat = urutTingkat([...new Set(s.peserta.map(tingkatPeserta).filter(Boolean))]);
    const tanpaTingkat = s.peserta.filter((p) => !tingkatPeserta(p)).length;
    const simpan = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-simpan-sesi' }, 'Simpan');
    const batal = el('button', { class: 'tombol', type: 'button', id: 'tombol-batal-sesi' }, 'Batal');
    const status = el('span', { class: 'bantu', role: 'status' });
    const isian: { t: string; input: HTMLInputElement; galat: HTMLElement }[] = [];

    // Diperbarui di tempat (tanpa menggambar ulang kartu): kalau kartu digambar ulang saat kotak
    // isian kehilangan fokus, klik pertama pada tombol Simpan hilang karena tombolnya sudah diganti.
    const segarkan = () => {
      const tersimpan = sesiTersimpan();
      simpan.disabled = tersimpan;
      batal.hidden = tersimpan;
      status.textContent = tersimpan ? 'Tersimpan.' : 'Ada perubahan yang belum disimpan.';
      for (const { t, input } of isian) input.classList.toggle('diedit', (s.sesi[t] ?? 1) !== (s.sesiDraf[t] ?? 1));
      for (const id of ['tombol-unduh-user', 'tombol-unduh-user-csv']) {
        const b = document.getElementById(id) as HTMLButtonElement | null;
        if (b) b.disabled = !tersimpan || terpilih().length === 0;
      }
      const peringatan = document.getElementById('peringatan-sesi');
      if (peringatan) peringatan.hidden = tersimpan;
    };

    /** Membaca semua kotak isian ke draf; false jika ada isian yang tidak sah. */
    const bacaIsian = (): boolean => {
      let sah = true;
      const draf: SesiPerTingkat = {};
      for (const { t, input, galat } of isian) {
        const n = nomorSesi(input.value);
        galat.textContent = n === null ? 'Isi angka bulat 1 atau lebih.' : '';
        if (n === null) sah = false;
        else if (n !== 1) draf[t] = n; // 1 = bawaan
      }
      if (sah) s.sesiDraf = draf;
      return sah;
    };

    const simpanSekarang = () => {
      if (!bacaIsian()) {
        status.textContent = 'Perbaiki isian yang salah dulu.';
        return;
      }
      s.sesi = { ...s.sesiDraf };
      simpanSesi(s.sesi);
      gambar();
      document.getElementById('kartu-user')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    const baris = tingkat.map((t) => {
      const input = el('input', { type: 'number', id: `sesi-${t}`, min: '1', step: '1', class: 'sel-edit angka', value: String(s.sesiDraf[t] ?? 1), 'aria-label': `Kel. Sesi kelas ${t}` });
      const galat = el('span', { class: 'bantu', role: 'alert' });
      isian.push({ t, input, galat });
      input.addEventListener('input', () => {
        bacaIsian();
        segarkan();
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') simpanSekarang();
      });
      const jumlah = s.peserta.filter((p) => tingkatPeserta(p) === t).length;
      return { sel: [`Kelas ${t}`, jumlah, el('span', { class: 'baris-kontrol' }, input, galat)] };
    });

    simpan.addEventListener('click', simpanSekarang);
    batal.addEventListener('click', () => {
      s.sesiDraf = { ...s.sesi };
      gambar();
    });
    // Status awal tombol & teks (elemen pratinjau belum ada saat ini; disetel lagi oleh kartuPratinjau)
    const tersimpan = sesiTersimpan();
    simpan.disabled = tersimpan;
    batal.hidden = tersimpan;
    status.textContent = tersimpan ? 'Tersimpan.' : 'Ada perubahan yang belum disimpan.';
    for (const { t, input } of isian) input.classList.toggle('diedit', (s.sesi[t] ?? 1) !== (s.sesiDraf[t] ?? 1));

    return el(
      'div',
      { class: 'kartu', id: 'kartu-sesi' },
      el('h2', {}, el('span', { class: 'langkah' }, langkah), 'Pengaturan sesi'),
      el('p', { class: 'bantu' }, 'Isi nomor Kel. Sesi untuk setiap tingkat, lalu klik Simpan (atau tekan Enter). Pratinjau dan file unduhan memakai sesi yang sudah disimpan; pengaturan diingat di browser ini.'),
      tabel([{ judul: 'Tingkat' }, { judul: 'Peserta', angka: true }, { judul: 'Kel. Sesi' }], baris, 'Kel. Sesi per tingkat'),
      el('div', { class: 'baris-kontrol' }, simpan, batal, status),
      tanpaTingkat ? el('p', { class: 'bantu' }, `${tanpaTingkat} peserta tidak diketahui tingkatnya, jadi memakai sesi 1.`) : null,
    );
  }

  function kartuKodeMapel(): HTMLElement | null {
    const peserta = terpilih();
    if (!peserta.some((p) => p.course.length)) return null; // hanya untuk file format lama yang punya course1..14
    const hasilMapel = ambilHasilMapel();
    if (hasilMapel.size === 0) {
      return el(
        'div',
        { class: 'kartu' },
        el('h2', {}, 'Cek kode mapel'),
        el('p', { class: 'kosong' }, 'Konvert jadwal di tab "Jadwal → Mapel" untuk memeriksa apakah kode course1–course14 peserta cocok dengan kode mapel.'),
      );
    }
    const { diperiksa, tidakCocok } = kodeTidakCocok(peserta, hasilMapel);
    let isi: Node;
    if (diperiksa.length === 0) {
      isi = el('p', { class: 'kosong' }, `Belum ada tingkat yang cocok untuk diperiksa. Mapel yang sudah dikonvert: kelas ${[...hasilMapel.keys()].join(', ')}.`);
    } else if (tidakCocok.length === 0) {
      isi = daftarPesan([{ tingkat: 'info', pesan: `Semua kode mapel peserta kelas ${diperiksa.join(', ')} ada di hasil konversi jadwal.` }])!;
    } else {
      isi = el(
        'div',
        {},
        daftarPesan([
          {
            tingkat: 'peringatan',
            pesan: `${tidakCocok.length} kode mapel di file peserta tidak ada di hasil konversi jadwal. Peserta dengan kode ini tidak akan masuk ke mapelnya.`,
          },
        ])!,
        tabel(
          [{ judul: 'Kode di file peserta' }, { judul: 'Jumlah peserta', angka: true }],
          tidakCocok.map((t) => ({ tanda: 'kuning' as const, sel: [t.kode, t.jumlahPeserta] })),
          'Kode mapel yang tidak cocok',
        ),
      );
    }
    return el('div', { class: 'kartu' }, el('h2', {}, 'Cek kode mapel'), isi);
  }

  function kartuPratinjau(cbt: PesertaCBT[]): HTMLElement {
    const wadahTabel = el('div', { id: 'hasil-cari-user' });
    // Nomor = urutan di file unduhan, jadi tetap sama saat pratinjau disaring dengan pencarian.
    const nomor = new Map(cbt.map((p, i) => [p, i + 1]));
    const isiTabel = () => {
      const cocok = cariPeserta(cbt, s.cari);
      const tampil = cocok.slice(0, BATAS_PRATINJAU);
      const jumlah = s.cari.trim()
        ? `${cocok.length} dari ${cbt.length} peserta cocok dengan "${s.cari.trim()}".`
        : `${cbt.length} peserta.`;
      wadahTabel.replaceChildren(
        ...[
          cocok.length
            ? tabel(
                ['No.', 'Nama Peserta', 'Nama Rombel', 'Kelas / Jurusan', 'Ruang', 'Kel. Sesi', 'Username', 'Password'].map((judul) => ({
                  judul,
                  angka: judul === 'No.' || judul === 'Ruang' || judul === 'Kel. Sesi',
                })),
                tampil.map((p) => ({
                  sel: [nomor.get(p) ?? null, p.namaPeserta, p.namaRombel, p.kelasJurusan, p.ruang, p.kelSesi, p.username, s.tampilkanPassword ? p.password : p.password ? '••••••' : ''],
                })),
                'Pratinjau data peserta untuk CBTAdmin',
              )
            : el('p', { class: 'kosong' }, 'Tidak ada peserta yang cocok.'),
          el(
            'p',
            { class: 'jumlah', role: 'status' },
            jumlah +
              (cocok.length > BATAS_PRATINJAU ? ` Pratinjau menampilkan ${BATAS_PRATINJAU} baris pertama.` : '') +
              (s.cari.trim() ? ' Pencarian hanya menyaring pratinjau; file unduhan tetap berisi semua peserta.' : cbt.length > BATAS_PRATINJAU ? ' File unduhan berisi semuanya.' : ''),
          ),
        ],
      );
    };
    isiTabel();

    const cari = el('input', {
      type: 'search',
      id: 'cari-user',
      placeholder: 'Cari nama, no ujian, atau kelas…',
      size: '34',
      autocomplete: 'off',
    });
    cari.value = s.cari;
    cari.addEventListener('input', () => {
      s.cari = cari.value;
      isiTabel();
    });
    const bersihkan = el('button', { class: 'tombol', type: 'button', hidden: !s.cari }, 'Hapus pencarian');
    bersihkan.addEventListener('click', () => {
      s.cari = cari.value = '';
      bersihkan.hidden = true;
      isiTabel();
      cari.focus();
    });
    cari.addEventListener('input', () => (bersihkan.hidden = !cari.value));
    const tombolPassword = el('button', { class: 'tombol', type: 'button', 'aria-pressed': String(s.tampilkanPassword) }, s.tampilkanPassword ? 'Sembunyikan password' : 'Tampilkan password');
    tombolPassword.addEventListener('click', () => {
      s.tampilkanPassword = !s.tampilkanPassword;
      gambar();
    });
    const adaYangDibuat = s.asli.some((p) => !p.password);
    const ulang = adaYangDibuat ? el('button', { class: 'tombol', type: 'button', id: 'tombol-password-ulang' }, 'Buat ulang password') : null;
    ulang?.addEventListener('click', () => {
      if (!confirm('Buat ulang semua password acak? Password yang sedang tampil akan diganti.')) return;
      s.peserta = isiPassword(s.asli);
      catatSemua();
      gambar();
    });

    const namaFile = `USER CBTADMIN${s.angkatan ? ` ${s.angkatan}` : ''}${s.kelas ? ` ${s.kelas}` : ''} ${tanggalHariIni()}.xlsx`;
    const sesiBelum = !sesiTersimpan();
    const unduh = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-unduh-user', disabled: sesiBelum || cbt.length === 0 }, `Unduh ${namaFile}`);
    const galatUnduh = el('span', { class: 'bantu', role: 'alert' });
    unduh.addEventListener('click', async () => {
      unduh.disabled = true;
      galatUnduh.textContent = '';
      try {
        unduhFile(namaFile, await tulisCbtXlsx(cbt, await muatTemplate()));
      } catch (e) {
        galatUnduh.textContent = `Gagal membuat file: ${e instanceof Error ? e.message : String(e)}`;
      } finally {
        unduh.disabled = false;
      }
    });

    const namaCsv = namaFile.replace(/\.xlsx$/, '.csv');
    const unduhCsvTombol = el('button', { class: 'tombol', type: 'button', id: 'tombol-unduh-user-csv', disabled: unduh.disabled }, `Unduh ${namaCsv}`);
    unduhCsvTombol.addEventListener('click', () => unduhCsv(namaCsv, tulisCbtCsv(cbt)));

    // Filter angkatan & kelas: berlaku untuk pratinjau dan file unduhan
    const tingkatAda = urutTingkat([...new Set(s.peserta.map(tingkatPeserta).filter(Boolean))]);
    const jumlahAngkatan = (t: string) => s.peserta.filter((p) => tingkatPeserta(p) === t).length;
    const pilihAngkatan = el(
      'select',
      { id: 'filter-angkatan' },
      el('option', { value: SEMUA, selected: !s.angkatan }, `Semua (${s.peserta.length})`),
      ...tingkatAda.map((t) => el('option', { value: t, selected: t === s.angkatan }, `Kelas ${t} (${jumlahAngkatan(t)})`)),
    );
    pilihAngkatan.addEventListener('change', () => {
      s.angkatan = pilihAngkatan.value;
      if (s.kelas && !daftarKelas().includes(s.kelas)) s.kelas = SEMUA;
      gambar();
    });
    const pilihKelas = el(
      'select',
      { id: 'filter-kelas' },
      el('option', { value: SEMUA, selected: !s.kelas }, 'Semua kelas'),
      ...daftarKelas().map((k) => el('option', { value: k, selected: k === s.kelas }, k)),
    );
    pilihKelas.addEventListener('change', () => {
      s.kelas = pilihKelas.value;
      gambar();
    });
    const tingkatLokasi = (lokasi = '') => tingkatDariSheet(lokasi.replace(/ baris \d+$/, ''));

    const langkah = '3';
    return el(
      'div',
      { class: 'kartu', id: 'kartu-user' },
      el('h2', {}, el('span', { class: 'langkah' }, langkah), 'Pratinjau & unduh'),
      daftarPesan(
        ringkasPesan(
          s.peringatan.filter((p) => !s.angkatan || tingkatLokasi(p.lokasi) === s.angkatan),
        ),
      ),
      el(
        'div',
        { class: 'baris-kontrol', role: 'group', 'aria-label': 'Filter peserta' },
        el('label', { for: 'filter-angkatan' }, 'Angkatan:'),
        pilihAngkatan,
        el('label', { for: 'filter-kelas' }, 'Kelas:'),
        pilihKelas,
      ),
      s.angkatan || s.kelas ? el('p', { class: 'bantu' }, `Filter berlaku juga untuk file unduhan: hanya ${cbt.length} peserta yang dipilih ikut diunduh.`) : null,
      el(
        'div',
        { class: 'baris-kontrol', role: 'search' },
        el('label', { for: 'cari-user' }, 'Cari peserta:'),
        cari,
        bersihkan,
      ),
      el('div', { class: 'baris-kontrol' }, tombolPassword, ulang),
      adaYangDibuat
        ? el('p', { class: 'bantu' }, 'Password dibuat acak saat file di-upload. Simpan file hasil unduhan: password yang sama tidak bisa dibuat lagi.')
        : null,
      wadahTabel,
      el('div', { id: 'peringatan-sesi', hidden: !sesiBelum }, daftarPesan([{ tingkat: 'peringatan', pesan: 'Pengaturan sesi belum disimpan. Klik Simpan di Pengaturan sesi dulu sebelum mengunduh.' }])),
      el('div', { class: 'baris-kontrol' }, unduh, unduhCsvTombol, galatUnduh),
      el('p', { class: 'bantu' }, 'File CSV berisi baris judul kolom lalu data peserta, tanpa baris petunjuk template.'),
    );
  }

  function gambar(): void {
    const fokus = document.activeElement?.id;
    const ada = s.peserta.length > 0;
    akar.replaceChildren(
      kartuUpload(),
      ...(ada ? [kartuSesi('2'), kartuKodeMapel(), kartuPratinjau(keCbt(terpilih(), s.sesi))] : []).filter(
        (x): x is HTMLElement => x !== null,
      ),
    );
    if (fokus) document.getElementById(fokus)?.focus();
  }

  gambar();
}
