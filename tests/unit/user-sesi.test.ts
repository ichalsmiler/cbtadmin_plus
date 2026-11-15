import { describe, expect, it } from 'vitest';
import { keCbt, nomorSesi, tingkatPeserta, tulisCbtCsv } from '../../src/core/user';
import type { PesertaMasuk } from '../../src/core/tipe';

const p = (username: string, department: string, sheet?: string): PesertaMasuk => ({
  username,
  password: 'AB12CD',
  firstname: `Siswa ${username}`,
  lastname: '',
  department,
  address: '1',
  course: [],
  baris: 2,
  ...(sheet ? { sheet } : {}),
});

// Kolom KELAS bisa masih kelas lama; tingkat diambil dari nama sheet
const PESERTA = [p('91-26-0001', 'X-1', 'KLS. X'), p('92-26-0001', 'X-3', 'KLS. XI'), p('93-26-0001', 'XII-2', 'KLS. XII'), p('99-26-0001', 'Lain')];

describe('Kel. Sesi per tingkat', () => {
  it('tanpa pengaturan semua sesi 1', () => {
    expect(keCbt(PESERTA).map((c) => c.kelSesi)).toEqual([1, 1, 1, 1]);
  });

  it('mengikuti tingkat dari nama sheet; tingkat tak dikenal tetap 1', () => {
    expect(tingkatPeserta(PESERTA[1])).toBe('XI');
    expect(keCbt(PESERTA, { X: 1, XI: 2, XII: 3 }).map((c) => c.kelSesi)).toEqual([1, 2, 3, 1]);
  });

  it('tingkat yang tidak diatur memakai 1; nilai tidak sah diabaikan', () => {
    expect(keCbt(PESERTA, { XII: 2 }).map((c) => c.kelSesi)).toEqual([1, 1, 2, 1]);
    expect(keCbt(PESERTA, { XI: 0, XII: 1.5 }).map((c) => c.kelSesi)).toEqual([1, 1, 1, 1]);
  });

  it('dipakai di file CSV', () => {
    expect(tulisCbtCsv(keCbt(PESERTA, { XI: 2 })).split('\r\n')[2]).toBe('Siswa 92-26-0001,X-3,X-3,1,2,92-26-0001,AB12CD');
  });

  it('nomorSesi: hanya bilangan bulat 1 atau lebih', () => {
    expect([' 2 ', '1', 3].map(nomorSesi)).toEqual([2, 1, 3]);
    expect(['', '0', '-1', '1.5', 'dua'].map(nomorSesi)).toEqual([null, null, null, null, null]);
  });
});
