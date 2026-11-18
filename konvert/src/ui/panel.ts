// Hubungan ke panel CBT Admin Plus saat Konvert dibuka di dalam panel (konvert/app menyisipkan meta cbt-*).
// Di luar panel (berkas HTML lepas) meta tidak ada, jadi fitur ini tidak muncul.

import type { BarisMoodle } from '../core/moodle';
import type { BarisMapel, PesertaCBT } from '../core/tipe';

export interface HasilPanel {
  ok: boolean;
  pesan: string;
  dibuat?: number;
  ada?: number;
  gagal?: number;
  tanpa_kategori?: string[];
}

function meta(nama: string): HTMLMetaElement | null {
  return document.querySelector(`meta[name="${nama}"]`);
}

/** Alamat halaman Kategori Mapel di panel, atau null bila Konvert tidak dibuka dari panel. */
export function alamatKategori(): string | null {
  return meta('cbt-kategori-url')?.content || null;
}

export function panelTersedia(): boolean {
  return Boolean(meta('cbt-mapel-url')?.content && meta('cbt-csrf')?.content);
}

/**
 * Kirim daftar mapel ke panel; kolom category = nomor hari, dicocokkan panel ke kategori awalan+N (mis. H3).
 * Token CSRF diperbarui dari balasan agar tombol bisa dipakai lagi tanpa memuat ulang halaman.
 */
export async function kirimMapel(baris: readonly BarisMapel[], awalan = 'H', ambil: typeof fetch = fetch): Promise<HasilPanel> {
  const url = meta('cbt-mapel-url')?.content;
  const token = meta('cbt-csrf');
  const header = meta('cbt-csrf-header')?.content || 'X-CSRF-TOKEN';
  if (!url || !token?.content) throw new Error('Konvert tidak dibuka dari panel CBT Admin.');

  const res = await ambil(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [header]: token.content },
    body: JSON.stringify({ awalan, mapel: baris.map((b) => ({ category: b.category, shortname: b.shortname, fullname: b.fullname })) }),
  });
  let data: (HasilPanel & { csrf?: string }) | null = null;
  try {
    data = (await res.json()) as HasilPanel & { csrf?: string };
  } catch {
    data = null;
  }
  if (!data || typeof data.pesan !== 'string') {
    throw new Error('Panel tidak menjawab dengan benar (sesi login mungkin habis). Muat ulang halaman lalu coba lagi.');
  }
  if (data.csrf) token.content = data.csrf;
  return data;
}

/** Alamat halaman Data CBTAdmin di panel, atau null di luar panel. */
export function alamatDataPeserta(): string | null {
  return meta('cbt-peserta-halaman')?.content || null;
}

export function kirimPesertaTersedia(): boolean {
  return Boolean(meta('cbt-peserta-url')?.content && meta('cbt-csrf')?.content);
}

/** Bentuk satu peserta yang dikirim ke panel (kunci kolom impor Data CBTAdmin). */
export function barisKirimPeserta(p: PesertaCBT): Record<string, string> {
  return {
    nama: p.namaPeserta,
    kelas: p.namaRombel || p.kelasJurusan,
    ruang: p.ruang === null ? '' : String(p.ruang),
    sesi: p.kelSesi === null ? '' : String(p.kelSesi),
    username: p.username,
    password: p.password,
  };
}

/**
 * Kirim peserta hasil konversi ke Data CBTAdmin (Peserta::imporJson): username yang sudah ada diperbarui,
 * yang baru ditambahkan. Token CSRF diperbarui dari balasan.
 */
export async function kirimPeserta(peserta: readonly PesertaCBT[], ambil: typeof fetch = fetch): Promise<HasilPanel> {
  const url = meta('cbt-peserta-url')?.content;
  const token = meta('cbt-csrf');
  const header = meta('cbt-csrf-header')?.content || 'X-CSRF-TOKEN';
  if (!url || !token?.content) throw new Error('Konvert tidak dibuka dari panel CBT Admin.');

  const res = await ambil(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [header]: token.content },
    body: JSON.stringify({ peserta: peserta.map(barisKirimPeserta) }),
  });
  let data: (HasilPanel & { csrf?: string }) | null = null;
  try {
    data = (await res.json()) as HasilPanel & { csrf?: string };
  } catch {
    data = null;
  }
  if (!data || typeof data.pesan !== 'string') {
    throw new Error('Panel tidak menjawab dengan benar (sesi login mungkin habis). Muat ulang halaman lalu coba lagi.');
  }
  if (data.csrf) token.content = data.csrf;
  return data;
}

// ---------- kirim ke Moodle (tab User → Moodle) ----------

export interface HasilKirimMoodle {
  dibuat: number;
  diperbarui: number;
  enrolBaru: number;
  enrolSudah: number;
  gagal: string[];
  tidakAda: string[];
}

export function kirimMoodleTersedia(): boolean {
  return Boolean(meta('cbt-moodle-url')?.content && meta('cbt-csrf')?.content);
}

/** Alamat halaman Daftar Peserta Moodle di panel, atau null di luar panel. */
export function alamatDataMoodle(): string | null {
  return meta('cbt-moodle-halaman')?.content || null;
}

/** Ukuran kiriman per permintaan (pembuatan akun + pendaftaran mapel di server ±beberapa detik per paket). */
export const PAKET_MOODLE = 40;

/**
 * Kirim siswa ke Moodle bertahap (paket PAKET_MOODLE): buat akun lalu daftarkan ke mapelnya (Peserta::kirimMoodleJson).
 * `kemajuan(selesai, total)` dipanggil setelah tiap paket. Berhenti dan melempar galat bila satu paket gagal.
 */
export async function kirimKeMoodle(
  baris: readonly BarisMoodle[],
  kemajuan: (selesai: number, total: number) => void = () => {},
  ambil: typeof fetch = fetch,
): Promise<HasilKirimMoodle> {
  const url = meta('cbt-moodle-url')?.content;
  const token = meta('cbt-csrf');
  const header = meta('cbt-csrf-header')?.content || 'X-CSRF-TOKEN';
  if (!url || !token?.content) throw new Error('Konvert tidak dibuka dari panel CBT Admin.');

  const total: HasilKirimMoodle = { dibuat: 0, diperbarui: 0, enrolBaru: 0, enrolSudah: 0, gagal: [], tidakAda: [] };
  for (let i = 0; i < baris.length; i += PAKET_MOODLE) {
    const paket = baris.slice(i, i + PAKET_MOODLE).map((b) => ({
      username: b.username,
      password: b.password,
      firstname: b.firstname,
      lastname: b.lastname,
      email: b.email,
      department: b.department,
      address: b.address,
      course: b.course,
    }));
    const res = await ambil(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [header]: token.content },
      body: JSON.stringify({ users: paket }),
    });
    let data: Record<string, unknown> | null = null;
    try {
      data = (await res.json()) as Record<string, unknown>;
    } catch {
      data = null;
    }
    if (!data || typeof data.dibuat !== 'number') {
      const pesan = data && typeof data.pesan === 'string' && data.pesan ? data.pesan : 'Panel tidak menjawab dengan benar (sesi login mungkin habis). Muat ulang halaman lalu coba lagi.';
      throw new Error(`${pesan} (${i} dari ${baris.length} siswa sudah terkirim)`);
    }
    if (typeof data.csrf === 'string') token.content = data.csrf;
    total.dibuat += data.dibuat;
    total.diperbarui += Number(data.diperbarui) || 0;
    total.enrolBaru += Number(data.enrol_baru) || 0;
    total.enrolSudah += Number(data.enrol_sudah) || 0;
    total.gagal.push(...((data.gagal as string[]) ?? []));
    for (const sn of (data.tidak_ada as string[]) ?? []) if (!total.tidakAda.includes(sn)) total.tidakAda.push(sn);
    kemajuan(Math.min(i + PAKET_MOODLE, baris.length), baris.length);
  }
  return total;
}

// ---------- pengaturan sesi bersama menu Sesi di panel ----------

const TINGKAT_SESI = ['X', 'XI', 'XII'];

/** Sesi per tingkatan dari menu Sesi (meta cbt-sesi-tingkat), null bila tidak di panel atau belum disimpan. */
export function sesiDariPanel(): Record<string, number> | null {
  const isi = meta('cbt-sesi-tingkat')?.content;
  if (!isi) return null;
  try {
    const d = JSON.parse(isi) as Record<string, unknown>;
    const hasil = Object.fromEntries(
      TINGKAT_SESI.filter((t) => Number.isInteger(d[t]) && (d[t] as number) >= 1).map((t) => [t, d[t] as number]),
    );
    return Object.keys(hasil).length ? hasil : null;
  } catch {
    return null;
  }
}

/** Simpan sesi X/XI/XII ke menu Sesi di panel (tanpa menunggu; gagal → tetap tersimpan di browser ini). */
export function kirimSesiPanel(sesi: Record<string, number>, ambil: typeof fetch = fetch): Promise<boolean> {
  const url = meta('cbt-sesi-url')?.content;
  const token = meta('cbt-csrf');
  const header = meta('cbt-csrf-header')?.content || 'X-CSRF-TOKEN';
  const kirim = Object.fromEntries(TINGKAT_SESI.filter((t) => Number.isInteger(sesi[t]) && sesi[t] >= 1).map((t) => [t, sesi[t]]));
  if (!url || !token?.content || !Object.keys(kirim).length) return Promise.resolve(false);
  return ambil(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [header]: token.content },
    body: JSON.stringify({ sesi: kirim }),
  })
    .then(async (res) => {
      const d = (await res.json()) as { ok?: boolean; csrf?: string };
      if (d.csrf) token.content = d.csrf;
      return d.ok === true;
    })
    .catch(() => false);
}

