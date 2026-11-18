import { afterEach, describe, expect, it, vi } from 'vitest';
import { kirimSesiPanel, sesiDariPanel } from '../../src/ui/panel';

function pasangMeta(isi: Record<string, string>): Record<string, { content: string }> {
  const meta = Object.fromEntries(Object.entries(isi).map(([k, v]) => [k, { content: v }]));
  vi.stubGlobal('document', { querySelector: (sel: string) => meta[/name="([^"]+)"/.exec(sel)?.[1] ?? ''] ?? null });
  return meta;
}

afterEach(() => vi.unstubAllGlobals());

describe('sesi bersama menu Sesi di panel', () => {
  it('membaca sesi dari meta; kosong/rusak = null', () => {
    pasangMeta({ 'cbt-sesi-tingkat': '{"XII":1,"X":2,"XI":3,"IX":"a"}' });
    expect(sesiDariPanel()).toEqual({ X: 2, XI: 3, XII: 1 });
    pasangMeta({ 'cbt-sesi-tingkat': '{}' });
    expect(sesiDariPanel()).toBeNull();
    pasangMeta({ 'cbt-sesi-tingkat': '{' });
    expect(sesiDariPanel()).toBeNull();
    pasangMeta({});
    expect(sesiDariPanel()).toBeNull();
  });

  it('mengirim X/XI/XII saja dengan token CSRF lalu memperbarui token', async () => {
    const meta = pasangMeta({ 'cbt-sesi-url': '/s', 'cbt-csrf': 'lama' });
    const ambil = vi.fn(async (_u: string, init?: RequestInit) => {
      expect(JSON.parse(String(init?.body))).toEqual({ sesi: { X: 1, XI: 2, XII: 3 } });
      return new Response(JSON.stringify({ ok: true, csrf: 'baru' }));
    });
    expect(await kirimSesiPanel({ X: 1, XI: 2, XII: 3, IX: 4 }, ambil as unknown as typeof fetch)).toBe(true);
    expect(meta['cbt-csrf'].content).toBe('baru');
  });

  it('di luar panel tidak mengirim apa pun', async () => {
    pasangMeta({});
    const ambil = vi.fn();
    expect(await kirimSesiPanel({ X: 1 }, ambil as unknown as typeof fetch)).toBe(false);
    expect(ambil).not.toHaveBeenCalled();
  });
});
