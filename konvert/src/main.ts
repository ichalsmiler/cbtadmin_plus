import './style.css';
import { muatTema, simpanTema, type Tema } from './ui/simpan';
import { pasangTabJadwal } from './ui/tab-jadwal';
import { pasangTabKamus } from './ui/tab-kamus';
import { pasangTabMoodle } from './ui/tab-moodle';
import { pasangTabMoving } from './ui/tab-moving';
import { pasangTabUser } from './ui/tab-user';
import { kirimSesiPanel, sesiDariPanel } from './ui/panel';
import { aturSimpanSesiJauh, muatSesi, simpanSesi } from './ui/simpan';

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

pasangTema();
sinkronSesiPanel();
pasangTab();
pasangTabJadwal(document.getElementById('tab-jadwal')!);
pasangTabUser(document.getElementById('tab-user')!);
pasangTabMoving(document.getElementById('tab-moving')!);
pasangTabMoodle(document.getElementById('tab-moodle')!);
pasangTabKamus(document.getElementById('tab-kamus')!);
