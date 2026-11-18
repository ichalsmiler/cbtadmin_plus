// Tab "Kamus Mapel": kode / nama lengkap / alias yang dipakai tab Jadwal untuk mengenali nama mapel di jadwal.
// Ubah, tambah, hapus, Ekspor/Impor JSON, dan Kembalikan ke awal. Tersimpan di browser ini (localStorage).

import { cariMapel, hapusMapel, kamusAwal, kamusDariJson, pecahAlias, tambahMapel, ubahMapel } from '../core/kamus';
import { GalatFormat, type Kamus, type Mapel, type Peringatan } from '../core/tipe';
import { muatKamus, saatKamusBerubah, simpanKamus, unduhFile } from './simpan';
import { daftarPesan, el, tabel } from './tabel';

export function pasangTabKamus(akar: HTMLElement): void {
  let kamus: Kamus = muatKamus();
  let cari = '';
  let pesan: Peringatan[] = [];

  saatKamusBerubah((k) => {
    kamus = k;
    gambar();
  });

  /** Simpan perubahan; galat ditampilkan di atas tabel tanpa mengubah kamus. */
  function terapkan(ubah: () => Kamus, berhasil: string): void {
    try {
      const baru = ubah();
      pesan = [{ tingkat: 'info', pesan: berhasil }];
      simpanKamus(baru); // memicu gambar() lewat saatKamusBerubah
    } catch (e) {
      pesan = [{ tingkat: 'galat', pesan: e instanceof GalatFormat ? e.message : String(e) }];
      gambar();
    }
  }

  function cocok(m: Mapel): boolean {
    const q = cari.trim().toLowerCase();
    return !q || m.kode.toLowerCase().includes(q) || m.namaLengkap.toLowerCase().includes(q) || m.alias.some((a) => a.includes(q));
  }

  function idAman(kode: string): string {
    return kode.replace(/[^A-Za-z0-9]/g, '_');
  }

  /** Sel input; perubahan langsung disimpan (kode, nama lengkap, atau alias dipisah koma). */
  function selMapel(m: Mapel, kolom: 'kode' | 'namaLengkap' | 'alias'): HTMLElement {
    const nilai = kolom === 'alias' ? m.alias.join(', ') : m[kolom];
    const input = el('input', {
      type: 'text',
      id: `kamus-${kolom}-${idAman(m.kode)}`,
      class: 'sel-edit',
      value: nilai,
      'aria-label': `${kolom === 'namaLengkap' ? 'Nama lengkap' : kolom === 'kode' ? 'Kode' : 'Alias'} untuk ${m.kode}`,
    });
    input.addEventListener('change', () => {
      const asal = cariMapel(kamus, m.kode)!;
      const baru = {
        kode: kolom === 'kode' ? input.value : asal.kode,
        namaLengkap: kolom === 'namaLengkap' ? input.value : asal.namaLengkap,
        alias: kolom === 'alias' ? pecahAlias(input.value) : asal.alias,
      };
      terapkan(() => ubahMapel(kamus, m.kode, baru), `${m.kode} diperbarui.`);
    });
    return input;
  }

  function tombolHapus(m: Mapel): HTMLElement {
    const b = el('button', { class: 'tombol', type: 'button', 'aria-label': `Hapus ${m.kode}` }, 'Hapus');
    b.addEventListener('click', () => {
      if (!confirm(`Hapus ${m.kode} (${m.namaLengkap}) dari kamus?`)) return;
      terapkan(() => hapusMapel(kamus, m.kode), `${m.kode} dihapus dari kamus.`);
    });
    return b;
  }

  function kartuTambah(): HTMLElement {
    const kode = el('input', { type: 'text', id: 'kamus-baru-kode', placeholder: 'Kode, mis. KKA', size: '14', 'aria-label': 'Kode mapel baru' });
    const nama = el('input', { type: 'text', id: 'kamus-baru-nama', placeholder: 'Nama lengkap', size: '24', 'aria-label': 'Nama lengkap mapel baru' });
    const alias = el('input', { type: 'text', id: 'kamus-baru-alias', placeholder: 'Alias, pisah koma (opsional)', size: '30', 'aria-label': 'Alias mapel baru' });
    const tambah = el('button', { class: 'tombol utama', type: 'button' }, 'Tambah');
    tambah.addEventListener('click', () => {
      const k = kode.value.trim().toUpperCase().replace(/\s+/g, ' ');
      terapkan(() => {
        const dasar = tambahMapel(kamus, kode.value, nama.value);
        const daftar = pecahAlias(alias.value);
        if (!daftar.length) return dasar;
        const m = cariMapel(dasar, k)!;
        return ubahMapel(dasar, k, { kode: k, namaLengkap: m.namaLengkap, alias: [...m.alias, ...daftar] });
      }, `${k} ditambahkan ke kamus.`);
    });
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, 'Tambah mapel'),
      el('div', { class: 'baris-kontrol' }, kode, nama, alias, tambah),
      el('p', { class: 'bantu' }, 'Kode menjadi bagian shortname (USBK-XII-<kode>), nama lengkap menjadi fullname. Alias = tulisan lain di jadwal yang berarti mapel ini.'),
    );
  }

  function kartuBerkas(): HTMLElement {
    const ekspor = el('button', { class: 'tombol', type: 'button' }, 'Ekspor JSON');
    ekspor.addEventListener('click', () => unduhFile('kamus-mapel.json', new TextEncoder().encode(JSON.stringify(kamus, null, 2)), 'application/json'));

    const input = el('input', { type: 'file', accept: '.json,application/json', id: 'kamus-impor', class: 'sr-only' });
    input.addEventListener('change', async () => {
      const f = input.files?.[0];
      input.value = '';
      if (!f) return;
      const teks = await f.text();
      if (!confirm(`Ganti seluruh kamus di browser ini dengan isi ${f.name}?`)) return;
      terapkan(() => kamusDariJson(teks), `Kamus diimpor dari ${f.name}.`);
    });

    const awal = el('button', { class: 'tombol', type: 'button' }, 'Kembalikan ke awal');
    awal.addEventListener('click', () => {
      if (!confirm('Kembalikan kamus ke isi bawaan? Mapel dan alias yang ditambahkan di browser ini hilang. Ekspor JSON dulu bila perlu.')) return;
      terapkan(() => kamusAwal(), 'Kamus dikembalikan ke isi bawaan.');
    });

    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, 'Simpan dan pindahkan'),
      el('div', { class: 'baris-kontrol' }, ekspor, el('label', { class: 'tombol', for: 'kamus-impor' }, 'Impor JSON…'), input, awal),
      el('p', { class: 'bantu' }, 'Kamus tersimpan di browser ini saja. Ekspor ke berkas JSON untuk dipakai di komputer atau browser lain, lalu Impor di sana.'),
    );
  }

  function kartuDaftar(): HTMLElement {
    const kotakCari = el('input', { type: 'search', id: 'kamus-cari', placeholder: 'Cari kode, nama, atau alias…', value: cari, 'aria-label': 'Cari di kamus' });
    kotakCari.addEventListener('input', () => {
      cari = kotakCari.value;
      gambar();
    });
    const tampil = [...kamus.mapel].sort((a, b) => a.kode.localeCompare(b.kode)).filter(cocok);
    const grup = kamus.grup.map((g) => `"${g.alias.join(', ')}" → ${g.kodeAnggota.join(' + ')}`);
    return el(
      'div',
      { class: 'kartu' },
      el('h2', {}, 'Kamus mapel'),
      daftarPesan(pesan),
      el('div', { class: 'baris-kontrol' }, kotakCari),
      tabel(
        [{ judul: 'Kode' }, { judul: 'Nama lengkap' }, { judul: 'Alias (pisah koma)' }, { judul: '' }],
        tampil.map((m) => ({ sel: [selMapel(m, 'kode'), selMapel(m, 'namaLengkap'), selMapel(m, 'alias'), tombolHapus(m)] })),
        'Kamus mapel',
      ),
      el('p', { class: 'jumlah' }, `${tampil.length} dari ${kamus.mapel.length} mapel.`),
      grup.length ? el('p', { class: 'bantu' }, `Grup (satu nama di jadwal menjadi beberapa mapel): ${grup.join('; ')}.`) : null,
      el('p', { class: 'bantu' }, 'Ubah isi sel lalu tekan Enter atau pindah sel untuk menyimpan. Mengganti kode tidak mengubah mapel yang sudah dibuat di Moodle.'),
    );
  }

  function gambar(): void {
    const fokus = document.activeElement?.id;
    const posisi = fokus === 'kamus-cari' ? (document.activeElement as HTMLInputElement).selectionStart : null;
    akar.replaceChildren(kartuDaftar(), kartuTambah(), kartuBerkas());
    if (fokus) {
      const e = document.getElementById(fokus) as HTMLInputElement | null;
      e?.focus();
      if (e && posisi !== null) e.setSelectionRange(posisi, posisi);
    }
  }

  gambar();
}
