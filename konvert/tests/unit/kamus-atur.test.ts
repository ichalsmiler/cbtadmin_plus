import { describe, expect, it } from 'vitest';
import { cariAlias, cariMapel, hapusMapel, kamusAwal, kamusDariJson, pecahAlias, ubahMapel } from '../../src/core/kamus';
import { GalatFormat } from '../../src/core/tipe';

const awal = kamusAwal();

describe('pecahAlias', () => {
  it('memisah koma/titik koma/baris, menormalisasi, membuang ganda', () => {
    expect(pecahAlias('Bhs. Indo, bhs indo;\n , B Indo')).toEqual(['bahasa indo', 'b indo']);
  });
});

describe('ubahMapel', () => {
  it('mengganti nama lengkap dan alias tanpa mengubah kamus asli', () => {
    const k = ubahMapel(awal, 'BINDO', { kode: 'BINDO', namaLengkap: 'Bahasa Indonesia', alias: ['bahasa indonesia', 'b indo'] });
    expect(cariMapel(k, 'BINDO')).toEqual({ kode: 'BINDO', namaLengkap: 'Bahasa Indonesia', alias: ['bahasa indonesia', 'b indo'] });
    expect(cariAlias(k, 'B. Indo')).toEqual(['BINDO']);
    expect(cariAlias(awal, 'B. Indo')).toBeNull();
  });

  it('mengganti kode juga di grup', () => {
    const k = ubahMapel(awal, 'PAI', { kode: 'agama islam', namaLengkap: 'AGAMA ISLAM', alias: [] });
    expect(k.grup[0].kodeAnggota).toContain('AGAMA ISLAM');
    expect(k.grup[0].kodeAnggota).not.toContain('PAI');
  });

  it('menolak kode ganda dan alias milik mapel lain', () => {
    expect(() => ubahMapel(awal, 'BINDO', { kode: 'BING', namaLengkap: 'X', alias: [] })).toThrow(GalatFormat);
    const aliasMtk = cariMapel(awal, 'MATEMATIKA')!.alias[0];
    expect(() => ubahMapel(awal, 'BINDO', { kode: 'BINDO', namaLengkap: 'X', alias: [aliasMtk] })).toThrow(/sudah dipakai MATEMATIKA/);
    expect(() => ubahMapel(awal, 'BINDO', { kode: 'BINDO', namaLengkap: '', alias: [] })).toThrow(GalatFormat);
  });
});

describe('hapusMapel', () => {
  it('menghapus mapel biasa', () => {
    expect(cariMapel(hapusMapel(awal, 'BING'), 'BING')).toBeUndefined();
    expect(cariMapel(awal, 'BING')).toBeDefined();
  });
  it('menolak anggota grup', () => {
    expect(() => hapusMapel(awal, 'PAI')).toThrow(/grup/);
  });
});

describe('kamusDariJson', () => {
  it('membaca hasil ekspor', () => {
    expect(kamusDariJson(JSON.stringify(awal))).toEqual(awal);
  });
  it('menolak berkas rusak atau kode ganda', () => {
    expect(() => kamusDariJson('{')).toThrow(/JSON/);
    expect(() => kamusDariJson('{"versi":2}')).toThrow(/kamus/);
    const ganda = { ...awal, mapel: [...awal.mapel, awal.mapel[0]] };
    expect(() => kamusDariJson(JSON.stringify(ganda))).toThrow(/ganda/);
  });
});
