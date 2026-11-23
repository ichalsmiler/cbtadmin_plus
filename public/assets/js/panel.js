/* CBT Admin: perilaku umum panel. */
(function () {
    'use strict';

    // ---------------------------------------------------------------- Mode terang / gelap
    // Tema awal dipasang di <head> (partials/tema_awal); tombol di bilah atas mengganti dan menyimpannya.
    var temaBtn = document.querySelector('[data-tema-toggle]');
    var pasangTema = function (tema) {
        document.documentElement.setAttribute('data-bs-theme', tema);
        if (!temaBtn) { return; }
        var gelap = tema === 'dark';
        temaBtn.querySelector('.bi').className = 'bi ' + (gelap ? 'bi-sun' : 'bi-moon-stars');
        temaBtn.setAttribute('aria-label', gelap ? 'Ganti ke mode terang' : 'Ganti ke mode gelap');
    };
    if (temaBtn) {
        pasangTema(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light');
        temaBtn.addEventListener('click', function () {
            var tema = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            pasangTema(tema);
            try { localStorage.setItem('cbt-tema', tema); } catch (e) { /* tidak tersimpan: berlaku sampai halaman ditutup */ }
        });
    }

    // ---------------------------------------------------------------- Perubahan belum disimpan
    // Isian formulir POST (pengaturan, nama ruang, absensi, ...) yang diubah lalu ditinggal tanpa
    // Simpan memunculkan peringatan browser. Tidak berlaku untuk filter (GET), centang baris tabel
    // massal (data-bulk), formulir di modal, atau elemen/formulir ber-data-no-dirty.
    var dirty = false;
    function watchDirty(event) {
        var el = event.target;
        var form = el && el.form;
        if (!form || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') { return; }
        if (form.matches('[data-bulk], [data-no-dirty]') || el.matches('[data-no-dirty]') || form.closest('.modal')) { return; }
        dirty = true;
    }
    document.addEventListener('input', watchDirty);
    document.addEventListener('change', watchDirty);
    // Dikirim (dan tidak dibatalkan handler lain, mis. konfirmasi): perubahan dianggap tersimpan.
    // Dipasang lewat setTimeout supaya berjalan setelah handler submit lain di berkas ini.
    setTimeout(function () {
        document.addEventListener('submit', function (event) { if (!event.defaultPrevented) { dirty = false; } });
    }, 0);
    window.addEventListener('beforeunload', function (event) {
        if (!dirty) { return; }
        event.preventDefault();
        event.returnValue = 'Perubahan belum disimpan.';
    });

    // ---------------------------------------------------------------- Konfirmasi
    var pendingAction = null;
    function confirmThen(text, action) {
        pendingAction = action;
        document.getElementById('confirmText').textContent = text;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmModal')).show();
    }
    var okButton = document.getElementById('confirmOk');
    if (okButton) {
        okButton.addEventListener('click', function () {
            var action = pendingAction;
            pendingAction = null;
            dirty = false;  // aksi ini mengirim formulir / pindah halaman dengan sengaja
            if (action) { action(); }
        });
    }

    // Formulir dengan data-confirm="...": minta konfirmasi sebelum dikirim.
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form.dataset && form.dataset.confirm) {
            event.preventDefault();
            confirmThen(form.dataset.confirm, function () { form.submit(); });
            return;
        }
        if (form.matches && form.matches('[data-bulk]')) { addOffPageChecks(form); }
    });

    // Tombol data-post="url": kirim POST lewat #postForm setelah konfirmasi.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-post]');
        if (!button) { return; }
        confirmThen(button.dataset.confirmText || 'Lanjutkan?', function () {
            var form = document.getElementById('postForm');
            form.querySelectorAll('.post-extra').forEach(function (el) { el.remove(); });
            var fields = JSON.parse(button.dataset.fields || '{}');
            Object.keys(fields).forEach(function (name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = fields[name];
                hidden.className = 'post-extra';
                form.appendChild(hidden);
            });
            form.action = button.dataset.post;
            form.submit();
        });
    });

    // Tombol submit dengan data-confirm-button di formulir massal.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-confirm-button]');
        if (!button) { return; }
        event.preventDefault();
        var form = button.form;
        confirmThen(button.dataset.confirmButton, function () {
            addOffPageChecks(form);
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = button.name;
            hidden.value = button.value;
            form.appendChild(hidden);
            form.submit();
        });
    });

    // ---------------------------------------------------------------- Modal isian
    // Tombol data-fill-modal="#id" data-fill='{"nama":"..."}' mengisi input bernama sama.
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-fill-modal], [data-ruang]');
        if (!button) { return; }
        var modal = document.querySelector(button.dataset.fillModal || '#ruangModal');
        var data = JSON.parse(button.dataset.fill || button.dataset.ruang);
        Object.keys(data).forEach(function (name) {
            var field = modal.querySelector('[name="' + name + '"]');
            if (field) { field.value = data[name] === null ? '' : data[name]; }
        });
        bootstrap.Modal.getOrCreateInstance(modal).show();
    });

    // ---------------------------------------------------------------- Pratinjau PDF
    // Tautan a[data-pdf] dan formulir form[data-pdf] (GET) dibuka di modal dulu; tombol Unduh
    // meminta berkas yang sama dengan ?unduh=1 (Content-Disposition: attachment).
    function showPdf(url) {
        var modal = document.getElementById('pdfModal');
        var frame = document.getElementById('pdfFrame');
        var loading = document.getElementById('pdfLoading');
        loading.classList.remove('d-none');
        var download = document.getElementById('pdfDownload');
        var setDownload = function (u) { download.href = u + (u.indexOf('?') < 0 ? '?' : '&') + 'unduh=1'; };
        frame.onload = function () {
            loading.classList.add('d-none');
            // Isi bingkai bisa berpindah (mis. daftar kartu per ruang → PDF ruang itu): Unduh ikut berkas yang tampil.
            try {
                var shown = frame.contentWindow.location.href;
                if (shown && shown !== 'about:blank') { setDownload(shown); }
            } catch (e) { /* lain asal: biarkan */ }
        };
        frame.src = url;
        setDownload(url);
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }
    var pdfModal = document.getElementById('pdfModal');
    if (pdfModal) {
        pdfModal.addEventListener('hidden.bs.modal', function () { document.getElementById('pdfFrame').src = 'about:blank'; });
        document.getElementById('pdfPrint').addEventListener('click', function () {
            var frame = document.getElementById('pdfFrame');
            try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { window.open(frame.src, '_blank'); }
        });
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-pdf]');
        if (!link || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
        event.preventDefault();
        showPdf(link.href);
    });
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.matches || !form.matches('form[data-pdf]')) { return; }
        // Tombol data-no-pdf (cetak lewat browser): biarkan formulir terkirim biasa ke tab baru.
        if (event.submitter && event.submitter.hasAttribute('data-no-pdf')) { return; }
        event.preventDefault();
        var submitter = event.submitter;
        var action = (submitter && submitter.getAttribute('formaction')) || form.action;
        var params = new URLSearchParams(new FormData(form, submitter || undefined));
        var query = params.toString();
        showPdf(action + (query ? (action.indexOf('?') < 0 ? '?' : '&') + query : ''));
    });

    // ---------------------------------------------------------------- Tabel
    var tables = [];
    document.querySelectorAll('table.datatable').forEach(function (el) {
        // Isi tabel tampil lagi setelah disusun (atau langsung bila DataTables tidak termuat).
        if (typeof DataTable === 'undefined') { el.classList.add('dt-siap'); return; }
        tables.push(new DataTable(el, {
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100, 500],
            order: [[parseInt(el.dataset.orderCol || '0', 10), el.dataset.orderDir === 'desc' ? 'desc' : 'asc']],
            language: {
                search: 'Cari:', lengthMenu: 'Tampilkan _MENU_ baris',
                info: '_START_–_END_ dari _TOTAL_', infoEmpty: 'Tidak ada data',
                infoFiltered: '(disaring dari _MAX_)', zeroRecords: 'Tidak ada yang cocok',
                emptyTable: el.dataset.kosong || 'Tidak ada data untuk pilihan ini',
                paginate: { first: '«', last: '»', next: '›', previous: '‹' }
            }
        }));
        // data-toolbar="#id": pindahkan "Tampilkan n baris" dan "Cari" ke baris filter itu (sejajar).
        var toolbar = el.dataset.toolbar && document.querySelector(el.dataset.toolbar);
        var wrap = el.closest('.dt-container');
        if (toolbar && wrap) {
            var length = wrap.querySelector('.dt-length');
            var search = wrap.querySelector('.dt-search');
            if (length) {
                // Jangan ikut terkirim saat formulir filter (GET) dikirim.
                length.querySelectorAll('select').forEach(function (s) { s.removeAttribute('name'); });
                toolbar.appendChild(length);
            }
            if (search) { search.classList.add('ms-auto'); toolbar.appendChild(search); }
            // Baris atas DataTables yang kini kosong disembunyikan.
            var top = wrap.querySelector('.dt-layout-row');
            if (top && !top.querySelector('.dt-length, .dt-search, .dt-info, .dt-paging, table')) { top.classList.add('d-none'); }
        }
        el.classList.add('dt-siap');
    });

    function tableOf(form) {
        return tables.filter(function (t) { return form.contains(t.table().node()); })[0];
    }

    // Baris di halaman lain tidak ada di DOM: sertakan centangnya sebagai input tersembunyi.
    function addOffPageChecks(form) {
        form.querySelectorAll('.bulk-extra').forEach(function (el) { el.remove(); });
        var table = tableOf(form);
        if (!table) { return; }
        table.$('input[name="ids[]"]:checked').each(function () {
            if (document.body.contains(this)) { return; }
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'ids[]';
            hidden.value = this.value;
            hidden.className = 'bulk-extra';
            form.appendChild(hidden);
        });
    }

    // Centang semua baris yang lolos pencarian, di semua halaman.
    document.addEventListener('change', function (event) {
        var master = event.target.closest('[data-check-all]');
        if (!master) { return; }
        var table = tableOf(master.form);
        if (table) {
            table.$('input[name="ids[]"]', { search: 'applied' }).prop('checked', master.checked);
        }
    });

    // ---------------------------------------------------------------- Kelas mengikuti Tingkatan
    // Pilihan Kelas hanya berisi kelas dari Tingkatan terpilih; Tingkatan diganti → Kelas kembali ke
    // pilihan pertama ("Semua kelas"). Listener capture supaya jalan sebelum onchange="this.form.submit()".
    var tingkatOf = function (kelas) {
        var m = /^\s*(XII|XI|X)(?![A-Z])/i.exec(kelas || '');
        return m ? m[1].toUpperCase() : '';
    };
    var saringKelas = function (tingkat, kelas, reset) {
        Array.prototype.forEach.call(kelas.options, function (opt, i) {
            var tampil = i === 0 || tingkat.value === '' || tingkatOf(opt.value) === tingkat.value;
            opt.hidden = !tampil;
            opt.disabled = !tampil;
        });
        if (reset || kelas.selectedOptions[0] && kelas.selectedOptions[0].hidden) {
            kelas.selectedIndex = 0;
        }
        // "Semua kelas/ruang (N peserta)": N = jumlah peserta tingkatan terpilih (dari data-jumlah kelas).
        if (kelas.options[0] && kelas.options[0].dataset.semua) {
            var n = 0;
            Array.prototype.forEach.call(kelas.options, function (opt) {
                if (!opt.hidden && opt.dataset.jumlah) { n += parseInt(opt.dataset.jumlah, 10) || 0; }
            });
            [kelas, document.getElementById('fRuang')].forEach(function (sel) {
                var o = sel && sel.form === kelas.form ? sel.options[0] : null;
                if (o && o.dataset.semua) { o.textContent = o.dataset.semua + ' (' + n + ' peserta)'; }
            });
        }
    };
    var tingkatSel = document.getElementById('fTingkat');
    var kelasSel = document.getElementById('fKelas');
    // Ruang yang opsinya ber-data-kelas (Data CBTAdmin): hanya ruang berisi kelas/tingkatan terpilih.
    var ruangSel = document.getElementById('fRuang');
    // awal: saat halaman dimuat, ruang yang sedang terpilih tetap ditampilkan (sudah dipakai server).
    var saringRuang = function (awal) {
        if (!ruangSel || ruangSel.disabled || !ruangSel.querySelector('option[data-kelas]')) { return; }
        var sejenis = function (el) { return el && el.form === ruangSel.form; };
        var kelas = sejenis(kelasSel) && !kelasSel.disabled && kelasSel.value !== '__semua' ? kelasSel.value : '';
        var tingkat = sejenis(tingkatSel) ? tingkatSel.value : '';
        Array.prototype.forEach.call(ruangSel.options, function (opt) {
            if (!opt.hasAttribute('data-kelas')) { return; }
            var isi = opt.getAttribute('data-kelas').split('|').filter(Boolean);
            var tampil = (awal === true && opt.selected) || (kelas !== '' ? isi.indexOf(kelas) !== -1
                : tingkat === '' || isi.some(function (k) { return tingkatOf(k) === tingkat; }));
            opt.hidden = !tampil;
            opt.disabled = !tampil;
        });
        if (ruangSel.selectedOptions[0] && ruangSel.selectedOptions[0].hidden) { ruangSel.selectedIndex = 0; }
    };
    if (tingkatSel && kelasSel && tingkatSel.form === kelasSel.form) {
        saringKelas(tingkatSel, kelasSel, false);
    }
    saringRuang(true);
    document.addEventListener('change', function (e) {
        if (!e.target || (e.target !== tingkatSel && e.target !== kelasSel)) { return; }
        if (e.target === tingkatSel && kelasSel && tingkatSel.form === kelasSel.form) { saringKelas(tingkatSel, kelasSel, true); }
        saringRuang();
    }, true);

    // ---------------------------------------------------------------- Lain-lain
    var updateButton = document.getElementById('btnCekUpdate');
    if (updateButton) {
        updateButton.addEventListener('click', function () {
            document.getElementById('updateInfo').classList.remove('d-none');
        });
    }

    // Status Peserta: hitung mundur sisa waktu, dan muat ulang halaman berkala
    // selama admin tidak sedang memilih, mengetik, atau membuka jendela.
    window.CbtLive = {
        start: function (serverNow, seconds) {
            var offset = serverNow - Math.floor(Date.now() / 1000);
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var tick = function () {
                var now = Math.floor(Date.now() / 1000) + offset;
                document.querySelectorAll('[data-deadline]').forEach(function (el) {
                    var left = parseInt(el.dataset.deadline, 10) - now;
                    if (left <= 0) {
                        el.textContent = 'habis';
                        el.className = 'text-danger fw-semibold';
                        return;
                    }
                    var h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
                    el.textContent = (h > 0 ? h + ':' + pad(m) : m) + ':' + pad(s);
                    el.className = left <= 300 ? 'text-danger fw-semibold' : '';
                });
                // Waktu Pengerjaan siswa yang masih mengerjakan: sejak mulai, terus berjalan.
                document.querySelectorAll('[data-mulai]').forEach(function (el) {
                    var lama = Math.max(0, now - parseInt(el.dataset.mulai, 10));
                    var h = Math.floor(lama / 3600), m = Math.floor(lama % 3600 / 60), s = lama % 60;
                    el.textContent = (h > 0 ? h + ':' + pad(m) : m) + ':' + pad(s);
                });
            };
            tick();
            setInterval(tick, 1000);

            var busy = function () {
                var table = tables[0];
                var checked = table ? table.$('input[name="ids[]"]:checked').length > 0
                    : document.querySelector('input[name="ids[]"]:checked');
                var active = document.activeElement;
                return checked || document.querySelector('.modal.show')
                    || (active && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName));
            };
            setInterval(function () { if (!busy()) { window.location.reload(); } }, seconds * 1000);
        }
    };

    // Dashboard: perbarui angka dan bar secara berkala.
    window.CbtDashboard = {
        start: function (url, seconds) {
            var format = new Intl.NumberFormat('id-ID');
            var apply = function (data) {
                // Pantauan siswa pengawas: isi ulang daftar nama tiap kelompok.
                if (data.pantau) {
                    Object.keys(data.pantau).forEach(function (key) {
                        var list = document.querySelector('[data-pantau="' + key + '"]');
                        if (!list) { return; }
                        list.replaceChildren.apply(list, data.pantau[key].map(function (s) {
                            var li = document.createElement('li');
                            var nama = document.createElement('span');
                            nama.textContent = s.nama + ' ';
                            var kelas = document.createElement('span');
                            kelas.className = 'text-muted';
                            kelas.textContent = '· ' + s.kelas;
                            nama.appendChild(kelas);
                            li.appendChild(nama);
                            if (s.info) { var info = document.createElement('small'); info.textContent = s.info; li.appendChild(info); }
                            return li;
                        }));
                        document.querySelector('[data-pantau-jumlah="' + key + '"]').textContent = data.pantau[key].length;
                        document.querySelector('[data-pantau-kosong="' + key + '"]').classList.toggle('d-none', data.pantau[key].length > 0);
                    });
                }
                ['ruang', 'users', 'aktif', 'belumLogin', 'belumMulai', 'belum', 'sudah'].forEach(function (key) {
                    var el = document.querySelector('[data-stat="' + key + '"]');
                    if (!el) { return; }
                    var v = data[key];
                    el.textContent = v === null || v === undefined ? '–' : (typeof v === 'number' ? format.format(v) : v);
                });
                document.getElementById('moodleError').classList.toggle('d-none', !data.moodleError);
                if (!data.sys) { return; } // pengawas: tanpa informasi server
                ['cpu', 'mem', 'disk'].forEach(function (key) {
                    var bar = document.querySelector('[data-sys-bar="' + key + '"]');
                    var percent = data.sys[key].percent;
                    bar.style.width = percent + '%';
                    bar.textContent = percent + '%';
                    bar.classList.toggle('warn', percent >= 70 && percent < 90);
                    bar.classList.toggle('crit', percent >= 90);
                    if (key !== 'cpu') {
                        document.querySelector('[data-sys-text="' + key + '"]').textContent = data.sys[key].text;
                    }
                });
            };
            var tick = function () {
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (response) {
                        if (response.status === 401) { window.location.reload(); return null; }
                        return response.ok ? response.json() : null;
                    })
                    .then(function (data) { if (data) { apply(data); } })
                    .catch(function () { /* jaringan putus sementara: coba lagi di putaran berikutnya */ });
            };
            setInterval(tick, seconds * 1000);
        }
    };
})();
