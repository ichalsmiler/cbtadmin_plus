import './style.css';
import { muatTema, simpanTema, type Tema } from './ui/simpan';
import { pasangTabJadwal } from './ui/tab-jadwal';
import { pasangTabMoodle } from './ui/tab-moodle';
import { pasangTabMoving } from './ui/tab-moving';
import { pasangTabUser } from './ui/tab-user';

function pasangTab(): void {
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
}

function pasangTema(): void {
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

pasangTema();
pasangTab();
pasangTabJadwal(document.getElementById('tab-jadwal')!);
pasangTabUser(document.getElementById('tab-user')!);
pasangTabMoving(document.getElementById('tab-moving')!);
pasangTabMoodle(document.getElementById('tab-moodle')!);
