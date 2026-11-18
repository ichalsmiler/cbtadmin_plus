// Penyimpanan berkas yang sudah diunggah (jadwal, kelas moving) di IndexedDB browser ini, supaya tidak perlu
// upload ulang setelah pindah menu atau memuat ulang halaman. Dihapus lewat tombol Reset menu.
// Tanpa IndexedDB (mode privat lama, lingkungan tes) semua fungsi diam-diam tidak melakukan apa-apa.

const NAMA_DB = 'konvert-berkas';
const STORE = 'berkas';

function bukaDb(): Promise<IDBDatabase | null> {
  if (typeof indexedDB === 'undefined') return Promise.resolve(null);
  return new Promise((selesai) => {
    try {
      const req = indexedDB.open(NAMA_DB, 1);
      req.onupgradeneeded = () => req.result.createObjectStore(STORE);
      req.onsuccess = () => selesai(req.result);
      req.onerror = () => selesai(null);
    } catch {
      selesai(null);
    }
  });
}

async function jalankan<T>(mode: IDBTransactionMode, kerja: (s: IDBObjectStore) => IDBRequest<T>): Promise<T | null> {
  const db = await bukaDb();
  if (!db) return null;
  return new Promise((selesai) => {
    try {
      const req = kerja(db.transaction(STORE, mode).objectStore(STORE));
      req.onsuccess = () => selesai(req.result ?? null);
      req.onerror = () => selesai(null);
    } catch {
      selesai(null);
    }
  });
}

/** Simpan data (boleh berisi ArrayBuffer) dengan kunci, mis. 'jadwal' atau 'moving'. */
export async function simpanBerkas(kunci: string, data: unknown): Promise<void> {
  await jalankan('readwrite', (s) => s.put(data, kunci));
}

export async function muatBerkas<T>(kunci: string): Promise<T | null> {
  return (await jalankan('readonly', (s) => s.get(kunci) as IDBRequest<T>)) ?? null;
}

export async function hapusBerkas(kunci: string): Promise<void> {
  await jalankan('readwrite', (s) => s.delete(kunci));
}
