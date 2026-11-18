import { afterEach, describe, expect, it, vi } from 'vitest';
import { kirimMapel, panelTersedia } from '../../src/ui/panel';

// Lingkungan tes tanpa DOM: cukup tiruan document.querySelector untuk meta cbt-*.
function pasangMeta(isi: Record<string, string>): Record<string, { content: string }> {
  const meta = Object.fromEntries(Object.entries(isi).map(([k, v]) => [k, { content: v }]));
  vi.stubGlobal('document', {
    querySelector: (sel: string) => meta[/name="([^"]+)"/.exec(sel)?.[1] ?? ''] ?? null,
  });
  return meta;
}

const baris = [
  { category: 1, format: 'singleactivity', shortname: 'USBK-X-MATEMATIKA', fullname: 'MATEMATIKA X' },
  { category: 2, format: 'singleactivity', shortname: 'USBK-X-BINDO', fullname: 'BHS INDONESIA X' },
];

afterEach(() => vi.unstubAllGlobals());

describe('panel CBT Admin', () => {
  it('tidak tersedia di luar panel (tanpa meta)', () => {
    pasangMeta({});
    expect(panelTersedia()).toBe(false);
  });

  it('mengirim nomor hari, shortname, fullname dengan token CSRF lalu memperbarui token', async () => {
    const meta = pasangMeta({ 'cbt-mapel-url': '/cbtadmin/tools/test_manage/mapel_json', 'cbt-csrf': 'lama', 'cbt-csrf-header': 'X-CSRF-TOKEN' });
    expect(panelTersedia()).toBe(true);
    const ambil = vi.fn(async (_url: string, init?: RequestInit) => {
      const body = JSON.parse(String(init?.body));
      expect(body).toEqual({ awalan: 'H', mapel: [
        { category: 1, shortname: 'USBK-X-MATEMATIKA', fullname: 'MATEMATIKA X' },
        { category: 2, shortname: 'USBK-X-BINDO', fullname: 'BHS INDONESIA X' },
      ] });
      expect((init?.headers as Record<string, string>)['X-CSRF-TOKEN']).toBe('lama');
      return new Response(JSON.stringify({ ok: true, pesan: '2 mapel dibuat.', dibuat: 2, csrf: 'baru' }));
    });
    const h = await kirimMapel(baris, 'H', ambil as unknown as typeof fetch);
    expect(h).toMatchObject({ ok: true, dibuat: 2 });
    expect(meta['cbt-csrf'].content).toBe('baru');
  });

  it('memberi pesan jelas bila panel menjawab halaman login (bukan JSON)', async () => {
    pasangMeta({ 'cbt-mapel-url': '/x', 'cbt-csrf': 't' });
    const ambil = vi.fn(async () => new Response('<html>Masuk</html>'));
    await expect(kirimMapel(baris, 'H', ambil as unknown as typeof fetch)).rejects.toThrow(/Muat ulang halaman/);
  });
});
