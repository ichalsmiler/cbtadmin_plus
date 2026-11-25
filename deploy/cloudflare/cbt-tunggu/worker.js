// CBT HA: halaman "Mohon tunggu" saat server ujian tidak menjawab (mis. jeda ±3–4 menit sebelum server cadangan
// mengambil alih). Permintaan diteruskan apa adanya; hanya bila Cloudflare gagal menghubungi server (galat 520–526
// atau koneksi putus) siswa mendapat halaman yang memuat ulang sendiri tiap 30 detik.
// Route: cbtsrv2026.sman2makassar.sch.id/*  — atur "Fail open" supaya kuota gratis habis tidak mengganggu ujian.

const HALAMAN = `<!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="30">
<title>Mohon tunggu</title>
<style>body{font-family:system-ui,sans-serif;background:#f1f3f7;color:#2a2e3d;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px}
main{background:#fff;border-radius:12px;padding:32px;max-width:440px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:1.3rem;margin:0 0 .5rem}</style>
</head><body><main><h1>Mohon tunggu sebentar</h1>
<p>Server ujian sedang dipindahkan. Halaman ini <strong>memuat ulang sendiri tiap 30 detik</strong>.</p>
<p>Jangan tutup browser dan jangan logout. Jawaban yang sudah tersimpan aman. Bila lebih dari 5 menit, lapor ke pengawas ruang.</p>
</main></body></html>`;

function tunggu(request) {
  const html = request.method === 'GET' && (request.headers.get('accept') || '').includes('text/html');
  return new Response(html ? HALAMAN : 'Server ujian sedang dipindahkan, coba lagi sebentar.', {
    status: 503,
    headers: {
      'content-type': html ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8',
      'cache-control': 'no-store',
      'retry-after': '30',
    },
  });
}

export default {
  async fetch(request) {
    try {
      const res = await fetch(request);
      return res.status >= 520 && res.status <= 526 ? tunggu(request) : res;
    } catch (e) {
      return tunggu(request);
    }
  },
};
