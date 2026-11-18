import { afterEach, describe, expect, it, vi } from 'vitest';
import { barisKirimPeserta, kirimPeserta, kirimPesertaTersedia } from '../../src/ui/panel';
import type { PesertaCBT } from '../../src/core/tipe';

function pasangMeta(isi: Record<string, string>): Record<string, { content: string }> {
  const meta = Object.fromEntries(Object.entries(isi).map(([k, v]) => [k, { content: v }]));
  vi.stubGlobal('document', {
    querySelector: (sel: string) => meta[/name="([^"]+)"/.exec(sel)?.[1] ?? ''] ?? null,
  });
  return meta;
}

const peserta: PesertaCBT[] = [
  { namaPeserta: 'Ani', namaRombel: 'X-1', kelasJurusan: 'X-1', ruang: 3, kelSesi: 2, username: '2601001', password: 'HJ86KL' },
  { namaPeserta: 'Budi', namaRombel: '', kelasJurusan: 'XI-2', ruang: null, kelSesi: null, username: '2601002', password: '' },
];

afterEach(() => vi.unstubAllGlobals());

describe('kirim peserta ke Data CBTAdmin', () => {
  it('memetakan kolom ke kunci impor panel', () => {
    expect(barisKirimPeserta(peserta[0])).toEqual({ nama: 'Ani', kelas: 'X-1', ruang: '3', sesi: '2', username: '2601001', password: 'HJ86KL' });
    expect(barisKirimPeserta(peserta[1])).toEqual({ nama: 'Budi', kelas: 'XI-2', ruang: '', sesi: '', username: '2601002', password: '' });
  });

  it('tidak tersedia di luar panel', () => {
    pasangMeta({});
    expect(kirimPesertaTersedia()).toBe(false);
  });

  it('mengirim dengan token CSRF lalu memperbarui token', async () => {
    const meta = pasangMeta({ 'cbt-peserta-url': '/cbtadmin/daftar_peserta/cbtadmin/impor_json', 'cbt-csrf': 'lama' });
    expect(kirimPesertaTersedia()).toBe(true);
    const ambil = vi.fn(async (_url: string, init?: RequestInit) => {
      expect(JSON.parse(String(init?.body)).peserta).toHaveLength(2);
      expect((init?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('lama');
      return new Response(JSON.stringify({ ok: true, pesan: '2 peserta baru.', baru: 2, csrf: 'baru' }));
    });
    expect(await kirimPeserta(peserta, ambil as unknown as typeof fetch)).toMatchObject({ ok: true, baru: 2 });
    expect(meta['cbt-csrf'].content).toBe('baru');
  });

  it('pesan jelas bila sesi login habis', async () => {
    pasangMeta({ 'cbt-peserta-url': '/x', 'cbt-csrf': 't' });
    const ambil = vi.fn(async () => new Response('<html>Masuk</html>'));
    await expect(kirimPeserta(peserta, ambil as unknown as typeof fetch)).rejects.toThrow(/sesi login/);
  });
});
