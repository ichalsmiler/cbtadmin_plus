import './style.css';
import { muatTema, simpanTema, type Tema } from './ui/simpan';
import { pasangTabJadwal } from './ui/tab-jadwal';
import { pasangTabKamus } from './ui/tab-kamus';
import { pasangTabMoodle } from './ui/tab-moodle';
import { pasangTabMoving } from './ui/tab-moving';
import { pasangTabUser } from './ui/tab-user';
import { kirimSesiPanel, sesiDariPanel } from './ui/panel';
import { aturSimpanSesiJauh, muatSesi, resetDataJadwal, resetDataUser, simpanSesi } from './ui/simpan';
import { hapusBerkas } from './ui/berkas';

/** Tab menu Jadwal di panel: jadwal → mapel dan kamus mapel yang dipakainya. */
const TAB_JADWAL = ['tab-btn-jadwal', 'tab-btn-kamus'];

/**
 * Di panel CBT Admin tiap bagian punya menu sendiri: `?bagian=jadwal` hanya Jadwal + Kamus Mapel,
 * `?bagian=user` tab lainnya (User → CBTAdmin, Kelas Moving, User → Moodle). Tanpa parameter: semua tab.
 */
function saringBagian(): void {
  const bagian = new URLSearchParams(location.search).get('bagian');
  if (bagian !== 'jadwal' && bagian !== 'user') return;
  for (const b of Array.from(document.querySelectorAll<HTMLButtonElement>('.tab button'))) {
    if (TAB_JADWAL.includes(b.id) === (bagian === 'jadwal')) continue;
    b.remove();
    document.getElementById(b.getAttribute('aria-controls')!)?.setAttribute('hidden', '');
  }
}

function pasangTab(): void {
  saringBagian();
  const tombol = Array.from(document.querySelectorAll<HTMLButtonElement>('.tab button'));
  const pilih = (b: HTMLButtonElement): void => {
    for (const lain of tombol) {
      const aktif = lain === b;
      lain.setAttribute('aria-selected', String(aktif));
      lain.tabIndex = aktif ? 0 : -1;
      document.getElementById(lain.getAttribute('aria-controls')!)!.hidden = !aktif;
    }
    b.scrollIntoView({ block: 'nearest', inline: 'nearest' }); // deretan tab bisa digulir di layar sempit
  };
  tombol.forEach((b, i) => {
    b.addEventListener('click', () => pilih(b));
    // Pola tab standar: panah kiri/kanan, Home, End berpindah tab.
    b.addEventListener('keydown', (e) => {
      const n = tombol.length;
      const tujuan = { ArrowRight: (i + 1) % n, ArrowLeft: (i - 1 + n) % n, Home: 0, End: n - 1 }[e.key];
      if (tujuan === undefined) return;
      e.preventDefault();
      pilih(tombol[tujuan]);
      tombol[tujuan].focus();
    });
  });
  if (tombol.length > 0) pilih(tombol[0]);
}

/** Tema panel CBT Admin (localStorage `cbt-tema`, satu asal dengan bingkai ini); bawaan terang. */
function temaPanel(): Tema {
  try {
    return localStorage.getItem('cbt-tema') === 'dark' ? 'gelap' : 'terang';
  } catch {
    return 'terang';
  }
}

function pasangTema(): void {
  // Di dalam panel CBT Admin: ikuti tombol tema panel (event storage terpicu saat panel menggantinya).
  if (window.self !== window.top) {
    document.documentElement.classList.add('di-panel');
    const ikuti = (): void => {
      document.documentElement.dataset.tema = temaPanel();
    };
    window.addEventListener('storage', (e) => {
      if (e.key === 'cbt-tema') ikuti();
    });
    ikuti();
    return;
  }
  const tombol = document.getElementById('tombol-tema') as HTMLButtonElement;
  const sistem = matchMedia('(prefers-color-scheme: dark)');
  const terapkan = (): void => {
    const tema: Tema = muatTema() ?? (sistem.matches ? 'gelap' : 'terang');
    document.documentElement.dataset.tema = tema;
    tombol.textContent = tema === 'gelap' ? 'Tema terang' : 'Tema gelap';
  };
  tombol.addEventListener('click', () => {
    simpanTema(document.documentElement.dataset.tema === 'gelap' ? 'terang' : 'gelap');
    terapkan();
  });
  sistem.addEventListener('change', terapkan);
  terapkan();
}

/** Di panel: pengaturan sesi dari menu Sesi menang atas yang tersimpan di browser; perubahan di Konvert dikirim balik. */
function sinkronSesiPanel(): void {
  const panel = sesiDariPanel();
  if (panel) {
    const lokal = muatSesi();
    if (Object.entries(panel).some(([t, n]) => lokal[t] !== n)) simpanSesi({ ...lokal, ...panel });
  }
  if (document.querySelector('meta[name="cbt-sesi-url"]')) aturSimpanSesiJauh((s) => void kirimSesiPanel(s));
}

/**
 * Tombol Reset di atas tab (menu Jadwal atau Konvert User di panel): berkas yang diunggah tetap tersimpan di browser
 * sampai tombol ini ditekan. Tanpa parameter bagian (berkas lepas): reset keduanya.
 */
function pasangReset(): void {
  const bagian = new URLSearchParams(location.search).get('bagian');
  const nav = document.querySelector<HTMLElement>('.tab');
  if (!nav) return;
  const jadwal = bagian !== 'user';
  const user = bagian !== 'jadwal';
  const isi = [jadwal ? 'berkas jadwal, hasil konversi, dan pilihan/edit mapel' : '', user ? 'data peserta, file kelas moving, dan pencocokannya' : '']
    .filter(Boolean)
    .join('; ');
  const tombol = document.createElement('button');
  tombol.type = 'button';
  tombol.className = 'tombol';
  tombol.textContent = 'Reset';
  tombol.addEventListener('click', async () => {
    if (!confirm(`Reset menu ini?\n\nYang dihapus dari browser ini: ${isi}.\nKamus mapel dan pengaturan sesi tetap. Data yang sudah dikirim ke panel/Moodle tidak berubah.`)) return;
    if (jadwal) {
      resetDataJadwal();
      await hapusBerkas('jadwal');
    }
    if (user) {
      resetDataUser();
      await hapusBerkas('moving');
    }
    location.reload();
  });
  const bantu = document.createElement('span');
  bantu.className = 'bantu';
  bantu.textContent = 'Berkas yang sudah diunggah tetap tersimpan di browser ini (juga setelah pindah menu atau memuat ulang) sampai Reset ditekan.';
  const bar = document.createElement('div');
  bar.className = 'baris-kontrol bar-reset';
  bar.append(tombol, bantu);
  nav.before(bar);
}

pasangTema();
sinkronSesiPanel();
pasangReset();
pasangTab();
// Hanya tab yang tampil di menu ini yang dipasang (di panel: Jadwal + Kamus, atau tab Konvert User), supaya
// berkas tersimpan menu lain tidak ikut dibaca ulang di sini.
const pasang: [string, (akar: HTMLElement) => void][] = [
  ['jadwal', pasangTabJadwal],
  ['user', pasangTabUser],
  ['moving', pasangTabMoving],
  ['moodle', pasangTabMoodle],
  ['kamus', pasangTabKamus],
];
for (const [id, f] of pasang) {
  if (document.getElementById(`tab-btn-${id}`)) f(document.getElementById(`tab-${id}`)!);
}
