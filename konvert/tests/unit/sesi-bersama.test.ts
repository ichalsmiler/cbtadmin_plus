import { beforeEach, describe, expect, it } from 'vitest';

// localStorage tiruan (lingkungan tes node).
const isi = new Map<string, string>();
globalThis.localStorage = {
  getItem: (k: string) => isi.get(k) ?? null,
  setItem: (k: string, v: string) => void isi.set(k, v),
  removeItem: (k: string) => void isi.delete(k),
  clear: () => isi.clear(),
  key: () => null,
  length: 0,
} as Storage;

const { muatSesi, muatUrutanSesi, saatSesiBerubah, simpanSesi, simpanUrutanSesi } = await import('../../src/ui/simpan');

beforeEach(() => isi.clear());

describe('pengaturan sesi bersama Jadwal ↔ Konvert User', () => {
  it('bawaan XII, X, XI', () => {
    expect(muatUrutanSesi()).toEqual(['XII', 'X', 'XI']);
  });

  it('urutan dari tab Jadwal menjadi Kel. Sesi per tingkat', () => {
    simpanUrutanSesi(['X', 'XI', 'XII']);
    expect(muatSesi()).toEqual({ X: 1, XI: 2, XII: 3 });
  });

  it('Kel. Sesi dari Konvert User menjadi urutan di tab Jadwal', () => {
    simpanSesi({ XI: 1, XII: 2, X: 3 });
    expect(muatUrutanSesi()).toEqual(['XI', 'XII', 'X']);
  });

  it('nomor sama mengikuti urutan bawaan; tingkat lain tidak diubah', () => {
    simpanSesi({ XII: 2, X: 2, XI: 1, IX: 5 });
    expect(muatUrutanSesi()).toEqual(['XI', 'XII', 'X']);
    simpanUrutanSesi(['XII', 'X', 'XI']);
    expect(muatSesi()).toEqual({ XII: 1, X: 2, XI: 3, IX: 5 });
  });

  it('tab lain diberi tahu saat sesi berubah', () => {
    let diterima: Record<string, number> | null = null;
    const lepas = saatSesiBerubah((s) => (diterima = s));
    simpanUrutanSesi(['X', 'XII', 'XI']);
    lepas();
    expect(diterima).toEqual({ X: 1, XII: 2, XI: 3 });
  });

  it('kunci lama urutan-sesi dipakai bila Kel. Sesi belum pernah diisi', () => {
    isi.set('web-ujian.urutan-sesi', JSON.stringify(['XI', 'X', 'XII']));
    expect(muatUrutanSesi()).toEqual(['XI', 'X', 'XII']);
  });
});
