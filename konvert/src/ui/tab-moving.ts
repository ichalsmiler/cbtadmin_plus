// Tab "Kelas Moving": upload daftar hadir kelas moving (mapel pilihan), cocokkan nama murid ke
// peserta dari tab User → CBTAdmin, perbaiki yang tidak cocok, dan tambah siswa secara manual.
// Hasilnya (mapel moving per siswa) dipakai tab User → Moodle untuk kolom course.

import { urutTingkat } from '../core/jadwal';
import { PETA_MAPEL_MOVING, bacaFileMoving, tingkatDariKelasMoving, tingkatDariNamaFile, cocokkanMoving, kodeMovingAktif, kunciKodeMoving, kunciNama, kunciPasangan, movingPerSiswa, nomorKelas, pilihanPasangan, pasanganGanda, JUMLAH_MOVING, rekapMoving, tulisRekapMovingXlsx, type KelompokMoving, type PasanganMoving, type TambahanManual } from '../core/moving';
import { tingkatPeserta } from '../core/moodle';
import { GalatFormat, type PesertaMasuk, type Peringatan } from '../core/tipe';
import { ambilPeserta, catatMoving, muatKamus, muatMoving, saatKamusBerubah, saatPesertaBerubah, simpanMoving, unduhFile } from './simpan';
import { daftarPesan, el, tabel } from './tabel';

const BATAS_BARIS = 200;
const ABAIKAN = '__abaikan__';
const CARI = '__cari__';

type Saring = 'perlu' | 'mirip' | 'semua';

interface Status {
  kelompok: KelompokMoving[];
  galat: Peringatan[];
  /** Kode mapel yang diganti operator, per singkatan (mis. BIO → BIOLOGI). */
  kodeDiganti: Record<string, string>;
  /** Pilihan operator per baris file: username, atau '' = abaikan. */
  pilihan: Record<string, string>;
  manual: TambahanManual[];
  saring: Saring;
  /** Alur yang sedang dikerjakan: 'XI' atau 'XII' ('' = belum dipilih). Tiap alur punya file, pencocokan, dan rekap sendiri. */
  alur: string;
}

/** Tingkatan yang punya kelas moving; masing-masing alur tersendiri. */
const ALUR_MOVING = ['XI', 'XII'];

function labelSiswa(p: PesertaMasuk): string {
  return `${p.username}: ${p.firstname} (${p.department})`;
}

/** Untuk rekap: cukup nama dan kelas, tanpa nomor ujian. */
function namaKelas(p: PesertaMasuk): string {
  return `${p.firstname} (${p.department})`;
}

export function pasangTabMoving(akar: HTMLElement): void {
  const tersimpan = muatMoving();
  const s: Status = { kelompok: [], galat: [], ...tersimpan, saring: 'perlu', alur: '' };

  saatPesertaBerubah(() => gambar());
  saatKamusBerubah(() => gambar());

  /** Kelompok (file) alur yang sedang dikerjakan. */
  function kelompokAktif(): KelompokMoving[] {
    return s.kelompok.filter((k) => k.tingkat === s.alur).map((k) => ({ ...k, kode: kodeMovingAktif(k, s.kodeDiganti) }));
  }

  /** Peserta per tingkat (dari nama sheet), untuk dicocokkan. */
  function pesertaTingkat(t: string): PesertaMasuk[] {
    return (ambilPeserta()?.peserta ?? []).filter((p) => tingkatPeserta(p) === t);
  }

  // Pencocokan nama cukup berat (ribuan nama × ratusan siswa), jadi hasilnya disimpan per kelompok
  // dan hanya dihitung ulang saat file moving atau file peserta berganti — bukan setiap klik.
  let cache: { peserta: unknown; per: WeakMap<KelompokMoving, PasanganMoving[]> } = { peserta: undefined, per: new WeakMap() };

  function hitungPasangan(): PasanganMoving[] {
    const data = ambilPeserta();
    if (cache.peserta !== data) cache = { peserta: data, per: new WeakMap() };
    const belum = s.kelompok.filter((k) => !cache.per.has(k));
    for (const t of new Set(belum.map((k) => k.tingkat))) {
      const kel = belum.filter((k) => k.tingkat === t);
      const hasil = cocokkanMoving(kel, pesertaTingkat(t));
      for (const k of kel) cache.per.set(k, hasil.filter((h) => h.kelompok === k));
    }
    const tingkat = [...new Set(s.kelompok.map((k) => k.tingkat))];
    return tingkat.flatMap((t) =>
      s.kelompok
        .filter((k) => k.tingkat === t)
        .flatMap((asli) => {
          const aktif = { ...asli, kode: kodeMovingAktif(asli, s.kodeDiganti) };
          return cache.per.get(asli)!.map((p) => ({ ...p, kelompok: aktif }));
        }),
    );
  }

  /** Teks status selama file dibaca; '' = tidak sedang membaca. */
  let memuat = '';

  async function pilihFile(files: FileList): Promise<void> {
    s.galat = [];
    for (const f of Array.from(files)) {
      memuat = `Membaca ${f.name}…`;
      gambar();
      try {
        // File yang diunggah di alur kelas XI dianggap kelas XI (begitu juga XII); bila nama file atau kolom KELAS
        // menyebut kelas lain, operator diberi peringatan (mungkin salah alur).
        const kel = (await bacaFileMoving(await f.arrayBuffer(), f.name)).map((k): KelompokMoving => {
          const terbaca = tingkatDariNamaFile(f.name) || tingkatDariKelasMoving(k.siswa.map((x) => x.kelas));
          const baru: KelompokMoving = { ...k, tingkat: s.alur };
          delete baru.peringatan;
          if (terbaca && terbaca !== s.alur) {
            baru.peringatan = `File ini tampaknya untuk kelas ${terbaca}, tetapi diunggah di alur kelas ${s.alur}; muridnya dicocokkan dengan siswa kelas ${s.alur}. Lepas file lalu unggah di alur kelas ${terbaca} bila salah.`;
          }
          return baru;
        });
        // Upload ulang file yang sama di alur ini = ganti; file alur lain tidak tersentuh.
        s.kelompok = [...s.kelompok.filter((k) => !(k.namaFile === f.name && k.tingkat === s.alur)), ...kel];
      } catch (e) {
        s.galat.push({ tingkat: 'galat', pesan: e instanceof GalatFormat ? e.message : `${f.name}: ${String(e)}` });
      }
    }
    s.kelompok.sort((a, b) => a.judul.localeCompare(b.judul, 'id', { numeric: true }));
    memuat = '';
    gambar();
  }

  // ---------- kartu ----------

  function kartuUpload(): HTMLElement {
    const input = el('input', { type: 'file', accept: '.xlsx', id: 'file-moving', class: 'sr-only', multiple: true });
    input.addEventListener('change', () => {
      if (input.files?.length) void pilihFile(input.files);
      input.value = '';
    });
    const hapus = el('button', { class: 'tombol', type: 'button' }, 'Lepas semua file');
    hapus.addEventListener('click', () => {
      Object.assign(s, { kelompok: s.kelompok.filter((k) => k.tingkat !== s.alur), galat: [] }); // hanya file alur ini; pilihan & entri manual tetap tersimpan
      gambar();
    });
    const jumlahTersimpan = Object.keys(s.pilihan).length + s.manual.length;
    const hapusSimpanan = el('button', { class: 'tombol', type: 'button', id: 'hapus-simpanan-moving', disabled: jumlahTersimpan === 0 }, `Hapus pilihan tersimpan (${jumlahTersimpan})`);
    hapusSimpanan.addEventListener('click', () => {
      if (!confirm(`Hapus ${jumlahTersimpan} pilihan pencocokan dan entri manual yang tersimpan di browser ini? Ini tidak bisa dibatalkan.`)) return;
      Object.assign(s, { pilihan: {}, manual: [], kodeDiganti: {} });
      gambar();
    });

    const semuaKode = [...new Set([...Object.values(PETA_MAPEL_MOVING), ...muatKamus().mapel.map((m) => m.kode)])].sort();
    // Satu baris per (tingkat, mapel): file moving XI dan XII untuk mapel yang sama tetap terpisah.
    const perMapel = new Map<string, KelompokMoving[]>();
    for (const k of kelompokAktif()) {
      const kunci = `${k.tingkat}|${k.singkatan}`;
      perMapel.set(kunci, [...(perMapel.get(kunci) ?? []), k]);
    }
    const urutanTingkat = urutTingkat([...new Set(kelompokAktif().map((k) => k.tingkat))]);
    const baris = [...perMapel].sort(
      ([a], [b]) => urutanTingkat.indexOf(a.split('|')[0]) - urutanTingkat.indexOf(b.split('|')[0]) || a.localeCompare(b),
    );
    const jumlahPeserta = (t: string) => pesertaTingkat(t).length;
    const peringatanFile = [...new Map(kelompokAktif().filter((k) => k.peringatan).map((k) => [k.namaFile, k])).values()].map((k) => ({
      tingkat: 'peringatan' as const,
      pesan: k.peringatan!,
      lokasi: k.namaFile,
    }));

    // Kode mapel moving sudah pasti (PETA_MAPEL_MOVING), jadi tabel hanya muncul untuk mapel yang
    // kodenya belum dikenali dan harus dipilih operator.
    const belumAdaKode = baris.filter(([, kel]) => !kel[0].kode);
    const ringkasFile = perMapel.size
      ? el('p', {}, `${new Set(kelompokAktif().map((k) => k.namaFile)).size} file terbaca: ${perMapel.size} mapel moving, ${kelompokAktif().length} kelompok.`)
      : null;
    const tabelMapel = belumAdaKode.length
      ? tabel(
          [{ judul: 'Kelas' }, { judul: 'Mapel di file' }, { judul: 'Kelompok' }, { judul: 'Siswa', angka: true }, { judul: 'Didaftarkan ke' }, { judul: 'Dicocokkan dengan' }],
          belumAdaKode.map(([, kel]) => {
            const singkatan = kel[0].singkatan;
            const t = kel[0].tingkat;
            const kode = kel[0].kode;
            const sel = el(
              'select',
              { 'aria-label': `Kode mapel untuk ${singkatan}` },
              el('option', { value: '', selected: !kode }, 'Pilih kode mapel…'),
              ...semuaKode.map((k) => el('option', { value: k, selected: k === kode }, k)),
            );
            sel.addEventListener('change', () => {
              s.kodeDiganti = { ...s.kodeDiganti, [kunciKodeMoving({ tingkat: t, singkatan })]: sel.value };
              gambar();
            });
            return {
              tanda: kode ? undefined : ('merah' as const),
              sel: [
                t,
                singkatan,
                kel.map((k) => k.judul).join(', '),
                kel.reduce((n, k) => n + k.siswa.length, 0),
                el('span', { class: 'baris-kontrol' }, `USBK-${t}-`, sel),
                ambilPeserta() ? `siswa kelas ${t} saja (${jumlahPeserta(t)} siswa)` : `siswa kelas ${t} saja`,
              ],
            };
          }),
          'Mapel moving yang kodenya belum dikenali',
        )
      : null;

    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '1'), `Upload daftar kelas moving ${s.alur}`),
      el(
        'div',
        { class: 'baris-kontrol' },
        el('label', { class: 'tombol', for: 'file-moving' }, kelompokAktif().length ? 'Tambah / ganti file…' : `Pilih file moving kelas ${s.alur} (.xlsx, bisa banyak sekaligus)…`),
        input,
        kelompokAktif().length ? hapus : null,
        hapusSimpanan,
      ),
      el('p', { class: 'bantu' }, 'Format seperti "DAFTAR HADIR KELAS MOVING XI BIO.xlsx": satu sheet per kelompok, judul kelompok di baris 2, kolom NAMA MURID dan KELAS.'),
      el(
        'p',
        { class: 'bantu' },
        `Semua file yang diunggah di sini dicocokkan dengan siswa kelas ${s.alur} saja. Untuk kelas ${ALUR_MOVING.filter((x) => x !== s.alur).join(', ')}, pilih alurnya di atas.`,
      ),
      memuat ? el('p', { class: 'memuat', role: 'status' }, memuat) : null,
      daftarPesan([...s.galat, ...peringatanFile]),
      ringkasFile,
      tabelMapel ? el('p', { class: 'bantu' }, 'Pilih kode mapel untuk file moving berikut:') : null,
      tabelMapel,
    );
  }

  function kartuSumberKosong(): HTMLElement {
    const buka = el('button', { class: 'tombol', type: 'button' }, 'Buka tab User → CBTAdmin');
    buka.addEventListener('click', () => document.getElementById('tab-btn-user')?.click());
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '2'), 'Cocokkan nama'),
      el(
        'div',
        { class: 'baris-kontrol' },
        el('span', { class: 'kosong' }, 'Nama murid dicocokkan ke data peserta. Upload file peserta dulu di tab User → CBTAdmin.'),
        buka,
      ),
    );
  }

  /**
   * Satu murid ada di beberapa file moving (satu baris per mapel). Di tabel pencocokan baris-baris
   * itu digabung: kunci = tingkat + nama + nomor rombel, sama dengan dasar hasil cocok otomatis.
   */
  function kunciSiswa(p: PasanganMoving): string {
    return `${p.kelompok.tingkat}|${kunciNama(p.siswa.nama)}|${nomorKelas(p.siswa.kelas)}`;
  }

  /** Pilihan operator untuk satu murid: pilihan pertama yang ada di salah satu barisnya. */
  function pilihanGrup(baris: PasanganMoving[]): string | undefined {
    for (const p of baris) {
      const d = pilihanPasangan(p, s.pilihan);
      if (d !== undefined) return d;
    }
    return undefined;
  }

  /** Simpan pilihan untuk semua baris (semua mapel) murid itu sekaligus. */
  function pilihUntuk(baris: PasanganMoving[], u: string): void {
    s.pilihan = { ...s.pilihan, ...Object.fromEntries(baris.map((p) => [kunciPasangan(p), u])) };
  }

  function pemilih(baris: PasanganMoving[], semua: PesertaMasuk[], idDaftar: string): HTMLElement {
    const p = baris[0];
    const dipilih = pilihanGrup(baris);
    const sekarang = dipilih ?? p.username;
    const opsi = new Map(p.kandidat.map((c) => [c.username, `${c.username}: ${c.nama} (${c.kelas}) · ${Math.round(c.skor * 100)}%`]));
    if (sekarang && !opsi.has(sekarang)) {
      const x = semua.find((q) => q.username === sekarang);
      if (x) opsi.set(sekarang, labelSiswa(x));
    }
    const sel = el(
      'select',
      { 'aria-label': `Siswa untuk ${p.siswa.nama}` },
      el('option', { value: '', selected: sekarang === '' && dipilih === undefined }, 'Pilih siswa…'),
      ...[...opsi].map(([u, label]) => el('option', { value: u, selected: u === sekarang }, label)),
      el('option', { value: CARI }, 'Cari siswa lain…'),
      el('option', { value: ABAIKAN, selected: dipilih === '' }, 'Abaikan nama ini'),
    );
    const cari = el('input', { type: 'text', list: idDaftar, placeholder: 'Ketik nama atau no ujian', size: '34', hidden: true, 'aria-label': 'Cari siswa' });
    sel.addEventListener('change', () => {
      if (sel.value === CARI) {
        cari.hidden = false;
        cari.focus();
        return;
      }
      pilihUntuk(baris, sel.value === ABAIKAN ? '' : sel.value);
      gambar();
    });
    cari.addEventListener('change', () => {
      const u = cari.value.split(': ')[0].trim();
      if (semua.some((q) => q.username === u)) {
        pilihUntuk(baris, u);
        gambar();
      }
    });
    return el('span', { class: 'baris-kontrol' }, sel, cari);
  }

  function kartuCocok(pasangan: PasanganMoving[]): HTMLElement {
    const semua = [...new Set(pasangan.map((p) => p.kelompok.tingkat))].flatMap((t) => pesertaTingkat(t));
    // Satu baris per murid (semua mapel moving-nya digabung), urutan sesuai kemunculan pertama.
    const perSiswa = new Map<string, PasanganMoving[]>();
    for (const p of pasangan) {
      const k = kunciSiswa(p);
      perSiswa.set(k, [...(perSiswa.get(k) ?? []), p]);
    }
    const grup = [...perSiswa.values()];
    const statusAkhir = (baris: PasanganMoving[]) => {
      const dipilih = pilihanGrup(baris);
      if (dipilih !== undefined) return dipilih ? 'dipilih' : 'diabaikan';
      return baris[0].status;
    };
    const hitung = (st: string) => grup.filter((g) => statusAkhir(g) === st).length;
    const perlu = hitung('pilih');

    const tombolSaring = (nilai: Saring, label: string) => {
      const b = el('button', { class: 'tombol', type: 'button', 'aria-pressed': String(s.saring === nilai) }, label);
      b.addEventListener('click', () => {
        s.saring = nilai;
        gambar();
      });
      return b;
    };
    const tampil = grup.filter((g) => {
      const st = statusAkhir(g);
      if (s.saring === 'perlu') return st === 'pilih';
      if (s.saring === 'mirip') return st === 'mirip' || st === 'dipilih' || st === 'diabaikan';
      return true;
    });

    const idDaftar = 'daftar-siswa-moving';
    const datalist = el('datalist', { id: idDaftar }, ...semua.map((q) => el('option', { value: labelSiswa(q) })));
    const nama = new Map(semua.map((q) => [q.username, q]));

    const pesan: Peringatan[] = [];
    if (perlu) pesan.push({ tingkat: 'galat', pesan: `${perlu} nama belum dicocokkan. Pilih siswanya di tabel merah (cukup sekali, berlaku untuk semua mapel moving-nya), atau abaikan barisnya.` });
    pesan.push({
      tingkat: 'info',
      pesan: 'Pilihan dan entri manual tersimpan di browser komputer ini, jadi tidak hilang saat halaman dimuat ulang (upload ulang file moving untuk melanjutkan; data peserta diambil otomatis dari tab User → CBTAdmin). Di komputer bersama, tekan "Hapus pilihan tersimpan" setelah selesai.',
    });

    return el(
      'div',
      { class: 'kartu', id: 'kartu-cocok-moving' },
      el('h2', {}, el('span', { class: 'langkah' }, '2'), 'Cocokkan nama'),
      el(
        'p',
        {},
        `${grup.length} siswa (${pasangan.length} baris di file moving): ${hitung('persis')} sama persis, ${hitung('mirip')} mirip (dicocokkan otomatis, perlu dicek), ` +
          `${perlu} perlu dipilih, ${hitung('dipilih')} dipilih operator, ${hitung('diabaikan')} diabaikan.`,
      ),
      daftarPesan(pesan),
      el(
        'div',
        { class: 'baris-kontrol', role: 'group', 'aria-label': 'Saring baris' },
        tombolSaring('perlu', `Perlu dipilih (${perlu})`),
        tombolSaring('mirip', `Mirip & sudah dipilih (${hitung('mirip') + hitung('dipilih') + hitung('diabaikan')})`),
        tombolSaring('semua', `Semua (${grup.length})`),
      ),
      el('p', {}),
      datalist,
      tampil.length
        ? tabel(
            [{ judul: 'Nama di file moving' }, { judul: 'Kelas' }, { judul: 'Mapel moving' }, { judul: 'Dicocokkan ke' }],
            tampil.slice(0, BATAS_BARIS).map((baris) => {
              const p = baris[0];
              const st = statusAkhir(baris);
              const u = pilihanGrup(baris) ?? p.username;
              const siswa = u ? nama.get(u) : undefined;
              return {
                tanda: st === 'pilih' ? ('merah' as const) : st === 'mirip' ? ('kuning' as const) : st === 'diabaikan' ? ('abu' as const) : undefined,
                sel: [p.siswa.nama, p.siswa.kelas, [...new Set(baris.map((b) => b.kelompok.judul))].join(', '), st === 'persis' && siswa ? labelSiswa(siswa) : pemilih(baris, semua, idDaftar)],
              };
            }),
            'Pencocokan nama murid kelas moving',
          )
        : el('p', { class: 'kosong' }, s.saring === 'perlu' ? 'Semua nama sudah dicocokkan.' : 'Tidak ada baris.'),
      tampil.length > BATAS_BARIS ? el('p', { class: 'jumlah' }, `Menampilkan ${BATAS_BARIS} dari ${tampil.length} baris.`) : null,
    );
  }

  function kartuManual(): HTMLElement {
    const tingkat = [s.alur];
    const semua = tingkat.flatMap((t) => pesertaTingkat(t));
    const shortname = [...new Set(kelompokAktif().filter((k) => k.kode).map((k) => `USBK-${k.tingkat}-${k.kode}`))].sort();

    const idDaftar = 'daftar-siswa-manual';
    const siswa = el('input', { type: 'text', list: idDaftar, placeholder: 'Ketik nama atau no ujian', size: '38', id: 'manual-siswa' });
    const mapel = el('select', { id: 'manual-mapel' }, el('option', { value: '' }, 'Pilih mapel moving…'), ...shortname.map((x) => el('option', { value: x }, x)));
    const galat = el('span', { class: 'bantu', role: 'alert' });
    const tambah = el('button', { class: 'tombol utama', type: 'button' }, 'Tambah');
    tambah.addEventListener('click', () => {
      const u = siswa.value.split(': ')[0].trim();
      if (!semua.some((q) => q.username === u)) {
        galat.textContent = 'Pilih siswa dari daftar.';
        return;
      }
      if (!mapel.value) {
        galat.textContent = 'Pilih mapel moving.';
        return;
      }
      const tingkatSiswa = tingkatPeserta(semua.find((q) => q.username === u)!);
      const tingkatMapel = mapel.value.split('-')[1];
      if (tingkatSiswa !== tingkatMapel) {
        galat.textContent = `Siswa kelas ${tingkatSiswa} tidak bisa didaftarkan ke mapel moving kelas ${tingkatMapel}.`;
        return;
      }
      if (!s.manual.some((m) => m.username === u && m.shortname === mapel.value)) s.manual = [...s.manual, { username: u, shortname: mapel.value }];
      gambar();
    });

    const nama = new Map(semua.map((q) => [q.username, q]));
    // Entri manual alur ini saja (shortname USBK-<kelas>-…); indeks asli dipakai untuk hapus.
    const manualAlur = s.manual.map((m, i) => ({ m, i })).filter(({ m }) => m.shortname.split('-')[1] === s.alur);
    const daftar = manualAlur.length
      ? tabel(
          [{ judul: 'Siswa' }, { judul: 'Mapel moving' }, { judul: '' }],
          manualAlur.map(({ m, i }) => {
            const hapus = el('button', { class: 'tombol', type: 'button' }, 'Hapus');
            hapus.addEventListener('click', () => {
              s.manual = s.manual.filter((_, j) => j !== i);
              gambar();
            });
            const q = nama.get(m.username);
            return { sel: [q ? labelSiswa(q) : m.username, m.shortname, hapus] };
          }),
          'Siswa yang ditambahkan manual',
        )
      : null;

    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '3'), 'Tambah siswa ke kelas moving (manual)'),
      el('p', { class: 'bantu' }, 'Untuk siswa yang tidak ada di file moving, atau pindah kelompok.'),
      el('datalist', { id: idDaftar }, ...semua.map((q) => el('option', { value: labelSiswa(q) }))),
      el(
        'div',
        { class: 'baris-kontrol' },
        el('label', { class: 'sr-only', for: 'manual-siswa' }, 'Siswa'),
        siswa,
        el('label', { class: 'sr-only', for: 'manual-mapel' }, 'Mapel moving'),
        mapel,
        tambah,
        galat,
      ),
      daftar,
    );
  }

  function kartuRekap(moving: Map<string, string[]>, pasangan: PasanganMoving[]): HTMLElement {
    const tingkat = [s.alur];
    const semua = tingkat.flatMap((t) => pesertaTingkat(t));
    const jumlah = new Map<number, number>();
    for (const q of semua) {
      const n = moving.get(q.username)?.length ?? 0;
      jumlah.set(n, (jumlah.get(n) ?? 0) + 1);
    }
    const ringkas = [...jumlah].sort((a, b) => a[0] - b[0]).map(([n, c]) => `${c} siswa dengan ${n} mapel`).join(' · ');
    // Aturan sekolah: setiap siswa punya JUMLAH_MOVING (4) mapel moving; selain itu perlu dicek.
    const umum = JUMLAH_MOVING;
    const tidakBiasa = semua.filter((q) => (moving.get(q.username)?.length ?? 0) !== umum);
    const unduh = el('button', { class: 'tombol utama', type: 'button' }, 'Unduh rekap kelas moving (.xlsx)');
    unduh.addEventListener('click', async () => {
      const isi = await tulisRekapMovingXlsx(rekapMoving(semua, moving));
      unduhFile(`REKAP KELAS MOVING ${tingkat.join(' ')}.xlsx`, isi);
    });
    const nama = new Map(semua.map((q) => [q.username, q]));
    const ganda = pasanganGanda(pasangan, s.pilihan);
    // Hanya informasi: mapel moving per siswa sudah unik (satu shortname per mapel), jadi siswa yang
    // ada di dua kelompok mapel yang sama tetap didaftarkan sekali. Tidak ada yang perlu dilakukan.
    const bagianGanda = ganda.length
      ? el(
          'div',
          {},
          daftarPesan([
            {
              tingkat: 'info',
              pesan: `${ganda.length} siswa tercantum lebih dari sekali untuk mapel yang sama (mis. di dua kelompok). Mereka tetap didaftarkan sekali ke mapel itu; tidak perlu tindakan.`,
            },
          ]),
          el(
            'details',
            {},
            el('summary', {}, 'Lihat daftarnya'),
            tabel(
              [{ judul: 'Siswa' }, { judul: 'Mapel' }, { judul: 'Tercantum di' }],
              ganda.map((x) => {
                const q = nama.get(x.username);
                const daftar = el('div', {}, ...x.baris.map((b) => el('div', {}, `${b.kelompok.judul}: ${b.siswa.nama} (${b.siswa.kelas})`)));
                return { sel: [q ? namaKelas(q) : x.username, x.kode, daftar] };
              }),
              'Siswa yang tercantum lebih dari sekali untuk mapel yang sama',
            ),
          ),
          el('p', {}),
        )
      : null;
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, el('span', { class: 'langkah' }, '4'), 'Rekap per siswa'),
      el('p', {}, `Kelas ${tingkat.join(', ')}: ${semua.length} siswa. ${ringkas}.`),
      el(
        'p',
        { class: 'baris-kontrol' },
        unduh,
        el('span', { class: 'bantu' }, ` Semua siswa dan mapel moving-nya; ${tidakBiasa.length} siswa yang mapel moving-nya bukan ${umum} diwarnai merah.`),
      ),
      bagianGanda,
      tidakBiasa.length
        ? el(
            'div',
            {},
            el('p', { class: 'bantu' }, `Siswa yang jumlah mapel moving-nya bukan ${umum} (perlu dicek):`),
            tabel(
              [{ judul: 'Siswa' }, { judul: 'Jumlah', angka: true }, { judul: 'Mapel moving' }],
              tidakBiasa.slice(0, BATAS_BARIS).map((q) => {
                const m = moving.get(q.username) ?? [];
                return { tanda: 'merah' as const, sel: [namaKelas(q), m.length, m.map((x) => x.replace(/^USBK-[IVX]+-/, '')).join(', ')] };
              }),
              'Siswa dengan jumlah mapel moving tidak biasa',
            ),
          )
        : el('p', { class: 'kosong' }, `Semua siswa punya ${umum} mapel moving.`),
      el('p', { class: 'bantu' }, 'Mapel moving ini otomatis ditambahkan ke kolom course di tab User → Moodle.'),
    );
  }

  /** Pilih alur: Kelas XI atau Kelas XII, masing-masing dengan langkah 1–4 sendiri. */
  function kartuAlur(): HTMLElement {
    const tombol = ALUR_MOVING.map((t) => {
      const jumlah = new Set(s.kelompok.filter((k) => k.tingkat === t).map((k) => k.namaFile)).size;
      const b = el(
        'button',
        { class: t === s.alur ? 'tombol utama' : 'tombol', type: 'button', 'aria-pressed': String(t === s.alur) },
        `Kelas ${t}${jumlah ? ` (${jumlah} file)` : ''}`,
      );
      b.addEventListener('click', () => {
        s.alur = t;
        s.galat = [];
        gambar();
      });
      return b;
    });
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, 'Pilih kelas moving'),
      el('div', { class: 'baris-kontrol', role: 'group', 'aria-label': 'Kelas moving' }, ...tombol),
      el('p', { class: 'bantu' }, s.alur
        ? `Mengerjakan kelas moving ${s.alur}. Hasil kelas XI dan XII digabung otomatis untuk tab User → Moodle.`
        : 'Kelas XI dan XII dikerjakan terpisah: pilih salah satu untuk upload file, mencocokkan nama, dan rekapnya.'),
    );
  }

  function gambar(): void {
    const fokus = document.activeElement?.id;
    const adaPeserta = !!ambilPeserta();
    // Hasil semua alur (XI + XII) untuk User → Moodle; yang ditampilkan hanya alur terpilih.
    const semuaPasangan = adaPeserta && s.kelompok.length ? hitungPasangan() : [];
    const pasangan = semuaPasangan.filter((p) => p.kelompok.tingkat === s.alur);
    const moving = movingPerSiswa(semuaPasangan, s.pilihan, s.manual);
    catatMoving(moving);
    simpanMoving({ pilihan: s.pilihan, manual: s.manual, kodeDiganti: s.kodeDiganti });
    const adaFile = kelompokAktif().length > 0;
    akar.replaceChildren(
      ...[
        kartuAlur(),
        s.alur ? kartuUpload() : null,
        s.alur && adaFile ? (adaPeserta ? kartuCocok(pasangan) : kartuSumberKosong()) : null,
        s.alur && adaPeserta && adaFile ? kartuManual() : null,
        s.alur && adaPeserta && adaFile ? kartuRekap(moving, pasangan) : null,
      ].filter((x): x is HTMLElement => x !== null),
    );
    if (fokus) document.getElementById(fokus)?.focus();
  }

  gambar();
}
