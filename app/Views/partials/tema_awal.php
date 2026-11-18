<?php // Pasang tema sebelum halaman digambar agar tidak berkedip. Bawaan terang (DESIGN.md); pilihan disimpan per browser. ?>
<script>
(function () {
    'use strict';
    var tema = 'light';
    try { tema = localStorage.getItem('cbt-tema') === 'dark' ? 'dark' : 'light'; } catch (e) { /* penyimpanan diblokir: tetap terang */ }
    document.documentElement.setAttribute('data-bs-theme', tema);
    // Tanda JS aktif: isi tabel besar disembunyikan sampai DataTables selesai menyusunnya (panel.css .dt-siap).
    document.documentElement.classList.add('js');
})();
</script>
