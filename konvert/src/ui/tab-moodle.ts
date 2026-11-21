// Tab "User → Moodle": memakai peserta dari tab User → CBTAdmin (password yang sama) dan
// daftar mapel dari tab Jadwal, lalu menghasilkan CSV seperti USER MOODLE.csv.

import { keMoodle, pengecualianAwal, terapkanCohort, tingkatPeserta, tulisMoodleCsv, uraiDaftarShortname, type ModeCohort } from '../core/moodle';
import { urutTingkat } from '../core/jadwal';
import { keCbt } from '../core/user';
import {
  ambilHasilMapel,
  ambilMoving,
  ambilPeserta,
  mapelLengkap,
  muatCohort,
  muatPengecualian,
  saatHasilMapelBerubah,
  saatMovingBerubah,
  saatPesertaBerubah,
  simpanCohort,
  simpanPengecualian,
  unduhCsv,
} from './simpan';
import type { Peringatan } from '../core/tipe';
import { alamatDataMoodle, kirimKeMoodle, kirimMoodleTersedia } from './panel';
import { daftarPesan, el, tabel } from './tabel';

/** Pilihan jumlah baris pratinjau; 0 = semua. Bawaan 30 supaya halaman tetap ringan. */
const PILIHAN_BATAS = [30, 50, 100, 0];
const SEMUA = '';

function tanggalHariIni(): string {
  const d = new Date();
  const dua = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${dua(d.getMonth() + 1)}-${dua(d.getDate())}`;
}

/** Tiga course pertama + "+N lainnya"; daftar lengkap ada di tooltip. File CSV tetap berisi semuanya. */
function ringkasCourse(course: string[]): HTMLElement {
  const tampil = course.slice(0, 3).map((c) => c.replace(/^USBK-[IVX]+-/, '')).join(', ');
  const sisa = course.length > 3 ? ` +${course.length - 3} lainnya` : '';
  return el('span', { class: 'ringkas-course', title: course.join('\n') }, tampil + sisa);
}

/** Cohort pertama + "+N lainnya"; daftar lengkap ada di tooltip. */
function ringkasCohort(cohort: string[]): HTMLElement | string {
  if (!cohort.length) return '–';
  const sisa = cohort.length > 1 ? ` +${cohort.length - 1} lainnya` : '';
  return el('span', { class: 'ringkas-course', title: cohort.join('\n') }, cohort[0] + sisa);
}

/** Tingkatan yang punya mapel pilihan (kelas moving): pratinjau menampilkan mapel pilihannya saja. */
const TINGKAT_PILIHAN = ['XI', 'XII'];

/**
 * Kolom course di pratinjau. Kelas XI/XII: hanya mapel pilihan (dari Kelas Moving), mapel wajib cukup disebut
 * jumlahnya; kelas lain: ringkasan semua course. File CSV dan kiriman ke Moodle tetap berisi semuanya.
 */
function selCourse(course: string[], tingkat: string, moving: readonly string[]): HTMLElement {
  if (!TINGKAT_PILIHAN.includes(tingkat)) return ringkasCourse(course);
  const pilihan = course.filter((c) => moving.includes(c));
  const wajib = course.length - pilihan.length;
  const judul = course.join('\n');
  if (!pilihan.length) return el('span', { class: 'ringkas-course kosong', title: judul }, `Belum ada mapel pilihan (${wajib} wajib)`);
  return el('span', { class: 'ringkas-course', title: judul }, `${pilihan.map((c) => c.replace(/^USBK-[IVX]+-/, '')).join(', ')} (+${wajib} wajib)`);
}

function bukaTab(id: string): void {
  document.getElementById(id)?.click();
  window.scrollTo({ top: 0 });
}

export function pasangTabMoodle(akar: HTMLElement): void {
  let kecuali = muatPengecualian(pengecualianAwal());
  let cohort = muatCohort();
  let sheetDipilih = SEMUA;
  let tampilkanPassword = false;
  /** Jumlah baris pratinjau; 0 = semua (file unduhan & kiriman tetap berisi semua). */
  let batas = PILIHAN_BATAS[0];

  saatPesertaBerubah(() => {
    sheetDipilih = SEMUA;
    gambar();
  });
  saatHasilMapelBerubah(() => gambar());
  saatMovingBerubah(() => gambar());

  function tombolKe(tab: string, label: string): HTMLElement {
    // Di panel, tab Jadwal ada di menu sendiri (bingkai ?bagian=user tidak memuatnya): buka menu itu.
    if (!document.getElementById(tab) && tab === 'tab-btn-jadwal') {
      return el('a', { class: 'tombol', href: '../jadwal_mapel', target: '_top' }, label.replace('tab Jadwal', 'menu Jadwal'));
    }
    const b = el('button', { class: 'tombol', type: 'button' }, label);
    b.addEventListener('click', () => bukaTab(tab));
    return b;
  }

  function kartuSumber(tingkatAda: string[]): HTMLElement {
    const data = ambilPeserta();
    const mapel = ambilHasilMapel();
    const barisPeserta = data
      ? el('p', {}, `Peserta: ${data.peserta.length} siswa dari "${data.namaFile}" (tab User → CBTAdmin). Username, password, dan nama sama dengan file CBTAdmin.`)
      : el(
          'div',
          { class: 'baris-kontrol' },
          el('span', { class: 'kosong' }, 'Belum ada data peserta. Upload file peserta dulu di tab User → CBTAdmin.'),
          tombolKe('tab-btn-user', 'Buka tab User → CBTAdmin'),
        );
    const statusMapel = tingkatAda.length
      ? tabel(
          [{ judul: 'Kelas' }, { judul: 'Mapel dari jadwal' }],
          tingkatAda.map((t) => {
            const d = mapel.get(t);
            const teks = !d ? 'Belum dikonvert' : mapelLengkap(t) ? `${d.length} mapel` : `${d.length} mapel, masih ada nama mapel belum dikenal (baris merah)`;
            return { tanda: !d ? ('merah' as const) : mapelLengkap(t) ? undefined : ('kuning' as const), sel: [t, teks] };
          }),
          'Status daftar mapel per kelas',
        )
      : null;
    const perluJadwal = tingkatAda.some((t) => !mapel.has(t) || !mapelLengkap(t));
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '1'), 'Sumber data'),
      barisPeserta,
      statusMapel,
      perluJadwal
        ? el(
            'div',
            { class: 'baris-kontrol' },
            el('span', { class: 'bantu' }, 'Course diisi dari hasil konversi jadwal. Buka Jadwal, upload jadwal, lalu tekan "Konvert"; hasilnya otomatis muncul di sini (juga di halaman lain).'),
            tombolKe('tab-btn-jadwal', 'Buka tab Jadwal'),
          )
        : null,
    );
  }

  function kartuPengecualian(tingkatAda: string[]): HTMLElement {
    const daftarTingkat = urutTingkat([...new Set(['X', 'XI', 'XII', ...tingkatAda])]);
    const kolom = daftarTingkat.map((t) => {
      const id = `kecuali-${t}`;
      const area = el('textarea', { id, rows: '8', spellcheck: 'false', class: 'daftar-kode' });
      area.value = (kecuali[t] ?? []).join('\n');
      area.addEventListener('change', () => {
        kecuali = { ...kecuali, [t]: uraiDaftarShortname(area.value) };
        simpanPengecualian(kecuali);
        gambar();
      });
      return el('div', { class: 'kolom-kecuali' }, el('label', { for: id }, `Kelas ${t}`), area);
    });
    const kembali = el('button', { class: 'tombol', type: 'button' }, 'Kembalikan ke awal');
    kembali.addEventListener('click', () => {
      if (!confirm('Kembalikan daftar mapel pilihan ke daftar awal?')) return;
      kecuali = pengecualianAwal();
      simpanPengecualian(kecuali);
      gambar();
    });
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '2'), 'Mapel pilihan (tidak didaftarkan)'),
      el(
        'p',
        { class: 'bantu' },
        'Siswa didaftarkan ke semua mapel kelasnya, kecuali shortname di bawah ini. Mapel pilihan hanya didaftarkan untuk siswa yang ada di tab Kelas Moving. Satu shortname per baris; perubahan disimpan otomatis.',
      ),
      el('div', { class: 'grid-kecuali' }, ...kolom),
      el('div', { class: 'baris-kontrol' }, kembali),
    );
  }

  function kartuCohort(): HTMLElement {
    const pilihan: [ModeCohort, string][] = [
      ['kelas', 'Per kelas (nama kelas, mis. XII-1)'],
      ['tingkat', 'Per tingkatan (X, XI, XII)'],
      ['mapel', 'Per mapel (shortname, mis. USBK-XII-BINDO)'],
      ['kelas-mapel', 'Per kelas dan per mapel'],
      ['tidak', 'Tidak dimasukkan ke cohort'],
    ];
    const mode = el('select', { id: 'cohort-mode' }, ...pilihan.map(([v, t]) => el('option', { value: v, selected: v === cohort.mode }, t)));
    const awalan = el('input', { type: 'text', id: 'cohort-awalan', value: cohort.awalan, placeholder: 'mis. Kelas ', maxlength: '50' });
    const simpan = () => {
      cohort = { mode: mode.value as ModeCohort, awalan: awalan.value };
      simpanCohort(cohort);
      gambar();
    };
    mode.addEventListener('change', simpan);
    awalan.addEventListener('change', simpan);
    const perluAwalan = cohort.mode === 'kelas' || cohort.mode === 'tingkat' || cohort.mode === 'kelas-mapel';
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '3'), 'Cohort Moodle'),
      el(
        'p',
        { class: 'bantu' },
        'Siswa dimasukkan ke cohort tingkat situs. Cohort yang belum ada dibuat otomatis saat Kirim ke Moodle; siswa tidak dikeluarkan dari cohort lamanya. Pengaturan disimpan otomatis.',
      ),
      el('div', { class: 'baris-kontrol' }, el('label', { for: 'cohort-mode' }, 'Cohort:'), mode),
      perluAwalan ? el('div', { class: 'baris-kontrol' }, el('label', { for: 'cohort-awalan' }, cohort.mode === 'tingkat' ? 'Awalan nama tingkatan (opsional):' : 'Awalan nama kelas (opsional):'), awalan) : null,
      cohort.mode === 'mapel' || cohort.mode === 'kelas-mapel' ? el('p', { class: 'bantu' }, 'Cohort mapel bernama sama dengan shortname mapel, berisi siswa yang didaftarkan ke mapel itu (mapel pilihan hanya siswa Kelas Moving).') : null,
    );
  }

  function kartuHasil(): HTMLElement | null {
    const data = ambilPeserta();
    if (!data) return null;
    const sheet = [...new Set(data.peserta.map((p) => p.sheet).filter((x): x is string => !!x))];
    const peserta = sheetDipilih ? data.peserta.filter((p) => p.sheet === sheetDipilih) : data.peserta;
    const hasilMoodle = keMoodle(peserta, keCbt(peserta), ambilHasilMapel(), kecuali, ambilMoving());
    const { peringatan, tingkatTanpaMapel } = hasilMoodle;
    const baris = terapkanCohort(hasilMoodle.baris, cohort);
    const jumlahCohort = new Set(baris.flatMap((b) => b.cohort ?? [])).size;
    const belumLengkap = [...new Set(baris.map((b) => b.tingkat))].filter((t) => ambilHasilMapel().has(t) && !mapelLengkap(t));
    const pesan = [
      ...peringatan,
      ...belumLengkap.map((t) => ({
        tingkat: 'peringatan' as const,
        pesan: `Daftar mapel kelas ${t} belum lengkap: masih ada nama mapel belum dikenal di tab Jadwal, jadi mapel itu tidak ikut di course`,
      })),
    ];

    let pilihSheet: HTMLElement | null = null;
    if (sheet.length > 1) {
      const sel = el(
        'select',
        { id: 'pilih-sheet-moodle' },
        el('option', { value: SEMUA, selected: !sheetDipilih }, `Semua sheet (${data.peserta.length} peserta)`),
        ...sheet.map((n) => el('option', { value: n, selected: n === sheetDipilih }, `${n} (${data.peserta.filter((p) => p.sheet === n).length} peserta)`)),
      );
      sel.addEventListener('change', () => {
        sheetDipilih = sel.value;
        gambar();
      });
      pilihSheet = el('div', { class: 'baris-kontrol' }, el('label', { for: 'pilih-sheet-moodle' }, 'Peserta dari:'), sel);
    }

    const tombolPassword = el('button', { class: 'tombol', type: 'button', 'aria-pressed': String(tampilkanPassword) }, tampilkanPassword ? 'Sembunyikan password' : 'Tampilkan password');
    tombolPassword.addEventListener('click', () => {
      tampilkanPassword = !tampilkanPassword;
      gambar();
    });

    const pilihBatas = el(
      'select',
      { id: 'batas-moodle' },
      ...PILIHAN_BATAS.map((n) => el('option', { value: String(n), selected: n === batas }, n ? `${n} baris` : 'Semua')),
    );
    pilihBatas.addEventListener('change', () => {
      batas = Number(pilihBatas.value);
      gambar();
    });

    const namaFile = `USER MOODLE${sheetDipilih ? ` ${sheetDipilih}` : ''} ${tanggalHariIni()}.csv`;
    const unduh = el('button', { class: 'tombol utama', type: 'button', id: 'tombol-unduh-moodle', disabled: tingkatTanpaMapel.length > 0 }, `Unduh ${namaFile}`);
    unduh.addEventListener('click', () => unduhCsv(namaFile, tulisMoodleCsv(baris)));

    // Di panel: kirim langsung ke Moodle (buat akun + daftarkan ke mapelnya), bertahap per paket.
    const hasilKirim = el('div', { 'aria-live': 'polite' });
    let kirim: HTMLButtonElement | null = null;
    if (kirimMoodleTersedia()) {
      const label = `Kirim ${baris.length} siswa ke Moodle`;
      const tombol = el('button', { class: 'tombol', type: 'button', id: 'tombol-kirim-moodle', disabled: unduh.disabled || baris.length === 0 }, label);
      tombol.addEventListener('click', async () => {
        const jumlahCourse = baris.reduce((n, b) => n + b.course.length, 0);
        if (!confirm(`Kirim ${baris.length} siswa ke Moodle?\n\n• Akun yang belum ada dibuat; yang sudah ada: password, nama, kelas, dan ruang disamakan.\n• Setiap siswa didaftarkan ke mapelnya (${jumlahCourse} pendaftaran); yang sudah terdaftar dilewati.\n• ${jumlahCohort ? `Siswa dimasukkan ke ${jumlahCohort} cohort (yang belum ada dibuat).` : 'Tanpa cohort.'}\n\nUntuk ±1.000 siswa butuh beberapa menit; jangan tutup halaman.`)) return;
        tombol.disabled = true;
        hasilKirim.replaceChildren(el('p', { class: 'memuat', role: 'status' }, 'Mengirim…'));
        try {
          const h = await kirimKeMoodle(baris, (selesai, total) => {
            tombol.textContent = `Mengirim ${selesai}/${total}…`;
            hasilKirim.replaceChildren(el('p', { class: 'memuat', role: 'status' }, `Mengirim ke Moodle: ${selesai} dari ${total} siswa…`));
          });
          const pesan: Peringatan[] = [
            {
              tingkat: h.gagal.length ? 'peringatan' : 'info',
              pesan: `Selesai: ${h.dibuat} akun dibuat, ${h.diperbarui} diperbarui; ${h.enrolBaru} pendaftaran mapel baru, ${h.enrolSudah} sudah terdaftar.`,
            },
          ];
          if (h.tidakAda.length) {
            pesan.push({ tingkat: 'peringatan', pesan: `${h.tidakAda.length} mapel belum ada di Moodle, siswa belum didaftarkan ke mapel itu: ${h.tidakAda.slice(0, 10).join(', ')}${h.tidakAda.length > 10 ? ', …' : ''}. Buat dulu lewat menu Jadwal ("Buat mapel di Moodle"), lalu kirim ulang.` });
          }
          if (h.gagal.length) {
            pesan.push({ tingkat: 'galat', pesan: `${h.gagal.length} gagal: ${h.gagal.slice(0, 5).join('; ')}${h.gagal.length > 5 ? '; …' : ''}` });
          }
          const isi: Node[] = [daftarPesan(pesan)!];
          const tautan = alamatDataMoodle();
          if (tautan) isi.push(el('p', { class: 'bantu' }, el('a', { href: tautan, target: '_top' }, 'Buka Daftar Peserta Moodle')));
          hasilKirim.replaceChildren(...isi);
        } catch (e) {
          hasilKirim.replaceChildren(daftarPesan([{ tingkat: 'galat', pesan: e instanceof Error ? e.message : String(e) }])!);
        } finally {
          tombol.disabled = false;
          tombol.textContent = label;
        }
      });
      kirim = tombol;
    }
    const maksCourse = Math.max(0, ...baris.map((b) => b.course.length));

    return el(
      'div',
      { class: 'kartu', id: 'kartu-moodle' },
      el('h2', {}, el('span', { class: 'langkah' }, '4'), 'Pratinjau & unduh'),
      pilihSheet,
      daftarPesan(pesan),
      el('div', { class: 'baris-kontrol' }, tombolPassword, el('label', { for: 'batas-moodle' }, 'Tampilkan:'), pilihBatas),
      el('p', {}),
      tabel(
        [
          { judul: 'username' },
          { judul: 'password' },
          { judul: 'firstname' },
          { judul: 'lastname' },
          { judul: 'email' },
          { judul: 'department' },
          { judul: 'address', angka: true },
          { judul: 'cohort' },
          { judul: 'Jumlah course', angka: true },
          { judul: 'course (XI/XII: mapel pilihan)' },
        ],
        (batas ? baris.slice(0, batas) : baris).map((b) => ({
          tanda: b.course.length === 0 ? ('merah' as const) : undefined,
          sel: [
            b.username,
            tampilkanPassword ? b.password : '••••••',
            b.firstname,
            b.lastname,
            b.email,
            b.department,
            b.address,
            ringkasCohort(b.cohort ?? []),
            b.course.length,
            selCourse(b.course, b.tingkat, ambilMoving().get(b.username) ?? []),
          ],
        })),
        'Pratinjau data user Moodle',
      ),
      el(
        'p',
        { class: 'jumlah' },
        `${baris.length} peserta, paling banyak ${maksCourse} course per siswa (kolom course1–course${Math.max(14, maksCourse)}).` +
          (batas && baris.length > batas ? ` Pratinjau menampilkan ${batas} baris pertama; file unduhan dan kiriman ke Moodle berisi semuanya.` : ''),
      ),
      el('div', { class: 'baris-kontrol' }, unduh, kirim),
      hasilKirim,
      el('p', { class: 'bantu' }, 'Format sama dengan USER MOODLE.csv: pemisah titik koma (;), email = username@smada.id. Dengan cohort ada kolom cohort1, cohort2, …; upload CSV di Moodle mencocokkannya dengan ID number cohort, jadi pakai Kirim ke Moodle atau buat cohort dulu.'),
      kirim ? el('p', { class: 'bantu' }, 'Kirim ke Moodle = tanpa unduh dan upload CSV: akun dibuat dan siswa langsung didaftarkan ke mapelnya. Mapel (course) harus sudah ada di Moodle.') : null,
    );
  }

  function gambar(): void {
    const fokus = document.activeElement?.id;
    const data = ambilPeserta();
    const tingkatAda = urutTingkat([...new Set((data?.peserta ?? []).map(tingkatPeserta).filter(Boolean))]);
    akar.replaceChildren(...[kartuSumber(tingkatAda), kartuPengecualian(tingkatAda), kartuCohort(), kartuHasil()].filter((x): x is HTMLElement => x !== null));
    if (fokus) document.getElementById(fokus)?.focus();
  }

  gambar();
}
