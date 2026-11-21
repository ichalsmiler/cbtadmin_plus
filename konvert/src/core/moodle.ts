// Konversi data user CBTAdmin ke format upload user Moodle (seperti USER MOODLE.csv).
//
// username, password, firstname = sama dengan file CBTAdmin
// lastname = department = kelas, email = username@<domain>, address = ruang
// course1..N = shortname mapel tingkat siswa (hasil tab Jadwal), dikurangi mapel pilihan yang dikecualikan.

import Papa from 'papaparse';
import type { PesertaCBT, PesertaMasuk, Peringatan } from './tipe';
import { tingkatPeserta } from './user';

export const DOMAIN_EMAIL = 'smada.id';
const MIN_KOLOM_COURSE = 14;

/**
 * Mapel pilihan (moving) kelas XI dan XII dari sekolah (2026-11-09); tidak didaftarkan untuk semua siswa,
 * hanya untuk siswa yang ada di daftar kelas moving. PKWU dan SEJARAH INDO bukan mapel pilihan.
 */
const MAPEL_PILIHAN = [
  'FISIKA',
  'KIMIA',
  'BING LANJUT',
  'BINDO LANJUT',
  'EKONOMI',
  'GEOGRAFI',
  'MATEMATIKA LANJUT',
  'INFORMATIKA',
  'BIOLOGI',
  'SOSIOLOGI',
];

/** Shortname yang dikecualikan per tingkat. X: tidak ada; XI dan XII: mapel pilihan. */
export function pengecualianAwal(): Record<string, string[]> {
  return {
    X: [],
    XI: MAPEL_PILIHAN.map((k) => `USBK-XI-${k}`),
    XII: MAPEL_PILIHAN.map((k) => `USBK-XII-${k}`),
  };
}

/** Mengubah teks (satu shortname per baris) menjadi daftar yang rapi dan unik. */
export function uraiDaftarShortname(teks: string): string[] {
  return [...new Set(teks.split(/\r?\n|;|,/).map((s) => s.trim().replace(/\s+/g, ' ')).filter(Boolean))];
}

export { tingkatPeserta };

export interface BarisMoodle {
  username: string;
  password: string;
  firstname: string;
  lastname: string;
  email: string;
  department: string;
  address: string;
  course: string[];
  tingkat: string;
  /** Nama cohort Moodle siswa ini (kosong = tidak dimasukkan ke cohort); diisi terapkanCohort. */
  cohort?: string[];
}

/** Cara mengisi cohort: per kelas (department), per tingkatan (X/XI/XII), per mapel (shortname course), kelas + mapel, atau tidak ada. */
export type ModeCohort = 'kelas' | 'tingkat' | 'mapel' | 'kelas-mapel' | 'tidak';

export const MODE_COHORT: readonly ModeCohort[] = ['kelas', 'tingkat', 'mapel', 'kelas-mapel', 'tidak'];

export interface AturCohort {
  mode: ModeCohort;
  /** Ditambahkan di depan nama kelas/tingkatan, mis. "Kelas " → "Kelas XII-1" (tidak untuk cohort mapel). */
  awalan: string;
}

export const COHORT_AWAL: AturCohort = { mode: 'tidak', awalan: '' };

/** Nama cohort satu siswa menurut pengaturan ([] = tanpa cohort). Tiap nama maks 254 karakter (batas Moodle). */
export function namaCohort(b: Pick<BarisMoodle, 'department' | 'tingkat' | 'course'>, atur: AturCohort): string[] {
  const rapi = (t: string) => t.trim().replace(/\s+/g, ' ');
  const pakaiAwalan = (t: string) => (rapi(t) ? rapi(atur.awalan + rapi(t)) : '');
  const hasil: string[] = [];
  if (atur.mode === 'kelas' || atur.mode === 'kelas-mapel') hasil.push(pakaiAwalan(b.department));
  if (atur.mode === 'tingkat') hasil.push(pakaiAwalan(b.tingkat));
  if (atur.mode === 'mapel' || atur.mode === 'kelas-mapel') hasil.push(...b.course.map(rapi));
  return [...new Set(hasil.filter(Boolean).map((n) => n.slice(0, 254)))];
}

/** Salinan baris dengan kolom cohort terisi menurut pengaturan. */
export function terapkanCohort(baris: readonly BarisMoodle[], atur: AturCohort): BarisMoodle[] {
  return baris.map((b) => ({ ...b, cohort: namaCohort(b, atur) }));
}

export interface HasilMoodle {
  baris: BarisMoodle[];
  peringatan: Peringatan[];
  /** Tingkat siswa yang belum punya daftar mapel (jadwal belum dikonvert). */
  tingkatTanpaMapel: string[];
}

/**
 * @param peserta  data peserta (urutan sama dengan `cbt`) — untuk menentukan tingkat
 * @param cbt      hasil keCbt: sumber username, password, nama, kelas, ruang
 * @param mapel    shortname per tingkat dari tab Jadwal, urut seperti file mapel
 * @param kecuali  shortname yang tidak didaftarkan, per tingkat
 * @param moving   shortname mapel moving per username (dari tab Kelas Moving), ditambahkan ke course
 */
export function keMoodle(
  peserta: PesertaMasuk[],
  cbt: PesertaCBT[],
  mapel: ReadonlyMap<string, readonly string[]>,
  kecuali: Record<string, readonly string[]>,
  moving: ReadonlyMap<string, readonly string[]> = new Map(),
  domain = DOMAIN_EMAIL,
): HasilMoodle {
  const tanpaMapel = new Set<string>();
  const baris = cbt.map((c, i) => {
    const tingkat = tingkatPeserta(peserta[i]);
    const daftar = mapel.get(tingkat);
    if (!daftar) tanpaMapel.add(tingkat || '(tidak diketahui)');
    const buang = new Set(kecuali[tingkat] ?? []);
    return {
      username: c.username,
      password: c.password,
      firstname: c.namaPeserta,
      lastname: c.namaRombel,
      email: `${c.username}@${domain}`,
      department: c.namaRombel,
      address: c.ruang === null ? '' : String(c.ruang),
      course: [...new Set([...(daftar ?? []).filter((s) => !buang.has(s)), ...(moving.get(c.username) ?? [])])],
      tingkat,
    };
  });

  const peringatan: Peringatan[] = [];
  for (const t of [...tanpaMapel]) {
    const n = baris.filter((b) => (b.tingkat || '(tidak diketahui)') === t).length;
    peringatan.push({
      tingkat: 'galat',
      pesan: `${n} siswa kelas ${t} belum punya daftar mapel. Tekan Konvert di tab Jadwal`,
    });
  }
  // Pengecualian yang tidak ada di daftar mapel kemungkinan salah ketik.
  for (const [t, daftar] of Object.entries(kecuali)) {
    const ada = mapel.get(t);
    if (!ada || !baris.some((b) => b.tingkat === t)) continue;
    const tidakAda = daftar.filter((s) => !ada.includes(s));
    if (tidakAda.length) {
      peringatan.push({
        tingkat: 'peringatan',
        pesan: `Mapel pilihan kelas ${t} ini tidak ada di hasil konversi jadwal, jadi tidak berpengaruh: ${tidakAda.join(', ')}. Periksa penulisannya`,
      });
    }
  }
  // Kelas moving: siswa tingkat yang punya mapel pilihan tetapi belum ada di daftar moving.
  if (moving.size > 0) {
    const tingkatMoving = new Set([...moving.values()].flat().map((s) => s.split('-')[1]));
    for (const t of tingkatMoving) {
      const tanpa = baris.filter((b) => b.tingkat === t && !moving.has(b.username)).length;
      if (tanpa) {
        peringatan.push({
          tingkat: 'peringatan',
          pesan: `${tanpa} siswa kelas ${t} belum punya mapel moving, jadi hanya didaftarkan ke mapel wajib. Periksa tab Kelas Moving`,
        });
      }
      const ada = mapel.get(t);
      const asing = [...new Set([...moving.values()].flat().filter((s) => s.split('-')[1] === t && ada && !ada.includes(s)))];
      if (asing.length) {
        peringatan.push({
          tingkat: 'peringatan',
          pesan: `Mapel moving kelas ${t} ini tidak ada di hasil konversi jadwal: ${asing.join(', ')}. Samakan kodenya di kamus mapel`,
        });
      }
    }
  }
  return { baris, peringatan, tingkatTanpaMapel: [...tanpaMapel] };
}

/**
 * CSV persis seperti USER MOODLE.csv: pemisah titik koma, akhir baris CRLF, baris terakhir
 * diakhiri CRLF, UTF-8 tanpa BOM. Kolom course1..N dengan N ≥ 14 (sebanyak course terbanyak);
 * kolom cohort1..M setelah address bila ada siswa ber-cohort (M = cohort terbanyak per siswa).
 */
export function tulisMoodleCsv(baris: BarisMoodle[]): string {
  const n = Math.max(MIN_KOLOM_COURSE, ...baris.map((b) => b.course.length));
  // Kolom cohortN (dicocokkan Moodle dengan ID number cohort) hanya ada bila ada siswa ber-cohort.
  const m = Math.max(0, ...baris.map((b) => b.cohort?.length ?? 0));
  const fields = ['username', 'password', 'firstname', 'lastname', 'email', 'department', 'address', ...Array.from({ length: m }, (_, i) => `cohort${i + 1}`), ...Array.from({ length: n }, (_, i) => `course${i + 1}`)];
  const data = baris.map((b) => [b.username, b.password, b.firstname, b.lastname, b.email, b.department, b.address, ...Array.from({ length: m }, (_, i) => b.cohort?.[i] ?? ''), ...Array.from({ length: n }, (_, i) => b.course[i] ?? '')]);
  return Papa.unparse({ fields, data }, { delimiter: ';', newline: '\r\n' }) + '\r\n';
}
