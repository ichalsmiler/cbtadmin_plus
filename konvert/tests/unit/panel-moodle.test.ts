import { afterEach, describe, expect, it, vi } from 'vitest';
import { PAKET_MOODLE, kirimKeMoodle, kirimMoodleTersedia } from '../../src/ui/panel';
import type { BarisMoodle } from '../../src/core/moodle';

function pasangMeta(isi: Record<string, string>): Record<string, { content: string }> {
  const meta = Object.fromEntries(Object.entries(isi).map(([k, v]) => [k, { content: v }]));
  vi.stubGlobal('document', { querySelector: (sel: string) => meta[/name="([^"]+)"/.exec(sel)?.[1] ?? ''] ?? null });
  return meta;
}

const siswa = (n: number): BarisMoodle[] =>
  Array.from({ length: n }, (_, i) => ({
    username: `u${i}`, password: 'P', firstname: `S${i}`, lastname: 'X-1', email: `u${i}@smada.id`,
    department: 'X-1', address: '1', course: ['USBK-X-BINDO'], tingkat: 'X',
  }));

afterEach(() => vi.unstubAllGlobals());

describe('kirim ke Moodle', () => {
  it('tidak tersedia di luar panel', () => {
    pasangMeta({});
    expect(kirimMoodleTersedia()).toBe(false);
  });

  it('mengirim bertahap per paket, menjumlah hasil, dan memperbarui token', async () => {
    const meta = pasangMeta({ 'cbt-moodle-url': '/k', 'cbt-csrf': 't0' });
    const token: string[] = [];
    const ambil = vi.fn(async (_u: string, init?: RequestInit) => {
      token.push((init?.headers as Record<string, string>)['X-CSRF-TOKEN']);
      const n = JSON.parse(String(init?.body)).users.length as number;
      return new Response(JSON.stringify({ dibuat: n, diperbarui: 0, enrol_baru: n, enrol_sudah: 0, gagal: [], tidak_ada: ['USBK-X-KKA'], csrf: `t${token.length}` }));
    });
    const kemajuan: number[] = [];
    const h = await kirimKeMoodle(siswa(PAKET_MOODLE + 5), (s) => kemajuan.push(s), ambil as unknown as typeof fetch);
    expect(ambil).toHaveBeenCalledTimes(2);
    expect(token).toEqual(['t0', 't1']);
    expect(meta['cbt-csrf'].content).toBe('t2');
    expect(h).toEqual({ dibuat: PAKET_MOODLE + 5, diperbarui: 0, enrolBaru: PAKET_MOODLE + 5, enrolSudah: 0, gagal: [], tidakAda: ['USBK-X-KKA'] });
    expect(kemajuan).toEqual([PAKET_MOODLE, PAKET_MOODLE + 5]);
  });

  it('berhenti dengan pesan jelas bila paket gagal', async () => {
    pasangMeta({ 'cbt-moodle-url': '/k', 'cbt-csrf': 't' });
    const ambil = vi.fn(async () => new Response(JSON.stringify({ ok: false, pesan: 'Gagal membuat akun Moodle: x' }), { status: 500 }));
    await expect(kirimKeMoodle(siswa(3), () => {}, ambil as unknown as typeof fetch)).rejects.toThrow(/Gagal membuat akun Moodle: x \(0 dari 3/);
  });
});
