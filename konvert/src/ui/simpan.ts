// Penyimpanan kamus & pengaturan di localStorage, serta unduhan file.
// Data siswa dan file jadwal TIDAK pernah disimpan di sini.

import { kamusAwal, kodeTerbaru, lengkapiKamus, shortnameTerbaru } from '../core/kamus';
import { rapikanUrutanSesi, type EditMapel } from '../core/mapel';
import type { Kamus, PesertaMasuk, Peringatan, PilihanMapel } from '../core/tipe';
import { COHORT_AWAL, MODE_COHORT, type AturCohort } from '../core/moodle';

const KUNCI_KAMUS = 'web-ujian.kamus';

type Pendengar = (kamus: Kamus) => void;
const pendengar = new Set<Pendengar>();

function kamusValid(x: unknown): x is Kamus {
  if (!x || typeof x !== 'object') return false;
  const k = x as Kamus;
  return (
    k.versi === 1 &&
    Array.isArray(k.mapel) &&
    Array.isArray(k.grup) &&
    k.mapel.every((m) => typeof m.kode === 'string' && typeof m.namaLengkap === 'string' && Array.isArray(m.alias))
  );
}

export function muatKamus(): Kamus {
  try {
    const teks = localStorage.getItem(KUNCI_KAMUS);
    if (teks) {
      const data: unknown = JSON.parse(teks);
      if (kamusValid(data)) return lengkapiKamus(data);
    }
  } catch {
    // Data rusak atau localStorage tidak tersedia: pakai kamus awal.
  }
  return kamusAwal();
}

export function simpanKamus(kamus: Kamus): void {
  try {
    localStorage.setItem(KUNCI_KAMUS, JSON.stringify(kamus));
  } catch {
    // Tetap jalan meski tidak bisa disimpan (mis. mode privat).
  }
  for (const f of pendengar) f(kamus);
}

/** Dipanggil setiap kali kamus berubah, agar tab lain ikut memperbarui tampilan. */
export function saatKamusBerubah(f: Pendengar): () => void {
  pendengar.add(f);
  return () => pendengar.delete(f);
}

const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** Unduh teks CSV (UTF-8). */
export function unduhCsv(nama: string, isi: string): void {
  unduhFile(nama, new TextEncoder().encode(isi), 'text/csv;charset=utf-8');
}

export function unduhFile(nama: string, isi: ArrayBuffer | Uint8Array, mime = MIME_XLSX): void {
  const blob = new Blob([isi as BlobPart], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = nama;
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Pilihan operator (nama tidak dikenal/duplikat) per sheet jadwal, diingat untuk upload berikutnya. */
export function muatPilihan(namaSheet: string): Record<string, PilihanMapel> {
  try {
    const data = JSON.parse(localStorage.getItem(`web-ujian.pilihan.${namaSheet}`) ?? '{}') as unknown;
    if (data && typeof data === 'object') {
      // kode lama (mis. KIMIA LANJUT) diganti kode terbaru
      return Object.fromEntries(
        Object.entries(data as Record<string, PilihanMapel>).map(([t, p]) => [t, Object.fromEntries(Object.entries(p).map(([k, kode]) => [k, kode.map(kodeTerbaru)]))]),
      );
    }
  } catch {
    // abaikan
  }
  return {};
}

export function simpanPilihan(namaSheet: string, pilihan: Record<string, PilihanMapel>): void {
  try {
    localStorage.setItem(`web-ujian.pilihan.${namaSheet}`, JSON.stringify(pilihan));
  } catch {
    // abaikan
  }
}

/** Edit operator di pratinjau mapel (kunci = shortname asli), per sheet jadwal. */
export function muatEditMapel(namaSheet: string): EditMapel {
  try {
    const data = JSON.parse(localStorage.getItem(`web-ujian.edit-mapel.${namaSheet}`) ?? '{}') as unknown;
    // kunci = shortname asli; kode lama (mis. USBK-XI-KIMIA LANJUT) diganti kode terbaru
    if (data && typeof data === 'object' && !Array.isArray(data)) {
      return Object.fromEntries(Object.entries(data as EditMapel).map(([k, v]) => [shortnameTerbaru(k), v]));
    }
  } catch {
    // abaikan
  }
  return {};
}

export function simpanEditMapel(namaSheet: string, edit: EditMapel): void {
  try {
    const kunci = `web-ujian.edit-mapel.${namaSheet}`;
    if (Object.keys(edit).length) localStorage.setItem(kunci, JSON.stringify(edit));
    else localStorage.removeItem(kunci);
  } catch {
    // abaikan
  }
}

// ---- Hasil konversi mapel terakhir, dipakai tab User (cek kode) dan User → Moodle (kolom course) ----
// Disimpan di localStorage: di panel, tab Jadwal (menu Jadwal) dan Konvert User adalah halaman berbeda,
// jadi hasil konversi jadwal harus bisa dibaca halaman lain (juga setelah dimuat ulang).

type PendengarMapel = (hasil: ReadonlyMap<string, readonly string[]>) => void;
const KUNCI_HASIL_MAPEL = 'web-ujian.hasil-mapel.v1';
const hasilMapel = new Map<string, string[]>();
const pendengarMapel = new Set<PendengarMapel>();

/** Tingkat yang hasil konversinya masih punya nama mapel belum dikenal (baris merah). */
const mapelBelumLengkap = new Set<string>();

/** Isi ulang hasil mapel dari localStorage: { tingkat: { shortname: [...], lengkap: bool } }. */
function muatHasilMapel(): void {
  hasilMapel.clear();
  mapelBelumLengkap.clear();
  try {
    const d = JSON.parse(localStorage.getItem(KUNCI_HASIL_MAPEL) ?? '{}') as unknown;
    if (!d || typeof d !== 'object' || Array.isArray(d)) return;
    for (const [t, v] of Object.entries(d as Record<string, unknown>)) {
      const x = v as { shortname?: unknown; lengkap?: unknown };
      if (!x || !Array.isArray(x.shortname) || !x.shortname.every((y) => typeof y === 'string')) continue;
      hasilMapel.set(t, [...(x.shortname as string[])]);
      if (x.lengkap === false) mapelBelumLengkap.add(t);
    }
  } catch {
    // localStorage tidak tersedia / rusak: mulai kosong.
  }
}

function tulisHasilMapel(): void {
  try {
    const d = Object.fromEntries([...hasilMapel].map(([t, sn]) => [t, { shortname: sn, lengkap: !mapelBelumLengkap.has(t) }]));
    localStorage.setItem(KUNCI_HASIL_MAPEL, JSON.stringify(d));
  } catch {
    // abaikan (mis. mode privat): tetap berlaku di halaman ini.
  }
}

if (typeof window !== 'undefined') {
  muatHasilMapel();
  // Halaman lain (menu Jadwal) mengonversi jadwal: perbarui tanpa memuat ulang.
  window.addEventListener('storage', (e) => {
    if (e.key !== KUNCI_HASIL_MAPEL) return;
    muatHasilMapel();
    for (const f of pendengarMapel) f(hasilMapel);
  });
}

/** Dicatat tab Jadwal setiap kali pratinjau mapel suatu tingkat dibuat. */
export function catatHasilMapel(tingkat: string, shortname: string[], lengkap = true): void {
  const lama = hasilMapel.get(tingkat);
  const sama = lama && lama.length === shortname.length && lama.every((s, i) => s === shortname[i]);
  if (sama && mapelBelumLengkap.has(tingkat) === !lengkap) return;
  hasilMapel.set(tingkat, [...shortname]);
  if (lengkap) mapelBelumLengkap.delete(tingkat);
  else mapelBelumLengkap.add(tingkat);
  tulisHasilMapel();
  for (const f of pendengarMapel) f(hasilMapel);
}

export function mapelLengkap(tingkat: string): boolean {
  return hasilMapel.has(tingkat) && !mapelBelumLengkap.has(tingkat);
}

export function ambilHasilMapel(): ReadonlyMap<string, readonly string[]> {
  return hasilMapel;
}

export function saatHasilMapelBerubah(f: PendengarMapel): () => void {
  pendengarMapel.add(f);
  return () => pendengarMapel.delete(f);
}

// ---- Peserta CBTAdmin terakhir, dipakai tab Kelas Moving dan User Moodle ----
// Disimpan di localStorage (permintaan sekolah 2026-11-16) agar tidak perlu upload ulang setelah
// halaman dimuat ulang. Ini pengecualian yang disengaja dari aturan "file siswa tidak disimpan";
// tombol "Hapus data peserta tersimpan" di tab User → CBTAdmin untuk komputer bersama.

export interface DataPeserta {
  namaFile: string;
  /** Peserta dengan password yang sudah dibuat (sama dengan file CBTAdmin). */
  peserta: PesertaMasuk[];
  /** Peserta seperti terbaca dari file (password kosong untuk format DATA PESERTA). */
  asli?: PesertaMasuk[];
  peringatan?: Peringatan[];
  sheet?: string[];
}

const KUNCI_PESERTA = 'web-ujian.peserta.v1';

function muatDataPeserta(): DataPeserta | null {
  try {
    const d = JSON.parse(localStorage.getItem(KUNCI_PESERTA) ?? 'null') as DataPeserta | null;
    return d && typeof d.namaFile === 'string' && Array.isArray(d.peserta) && d.peserta.length ? d : null;
  } catch {
    return null;
  }
}

type PendengarPeserta = (d: DataPeserta | null) => void;
let dataPeserta: DataPeserta | null = muatDataPeserta();
const pendengarPeserta = new Set<PendengarPeserta>();

export function catatPeserta(d: DataPeserta | null): void {
  dataPeserta = d;
  try {
    if (d) localStorage.setItem(KUNCI_PESERTA, JSON.stringify(d));
    else localStorage.removeItem(KUNCI_PESERTA);
  } catch {
    // localStorage penuh / tidak tersedia: data tetap berlaku sampai halaman ditutup.
  }
  for (const f of pendengarPeserta) f(d);
}

export function ambilPeserta(): DataPeserta | null {
  return dataPeserta;
}

export function saatPesertaBerubah(f: PendengarPeserta): () => void {
  pendengarPeserta.add(f);
  return () => pendengarPeserta.delete(f);
}

// ---- Mapel pilihan yang dikecualikan di tab User Moodle (disimpan) ----

// v2: PKWU & SEJARAH INDO bukan mapel pilihan (2026-11-09); daftar versi lama diabaikan.
const KUNCI_KECUALI = 'web-ujian.moodle.kecuali.v2';

export function muatPengecualian(awal: Record<string, string[]>): Record<string, string[]> {
  try {
    const data = JSON.parse(localStorage.getItem(KUNCI_KECUALI) ?? 'null') as unknown;
    if (data && typeof data === 'object' && Object.values(data).every((v) => Array.isArray(v) && v.every((x) => typeof x === 'string'))) {
      const lama = data as Record<string, string[]>;
      const baru = Object.fromEntries(Object.entries(lama).map(([t, d]) => [t, [...new Set(d.map(shortnameTerbaru))]]));
      return { ...awal, ...baru };
    }
  } catch {
    // abaikan
  }
  return awal;
}

export function simpanPengecualian(k: Record<string, string[]>): void {
  try {
    localStorage.setItem(KUNCI_KECUALI, JSON.stringify(k));
  } catch {
    // abaikan
  }
}

// ---- Pengaturan cohort Moodle (tab User → Moodle) ----

const KUNCI_COHORT = 'web-ujian.cohort.v1';

export function muatCohort(): AturCohort {
  try {
    const d = JSON.parse(localStorage.getItem(KUNCI_COHORT) ?? 'null') as Partial<AturCohort> | null;
    if (d && typeof d === 'object' && (MODE_COHORT as readonly string[]).includes(String(d.mode))) {
      return { mode: d.mode as AturCohort['mode'], awalan: String(d.awalan ?? '') };
    }
  } catch {
    // abaikan
  }
  return { ...COHORT_AWAL };
}

export function simpanCohort(a: AturCohort): void {
  try {
    localStorage.setItem(KUNCI_COHORT, JSON.stringify(a));
  } catch {
    // abaikan
  }
}

// ---- Mapel moving per username (hanya di memori), dari tab Kelas Moving untuk tab User Moodle ----

type PendengarMoving = (m: ReadonlyMap<string, readonly string[]>) => void;
let dataMoving: ReadonlyMap<string, readonly string[]> = new Map();
const pendengarMoving = new Set<PendengarMoving>();

export function catatMoving(m: ReadonlyMap<string, readonly string[]>): void {
  dataMoving = m;
  for (const f of pendengarMoving) f(m);
}

export function ambilMoving(): ReadonlyMap<string, readonly string[]> {
  return dataMoving;
}

export function saatMovingBerubah(f: PendengarMoving): () => void {
  pendengarMoving.add(f);
  return () => pendengarMoving.delete(f);
}

// ---- Pilihan kelas moving (disimpan atas permintaan sekolah 2026-11-09) ----
// Berisi nomor ujian dan kunci nama siswa, hanya di browser komputer ini. Ada tombol untuk menghapusnya.

const KUNCI_MOVING = 'web-ujian.moving.v1';

export interface SimpananMoving {
  pilihan: Record<string, string>;
  manual: { username: string; shortname: string }[];
  kodeDiganti: Record<string, string>;
}

const kosongMoving = (): SimpananMoving => ({ pilihan: {}, manual: [], kodeDiganti: {} });

function rekamanTeks(x: unknown): x is Record<string, string> {
  return !!x && typeof x === 'object' && !Array.isArray(x) && Object.values(x).every((v) => typeof v === 'string');
}

export function muatMoving(): SimpananMoving {
  try {
    const d = JSON.parse(localStorage.getItem(KUNCI_MOVING) ?? 'null') as Partial<SimpananMoving> | null;
    if (!d) return kosongMoving();
    return {
      pilihan: rekamanTeks(d.pilihan) ? d.pilihan : {},
      manual: Array.isArray(d.manual)
        ? d.manual
            .filter((m): m is SimpananMoving['manual'][number] => !!m && typeof m.username === 'string' && typeof m.shortname === 'string')
            .map((m) => ({ ...m, shortname: shortnameTerbaru(m.shortname) }))
        : [],
      kodeDiganti: rekamanTeks(d.kodeDiganti) ? Object.fromEntries(Object.entries(d.kodeDiganti).map(([k, v]) => [k, kodeTerbaru(v)])) : {},
    };
  } catch {
    return kosongMoving();
  }
}

export function simpanMoving(d: SimpananMoving): void {
  try {
    if (!Object.keys(d.pilihan).length && !d.manual.length && !Object.keys(d.kodeDiganti).length) localStorage.removeItem(KUNCI_MOVING);
    else localStorage.setItem(KUNCI_MOVING, JSON.stringify(d));
  } catch {
    // abaikan (mis. mode privat)
  }
}

// ---- Kel. Sesi per tingkat untuk file CBTAdmin ----

const KUNCI_SESI = 'web-ujian.sesi-tingkat';

export function muatSesi(): Record<string, number> {
  try {
    const d = JSON.parse(localStorage.getItem(KUNCI_SESI) ?? '{}') as unknown;
    if (d && typeof d === 'object' && !Array.isArray(d)) {
      return Object.fromEntries(Object.entries(d).filter(([, v]) => Number.isInteger(v) && (v as number) >= 1)) as Record<string, number>;
    }
  } catch {
    // abaikan
  }
  return {};
}

type PendengarSesi = (sesi: Record<string, number>) => void;
const pendengarSesi = new Set<PendengarSesi>();

/** Di panel: simpan juga ke menu Sesi (server), supaya halaman lain dan komputer lain memakai pengaturan yang sama. */
let simpanSesiJauh: ((sesi: Record<string, number>) => void) | null = null;
export function aturSimpanSesiJauh(f: ((sesi: Record<string, number>) => void) | null): void {
  simpanSesiJauh = f;
}

/**
 * Satu pengaturan sesi untuk tab Jadwal (urutan mapel) dan User → CBTAdmin (Kel. Sesi):
 * mengubah di salah satu tab langsung dipakai tab lainnya.
 */
export function simpanSesi(sesi: Record<string, number>): void {
  try {
    if (Object.keys(sesi).length) localStorage.setItem(KUNCI_SESI, JSON.stringify(sesi));
    else localStorage.removeItem(KUNCI_SESI);
  } catch {
    // abaikan
  }
  simpanSesiJauh?.({ ...sesi });
  for (const f of pendengarSesi) f({ ...sesi });
}

export function saatSesiBerubah(f: PendengarSesi): () => void {
  pendengarSesi.add(f);
  return () => pendengarSesi.delete(f);
}

// Menu Jadwal dan Konvert User di panel adalah dua halaman berbeda: ikuti perubahan dari halaman lain.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === KUNCI_SESI) for (const f of pendengarSesi) f(muatSesi());
  });
}

// ---------- tema tampilan ----------

export type Tema = 'terang' | 'gelap';
const KUNCI_TEMA = 'web-ujian.tema';

/** Tema pilihan operator; null = ikuti pengaturan sistem. */
export function muatTema(): Tema | null {
  try {
    const t = localStorage.getItem(KUNCI_TEMA);
    return t === 'terang' || t === 'gelap' ? t : null;
  } catch {
    return null;
  }
}

export function simpanTema(t: Tema): void {
  try {
    localStorage.setItem(KUNCI_TEMA, t);
  } catch {
    // localStorage tidak tersedia: tema hanya berlaku sampai halaman ditutup.
  }
}

// ---------- urutan sesi (tab Jadwal) ----------

/** Kunci lama (sebelum disatukan dengan Kel. Sesi): hanya dibaca bila pengaturan sesi belum pernah diisi. */
const KUNCI_URUTAN_SESI = 'web-ujian.urutan-sesi';

/**
 * Urutan tingkat per sesi (indeks 0 = sesi 1) dari pengaturan Kel. Sesi bersama: tingkat diurutkan menurut
 * nomor sesinya; nomor sama atau kosong mengikuti urutan bawaan XII, X, XI.
 */
export function muatUrutanSesi(): string[] {
  const sesi = muatSesi();
  const bawaan = rapikanUrutanSesi([]);
  if (bawaan.some((t) => sesi[t] !== undefined)) {
    return [...bawaan].sort((a, b) => (sesi[a] ?? Number.MAX_SAFE_INTEGER) - (sesi[b] ?? Number.MAX_SAFE_INTEGER));
  }
  try {
    const data = JSON.parse(localStorage.getItem(KUNCI_URUTAN_SESI) ?? '[]') as unknown;
    return rapikanUrutanSesi(Array.isArray(data) ? data : []);
  } catch {
    return bawaan;
  }
}

/** Sesi 1..n dari tab Jadwal ditulis ke pengaturan Kel. Sesi bersama (tingkat lain tidak diubah). */
export function simpanUrutanSesi(urutan: readonly string[]): void {
  simpanSesi({ ...muatSesi(), ...Object.fromEntries(urutan.map((t, i) => [t, i + 1])) });
}

// ---------- reset per menu (tombol Reset di panel) ----------

/** Hapus kunci localStorage yang persis atau berawalan tertentu. */
function hapusKunci(persis: string[], awalan: string[] = []): void {
  try {
    const semua = Array.from({ length: localStorage.length }, (_, i) => localStorage.key(i)).filter((k): k is string => k !== null);
    for (const k of semua) if (persis.includes(k) || awalan.some((a) => k.startsWith(a))) localStorage.removeItem(k);
  } catch {
    // abaikan
  }
}

/** Menu Jadwal: hasil konversi, pilihan mapel tak dikenal, dan edit pratinjau (kamus & sesi tetap). */
export function resetDataJadwal(): void {
  hapusKunci([KUNCI_HASIL_MAPEL], ['web-ujian.pilihan.', 'web-ujian.edit-mapel.']);
}

/** Menu Konvert User: data peserta, pencocokan & entri manual kelas moving (sesi & daftar mapel pilihan tetap). */
export function resetDataUser(): void {
  hapusKunci([KUNCI_PESERTA, KUNCI_MOVING]);
}
