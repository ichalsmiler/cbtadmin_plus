import { describe, expect, it, vi } from 'vitest';

// Halaman Konvert User membaca hasil konversi jadwal yang dibuat di halaman menu Jadwal (localStorage).
const isi = new Map<string, string>();
vi.stubGlobal('localStorage', {
  getItem: (k: string) => isi.get(k) ?? null,
  setItem: (k: string, v: string) => void isi.set(k, v),
  removeItem: (k: string) => void isi.delete(k),
});
const pendengarStorage: ((e: { key: string }) => void)[] = [];
vi.stubGlobal('window', { addEventListener: (_: string, f: (e: { key: string }) => void) => pendengarStorage.push(f) });
isi.set('web-ujian.hasil-mapel.v1', JSON.stringify({ XII: { shortname: ['USBK-XII-BINDO'], lengkap: true }, X: { shortname: ['USBK-X-PAI'], lengkap: false } }));

const { ambilHasilMapel, catatHasilMapel, mapelLengkap, saatHasilMapelBerubah } = await import('../../src/ui/simpan');

describe('hasil konversi mapel dibagi antar-halaman', () => {
  it('dimuat dari localStorage saat halaman dibuka', () => {
    expect(ambilHasilMapel().get('XII')).toEqual(['USBK-XII-BINDO']);
    expect(mapelLengkap('XII')).toBe(true);
    expect(mapelLengkap('X')).toBe(false);
  });

  it('catatan baru ditulis ke localStorage', () => {
    catatHasilMapel('XI', ['USBK-XI-KIMIA'], true);
    expect(JSON.parse(isi.get('web-ujian.hasil-mapel.v1')!).XI).toEqual({ shortname: ['USBK-XI-KIMIA'], lengkap: true });
  });

  it('perubahan dari halaman lain memberi tahu tab', () => {
    let terima = 0;
    saatHasilMapelBerubah(() => terima++);
    isi.set('web-ujian.hasil-mapel.v1', JSON.stringify({ XII: { shortname: ['USBK-XII-MATEMATIKA'], lengkap: true } }));
    for (const f of pendengarStorage) f({ key: 'web-ujian.hasil-mapel.v1' });
    expect(terima).toBe(1);
    expect(ambilHasilMapel().get('XII')).toEqual(['USBK-XII-MATEMATIKA']);
    expect(ambilHasilMapel().has('XI')).toBe(false);
  });
});
