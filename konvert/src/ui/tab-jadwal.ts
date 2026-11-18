// Tab "Jadwal" (jadwal → mapel): upload jadwal → pilih sheet & tingkat → Konvert → pratinjau → unduh.
// Perilaku sesuai specs/001-konverter-cbt/contracts/ui.md

import { bacaJadwal, daftarSheet, urutTingkat } from '../core/jadwal';
import { cocokkanJadwal, kunciPilihan, tambahAlias, tambahMapel } from '../core/kamus';
import { periksaEditMapel, susunBarisMapel, susunBarisMapelSemua, terapkanEditMapel, tulisMapelCsv, tulisMapelXlsx, ubahSelMapel, urutGabung, type BarisDiedit, type EditMapel, type KolomMapel } from '../core/mapel';
import { formatTanggal } from '../core/tanggal';
import { GalatFormat, type HasilCocok, type JadwalUjian, type Kamus, type Peringatan, type PilihanMapel } from '../core/tipe';
import { catatHasilMapel, muatEditMapel, muatKamus, muatPilihan, muatUrutanSesi, saatKamusBerubah, saatSesiBerubah, simpanEditMapel, simpanKamus, simpanPilihan, simpanUrutanSesi, unduhCsv, unduhFile } from './simpan';
import { alamatKategori, kirimMapel, panelTersedia } from './panel';
import { daftarPesan, el, tabel, type BarisTabel } from './tabel';

interface Status {
  file: ArrayBuffer | null;
  namaFile: string;
  sheet: string[];
  sheetDipilih: string;
  jadwal: JadwalUjian | null;
  galat: Peringatan[];
  tingkat: string;
  /** 'satu' = kelas yang dipilih saja; 'semua' = semua kelas dalam satu file. */
  mode: 'satu' | 'semua';
  sudahKonvert: boolean;
  kamus: Kamus;
  /** Per tingkat. */
  pilihan: Record<string, PilihanMapel>;
  /** Isi sel pratinjau yang diubah operator (kunci = shortname asli), per sheet. */
  edit: EditMapel;
  /** Tingkat per sesi (indeks 0 = sesi 1): urutan mapel di setiap hari untuk "semua kelas". */
  urutanSesi: string[];
}

const PILIH_BARU = '__baru__';
const PILIH_ABAIKAN = '__abaikan__';

export function pasangTabJadwal(akar: HTMLElement): void {
  const s: Status = {
    file: null,
    namaFile: '',
    sheet: [],
    sheetDipilih: '',
    jadwal: null,
    galat: [],
    tingkat: '',
    mode: 'semua',
    sudahKonvert: false,
    kamus: muatKamus(),
    pilihan: {},
    edit: {},
    urutanSesi: muatUrutanSesi(),
  };

  saatKamusBerubah((k) => {
    s.kamus = k;
    gambar();
  });
  // Pengaturan sesi dipakai bersama tab User → CBTAdmin (Kel. Sesi).
  saatSesiBerubah(() => {
    s.urutanSesi = muatUrutanSesi();
    gambar();
  });

  function pesanGalat(e: unknown): Peringatan[] {
    const pesan = e instanceof GalatFormat ? e.message : `Terjadi kesalahan tak terduga: ${String(e)}`;
    return [{ tingkat: 'galat', pesan }];
  }

  /** Teks status selama file dibaca; '' = tidak sedang membaca. */
  let memuat = '';

  async function pilihFile(f: File): Promise<void> {
    Object.assign(s, { file: null, namaFile: f.name, sheet: [], sheetDipilih: '', jadwal: null, galat: [], tingkat: '', sudahKonvert: false });
    memuat = `Membaca ${f.name}…`;
    gambar();
    try {
      s.file = await f.arrayBuffer();
      s.sheet = await daftarSheet(s.file);
      await bacaSheet(s.sheet[0]);
    } catch (e) {
      s.galat = pesanGalat(e);
    }
    memuat = '';
    gambar();
  }

  async function bacaSheet(nama: string): Promise<void> {
    s.sheetDipilih = nama;
    s.jadwal = null;
    s.galat = [];
    s.sudahKonvert = false;
    try {
      s.jadwal = await bacaJadwal(s.file!, nama);
      s.pilihan = muatPilihan(nama);
      s.edit = muatEditMapel(nama);
      if (!s.jadwal.tingkat.includes(s.tingkat)) s.tingkat = urutTingkat(s.jadwal.tingkat)[0];
    } catch (e) {
      s.galat = pesanGalat(e);
    }
    gambar();
  }

  function aturPilihan(tingkat: string, lokasi: string, teks: string, kode: string[] | null): void {
    const p = { ...(s.pilihan[tingkat] ?? {}) };
    const k = kunciPilihan(lokasi, teks);
    if (kode === null) delete p[k];
    else p[k] = kode;
    s.pilihan = { ...s.pilihan, [tingkat]: p };
    simpanPilihan(s.sheetDipilih, s.pilihan);
    gambar();
  }

  function gantiKamus(f: () => Kamus): string | null {
    try {
      simpanKamus(f()); // memicu gambar() lewat saatKamusBerubah
      return null;
    } catch (e) {
      return e instanceof GalatFormat ? e.message : String(e);
    }
  }

  // ---------- tampilan ----------

  function kartuUpload(): HTMLElement {
    const input = el('input', { type: 'file', accept: '.xlsx', id: 'file-jadwal', class: 'sr-only' });
    input.addEventListener('change', () => {
      const f = input.files?.[0];
      if (f) void pilihFile(f);
      input.value = '';
    });
    const kontrol = el(
      'div',
      { class: 'baris-kontrol' },
      el('label', { class: 'tombol', for: 'file-jadwal' }, s.namaFile ? 'Ganti file…' : 'Pilih file jadwal (.xlsx)…'),
      input,
      s.namaFile ? el('span', {}, s.namaFile) : null,
    );
    let pilihSheet: HTMLElement | null = null;
    if (s.sheet.length > 1) {
      const sel = el('select', { id: 'pilih-sheet' }, ...s.sheet.map((n) => el('option', { value: n, selected: n === s.sheetDipilih }, n)));
      sel.addEventListener('change', () => void bacaSheet(sel.value));
      pilihSheet = el('div', { class: 'baris-kontrol' }, el('label', { for: 'pilih-sheet' }, 'Sheet:'), sel);
    }
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '1'), 'Upload jadwal'),
      kontrol,
      pilihSheet,
      el('p', { class: 'bantu' }, 'Format seperti jadwal.xlsx: header HARI/TGL, JAM, WAKTU, KELAS XII, KELAS X, KELAS XI.'),
      memuat ? el('p', { class: 'memuat', role: 'status' }, memuat) : null,
      daftarPesan(s.galat),
    );
  }

  function kartuJadwal(j: JadwalUjian): HTMLElement {
    const tingkat = urutTingkat(j.tingkat);
    const kolom = [{ judul: 'Hari' }, { judul: 'Tanggal' }, { judul: 'Sesi' }, ...tingkat.map((t) => ({ judul: `Kelas ${t}` }))];
    const baris: BarisTabel[] = [];
    for (const h of j.hari) {
      h.sesi.forEach((ses, i) => {
        baris.push({
          tanda: h.susulan ? 'abu' : undefined,
          sel: [
            i === 0 ? h.namaHari || '-' : '',
            i === 0 ? formatTanggal(h.tanggal) : '',
            h.susulan ? 'Susulan' : ses.nomor,
            ...tingkat.map((t) => (ses.mapelPerTingkat[t] ?? []).join(', ')),
          ],
        });
      });
    }
    const jumlahHari = j.hari.filter((h) => !h.susulan).length;
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '2'), 'Jadwal terbaca'),
      daftarPesan(j.peringatan),
      tabel(kolom, baris, 'Jadwal ujian yang terbaca dari file'),
      el('p', { class: 'jumlah' }, `${jumlahHari} hari ujian${j.hari.some((h) => h.susulan) ? ' + hari susulan (tidak dikonvert)' : ''}.`),
    );
  }

  function kartuKonvert(j: JadwalUjian): HTMLElement {
    // Selalu semua kelas sekaligus; urutan mapel di setiap hari mengikuti penetapan sesi.
    const urutan = urutGabung(j.tingkat, s.urutanSesi);
    // Penetapan sesi: Sesi 1..n memilih tingkat; menukar tingkat dengan sesi lain supaya tiap tingkat tetap sekali.
    const pilihSesi = s.urutanSesi.map((t, i) => {
      const id = `sesi-${i + 1}`;
      const sel = el('select', { id }, ...s.urutanSesi.map((x) => el('option', { value: x, selected: x === t }, `Kelas ${x}`)));
      sel.addEventListener('change', () => {
        const baru = [...s.urutanSesi];
        const lama = baru.indexOf(sel.value);
        [baru[i], baru[lama]] = [sel.value, baru[i]];
        s.urutanSesi = baru;
        simpanUrutanSesi(baru);
        gambar();
      });
      return el('span', { class: 'baris-kontrol' }, el('label', { for: id }, `Sesi ${i + 1}`), sel);
    });
    const konvert = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-konvert', 'aria-pressed': String(s.sudahKonvert) }, 'Konvert');
    konvert.addEventListener('click', () => {
      s.mode = 'semua';
      s.sudahKonvert = true;
      gambar();
      document.getElementById('kartu-hasil')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '3'), 'Atur sesi lalu konvert'),
      el('p', { class: 'bantu' }, 'Pilih kelas di setiap sesi (sama dengan Pengaturan sesi di Konvert User). Mapel di setiap hari diurutkan menurut sesi.'),
      el('div', { class: 'baris-kontrol', role: 'group', 'aria-label': 'Penetapan sesi' }, ...pilihSesi),
      el('div', { class: 'baris-kontrol' }, konvert),
      el('p', { class: 'bantu' }, `Satu file berisi kelas ${urutan.join(', ')}. category = hari ujian ke-N; di setiap category, urutan mapel ${urutan.map((x, i) => `sesi ${i + 1} kelas ${x}`).join(' → ')}. Urutan yang sama dipakai di Moodle.`),
    );
  }

  function pilihanMapel(id: string, tambahan: HTMLOptionElement[]): HTMLSelectElement {
    return el(
      'select',
      { id },
      el('option', { value: '' }, 'Pilih mapel…'),
      ...tambahan,
      ...[...s.kamus.mapel]
        .sort((a, b) => a.kode.localeCompare(b.kode))
        .map((m) => el('option', { value: m.kode }, `${m.kode}: ${m.namaLengkap}`)),
    );
  }

  function tindakanTidakDikenal(h: HasilCocok, i: number): HTMLElement {
    const id = `perbaiki-${i}`;
    const sel = pilihanMapel(id, [el('option', { value: PILIH_BARU }, '＋ Mapel baru…')]);
    const kode = el('input', { type: 'text', placeholder: 'Kode, mis. KKA', size: '12', 'aria-label': 'Kode mapel baru' });
    const nama = el('input', { type: 'text', placeholder: 'Nama lengkap', size: '22', 'aria-label': 'Nama lengkap mapel baru' });
    const baru = el('span', { class: 'baris-kontrol', hidden: true }, kode, nama);
    const galat = el('span', { class: 'bantu', role: 'alert' });
    const simpan = el('button', { class: 'tombol', type: 'button' }, 'Simpan ke kamus');
    sel.addEventListener('change', () => {
      baru.hidden = sel.value !== PILIH_BARU;
      if (!baru.hidden && !kode.value) {
        kode.value = h.teksAsli.toUpperCase();
        nama.value = h.teksAsli;
      }
    });
    simpan.addEventListener('click', () => {
      if (!sel.value) {
        galat.textContent = 'Pilih mapel dulu.';
        return;
      }
      const g =
        sel.value === PILIH_BARU
          ? gantiKamus(() => tambahMapel(s.kamus, kode.value, nama.value, h.teksAsli))
          : gantiKamus(() => tambahAlias(s.kamus, h.teksAsli, sel.value));
      if (g) galat.textContent = g;
    });
    return el('div', { class: 'baris-kontrol' }, el('label', { class: 'sr-only', for: id }, `Mapel untuk ${h.teksAsli}`), sel, baru, simpan, galat);
  }

  function tindakanDuplikat(h: HasilCocok, tingkat: string, i: number): HTMLElement {
    const id = `ganda-${i}`;
    const sel = pilihanMapel(id, [el('option', { value: PILIH_ABAIKAN }, 'Abaikan (sudah ada)')]);
    const terapkan = el('button', { class: 'tombol', type: 'button' }, 'Terapkan');
    terapkan.addEventListener('click', () => {
      if (!sel.value) return;
      aturPilihan(tingkat, h.lokasi, h.teksAsli, sel.value === PILIH_ABAIKAN ? [] : [sel.value]);
    });
    return el('div', { class: 'baris-kontrol' }, el('label', { class: 'sr-only', for: id }, `Ganti ${h.teksAsli} dengan`), sel, terapkan);
  }

  function tindakanDipilih(h: HasilCocok, tingkat: string): HTMLElement {
    const batal = el('button', { class: 'tombol', type: 'button' }, 'Batalkan pilihan');
    batal.addEventListener('click', () => aturPilihan(tingkat, h.lokasi, h.teksAsli, null));
    return el('div', { class: 'baris-kontrol' }, el('span', {}, h.kode.length ? `Dipilih: ${h.kode.join(', ')}` : 'Diabaikan'), batal);
  }

  function kartuHasil(j: JadwalUjian): HTMLElement {
    const semuaKelas = s.mode === 'semua';
    const daftar = semuaKelas ? urutGabung(j.tingkat, s.urutanSesi) : [s.tingkat];
    const per = daftar.map((t) => ({ tingkat: t, hasil: cocokkanJadwal(j, t, s.kamus, s.pilihan[t] ?? {}) }));
    for (const p of per) {
      const lengkap = !p.hasil.some((h) => h.status === 'tidak-dikenal');
      // shortname yang diedit di pratinjau juga dipakai sebagai kode course di tab User → Moodle
      catatHasilMapel(p.tingkat, susunBarisMapel(p.hasil, p.tingkat, s.kamus).map((b) => s.edit[b.shortname]?.shortname ?? b.shortname), lengkap);
    }
    const diedit = terapkanEditMapel(semuaKelas ? susunBarisMapelSemua(per, s.kamus, s.urutanSesi) : susunBarisMapel(per[0].hasil, s.tingkat, s.kamus), s.edit);
    const baris = diedit.map((d) => d.hasil);
    const masalahEdit = periksaEditMapel(baris);
    const jumlahDiedit = diedit.filter((d) => s.edit[d.asli.shortname]).length;
    const hasil = per.flatMap((p) => p.hasil.map((h) => ({ ...h, tingkat: p.tingkat })));
    const tidakDikenal = hasil.filter((h) => h.status === 'tidak-dikenal');
    const duplikat = hasil.filter((h) => h.status === 'duplikat');
    const masalah = hasil.filter((h) => h.status !== 'cocok' || h.dipilih);
    const label = semuaKelas ? `semua kelas (${daftar.join(', ')})` : `kelas ${s.tingkat}`;
    const namaFile = semuaKelas ? 'MAPEL SEMUA KELAS.xlsx' : `MAPEL ${s.tingkat}.xlsx`;

    const pesan: Peringatan[] = [];
    if (tidakDikenal.length) {
      pesan.push({ tingkat: 'galat', pesan: `${tidakDikenal.length} nama mapel belum ada di kamus. Pilih mapelnya di tabel merah sebelum mengunduh.` });
    }
    for (const m of masalahEdit) pesan.push({ tingkat: 'galat', pesan: `Hasil edit belum benar: ${m}.` });
    if (duplikat.length) {
      pesan.push({
        tingkat: 'peringatan',
        pesan: `${duplikat.length} mapel muncul lebih dari sekali dan dilewati. Periksa baris kuning: mungkin maksudnya mapel lanjutan.`,
      });
    }

    const tabelMasalah = masalah.length
      ? tabel(
          [{ judul: 'Hari ke', angka: true }, { judul: 'Kelas' }, { judul: 'Sel' }, { judul: 'Nama di jadwal' }, { judul: 'Masalah' }, { judul: 'Tindakan' }],
          masalah.map((h, i) => ({
            tanda: h.status === 'tidak-dikenal' ? 'merah' : h.status === 'duplikat' ? 'kuning' : undefined,
            sel: [
              h.hariKe,
              h.tingkat,
              h.lokasi,
              h.teksAsli,
              h.status === 'tidak-dikenal' ? 'Belum ada di kamus' : h.status === 'duplikat' ? `Sama dengan ${h.kode.join(', ')} di hari lain` : 'Sudah dipilih',
              h.status === 'tidak-dikenal' ? tindakanTidakDikenal(h, i) : h.status === 'duplikat' ? tindakanDuplikat(h, h.tingkat, i) : tindakanDipilih(h, h.tingkat),
            ],
          })),
          'Nama mapel yang perlu diperiksa',
        )
      : null;

    const unduh = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-unduh', disabled: tidakDikenal.length > 0 || masalahEdit.length > 0 || baris.length === 0 }, `Unduh ${namaFile}`);
    unduh.addEventListener('click', async () => {
      unduh.disabled = true;
      try {
        unduhFile(namaFile, await tulisMapelXlsx(baris));
      } finally {
        unduh.disabled = false;
      }
    });

    const namaCsv = namaFile.replace(/\.xlsx$/, '.csv');
    const unduhCsvTombol = el('button', { class: 'tombol', type: 'button', id: 'tombol-unduh-csv', disabled: unduh.disabled }, `Unduh ${namaCsv}`);
    unduhCsvTombol.addEventListener('click', () => unduhCsv(namaCsv, tulisMapelCsv(baris)));

    // Di dalam panel CBT Admin: buat mapel langsung di Moodle. Hari N masuk ke kategori bernama H<N> (bukan id),
    // (Tools::mapelJson); satu-satunya jalan membuat mapel dari jadwal.
    let kirimMoodle: HTMLElement | null = null;
    if (panelTersedia()) {
      const jumlahHari = new Set(baris.map((b) => b.category)).size;
      const hasilKirim = el('div', { 'aria-live': 'polite' });
      const tombol = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-moodle', disabled: unduh.disabled }, `Buat ${baris.length} mapel di Moodle`);
      tombol.addEventListener('click', async () => {
        const hari = [...new Set(baris.map((b) => b.category))].sort((a, b) => a - b);
        if (!confirm(`Buat ${baris.length} mapel di Moodle?\nHari ${hari.join(', ')} masuk ke kategori ${hari.map((h) => 'H' + h).join(', ')}.\nMapel yang shortname-nya sudah ada dilewati.`)) return;
        tombol.disabled = true;
        tombol.textContent = 'Membuat mapel di Moodle…';
        hasilKirim.replaceChildren();
        try {
          const h = await kirimMapel(baris);
          const tautan = alamatKategori();
          const isi: Node[] = [daftarPesan([{ tingkat: h.ok ? 'info' : 'peringatan', pesan: h.pesan }])!];
          if (tautan) isi.push(el('p', { class: 'bantu' }, el('a', { href: tautan, target: '_top' }, 'Buka Kategori Mapel')));
          hasilKirim.replaceChildren(...isi);
        } catch (e) {
          hasilKirim.replaceChildren(daftarPesan([{ tingkat: 'galat', pesan: e instanceof Error ? e.message : String(e) }])!);
        } finally {
          tombol.disabled = false;
          tombol.textContent = `Buat ${baris.length} mapel di Moodle`;
        }
      });
      kirimMoodle = el(
        'div',
        {},
        el('p', { class: 'bantu' }, `Atau langsung buat di Moodle: ${jumlahHari} hari → kategori H1, H2, … (buat dulu kategorinya lewat Jumlah hari di Kategori Mapel).`),
        el('div', { class: 'baris-kontrol' }, tombol),
        hasilKirim,
      );
    }

    return el(
      'div',
      { class: 'kartu', id: 'kartu-hasil' },
      el('h2', {}, el('span', { class: 'langkah' }, '4'), `Pratinjau mapel ${label}`),
      daftarPesan(pesan),
      tabelMasalah,
      masalah.length ? el('p', {}) : null,
      el('p', { class: 'bantu' }, 'Klik sel untuk mengubah isinya. Perubahan dipakai di file unduhan dan diingat di browser ini; sel yang diubah berwarna kuning.'),
      tabel(
        // Kolom "category" berisi nomor hari (hari N → kategori H<N> di Moodle), jadi ditampilkan sebagai "Hari";
        // kolom format (selalu singleactivity) tidak perlu diubah sehingga tidak ditampilkan.
        [{ judul: 'Hari', angka: true }, { judul: 'shortname' }, { judul: 'fullname' }],
        diedit.map((d) => ({ sel: (['category', 'shortname', 'fullname'] as const).map((k) => selEdit(d, k)) })),
        `Hasil konversi mapel ${label}`,
      ),
      el('p', { class: 'jumlah' }, `${baris.length} mapel dalam ${new Set(baris.map((b) => b.category)).size} hari.${jumlahDiedit ? ` ${jumlahDiedit} baris diedit.` : ''}`),
      jumlahDiedit ? tombolKembalikan() : null,
      el('div', { class: 'baris-kontrol' }, unduh, unduhCsvTombol),
      kirimMoodle,
    );
  }

  /** Satu sel pratinjau yang bisa diedit; id dari shortname asli agar fokus bertahan setelah digambar ulang. */
  function selEdit(d: BarisDiedit, kolom: KolomMapel): HTMLElement {
    const nilai = d.hasil[kolom];
    const asli = d.asli[kolom];
    const berubah = s.edit[d.asli.shortname]?.[kolom] !== undefined;
    const input = el('input', {
      type: kolom === 'category' ? 'number' : 'text',
      min: kolom === 'category' ? '1' : undefined,
      id: `edit-${kolom}-${d.asli.shortname.replace(/[^A-Za-z0-9]/g, '_')}`,
      class: `sel-edit${berubah ? ' diedit' : ''}${kolom === 'category' ? ' angka' : ''}`,
      value: Number.isNaN(nilai) ? '' : String(nilai),
      'aria-label': `${kolom === 'category' ? 'hari' : kolom} untuk ${d.asli.shortname}`,
      title: berubah ? `Hasil konversi: ${asli}` : undefined,
    });
    input.addEventListener('change', () => {
      s.edit = ubahSelMapel(s.edit, d.asli, kolom, input.value);
      simpanEditMapel(s.sheetDipilih, s.edit);
      // gambar setelah fokus pindah ke sel berikutnya, supaya fokusnya ikut dipulihkan
      setTimeout(gambar, 0);
    });
    return input;
  }

  function tombolKembalikan(): HTMLElement {
    const b = el('button', { class: 'tombol', type: 'button' }, 'Kembalikan semua ke hasil konversi');
    b.addEventListener('click', () => {
      if (!confirm('Hapus semua perubahan di pratinjau mapel untuk sheet ini?')) return;
      s.edit = {};
      simpanEditMapel(s.sheetDipilih, s.edit);
      gambar();
    });
    return el('div', { class: 'baris-kontrol' }, b);
  }

  function gambar(): void {
    const fokus = document.activeElement?.id;
    akar.replaceChildren(
      kartuUpload(),
      ...(s.jadwal ? [kartuJadwal(s.jadwal), kartuKonvert(s.jadwal)] : []),
      ...(s.jadwal && s.sudahKonvert ? [kartuHasil(s.jadwal)] : []),
    );
    if (fokus) document.getElementById(fokus)?.focus();
  }

  gambar();
}
