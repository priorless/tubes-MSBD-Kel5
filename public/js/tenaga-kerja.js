/* ==========================================================================
   Portal Karyawan SJI — Modul Tenaga Kerja
   Interaksi & animasi (tanpa library tambahan). Tidak ada logika bisnis di sini:
   semua keputusan (boleh check-in/out, nilai gaji, dsb.) tetap dari server/database.
   ========================================================================== */
(function () {
    'use strict';

    const $ = (s, el = document) => el.querySelector(s);
    const $$ = (s, el = document) => Array.from(el.querySelectorAll(s));
    const kurangiGerak = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const dua = (n) => String(n).padStart(2, '0');

    /* ------------------------------------------------------------------
     * 1. Notifikasi (toast)
     * ------------------------------------------------------------------ */
    const TK = (window.TK = window.TK || {});

    TK.toast = function (tipe, pesan, durasi = 5000) {
        const wrap = $('#tkToastWrap');
        if (!wrap) return;
        const sukses = tipe === 'sukses';
        const el = document.createElement('div');
        el.className = 'tk-toast tk-toast-' + (sukses ? 'sukses' : 'gagal');
        el.setAttribute('role', sukses ? 'status' : 'alert');
        el.innerHTML =
            '<div class="tk-toast-ikon">' + (sukses ? '&#10003;' : '!') + '</div>' +
            '<div><div class="tk-toast-judul">' + (sukses ? 'Berhasil' : 'Gagal') + '</div>' +
            '<div class="tk-toast-pesan"></div></div>' +
            '<button type="button" class="tk-toast-tutup" aria-label="Tutup">&times;</button>' +
            '<div class="tk-toast-bar" style="animation-duration:' + durasi + 'ms"></div>';
        $('.tk-toast-pesan', el).textContent = pesan; // textContent: aman dari XSS
        wrap.appendChild(el);

        let timer;
        const tutup = () => {
            clearTimeout(timer);
            el.classList.add('keluar');
            el.addEventListener('animationend', () => el.remove(), { once: true });
        };
        timer = setTimeout(tutup, durasi);
        $('.tk-toast-tutup', el).addEventListener('click', tutup);
        el.addEventListener('mouseenter', () => { clearTimeout(timer); $('.tk-toast-bar', el).style.animationPlayState = 'paused'; });
        el.addEventListener('mouseleave', () => { timer = setTimeout(tutup, 2000); });
    };

    (window.TK_FLASH || []).forEach((f, i) => setTimeout(() => TK.toast(f.tipe, f.pesan), 250 + i * 150));

    /* ------------------------------------------------------------------
     * 2. Animasi muncul bertahap (reveal) + hitung naik
     * ------------------------------------------------------------------ */
    const hitungNaik = (el) => {
        const rupiah = el.hasAttribute('data-rupiah');
        const target = parseFloat(el.getAttribute(rupiah ? 'data-rupiah' : 'data-hitung')) || 0;
        const fmt = (n) => rupiah ? 'Rp ' + Math.round(n).toLocaleString('id-ID') : Math.round(n).toLocaleString('id-ID');
        if (kurangiGerak) { el.textContent = fmt(target); return; }
        const durasi = 1100, mulai = performance.now();
        const langkah = (t) => {
            const p = Math.min((t - mulai) / durasi, 1);
            el.textContent = fmt(target * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(langkah);
        };
        requestAnimationFrame(langkah);
    };

    const reveal = $$('.tk-reveal:not(.tampil)');
    if ('IntersectionObserver' in window) {
        let urutan = 0;
        const io = new IntersectionObserver((entri) => {
            entri.forEach((e) => {
                if (!e.isIntersecting) return;
                e.target.style.setProperty('--tunda', (urutan++ % 8) * 70 + 'ms');
                e.target.classList.add('tampil');
                $$('[data-hitung],[data-rupiah]', e.target).forEach(hitungNaik);
                io.unobserve(e.target);
            });
        }, { threshold: 0.08 });
        reveal.forEach((el) => io.observe(el));
    } else {
        reveal.forEach((el) => el.classList.add('tampil'));
        $$('[data-hitung],[data-rupiah]').forEach(hitungNaik);
    }

    /* ------------------------------------------------------------------
     * 3. Sidebar (mobile) & transisi antar halaman
     * ------------------------------------------------------------------ */
    const body = document.body;
    $('#tkMenuBtn')?.addEventListener('click', () => body.classList.add('menu-terbuka'));
    $('#tkBackdrop')?.addEventListener('click', () => body.classList.remove('menu-terbuka'));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') body.classList.remove('menu-terbuka'); });

    const progres = $('#tkProgress');
    const mulaiProgres = () => progres && requestAnimationFrame(() => progres.classList.add('jalan'));

    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        if (a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-no-transisi')) return;
        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin || (url.pathname === location.pathname && url.search === location.search)) return;
        e.preventDefault();
        mulaiProgres();
        if (kurangiGerak) { location.href = url.href; return; }
        body.classList.add('keluar');
        setTimeout(() => { location.href = url.href; }, 200);
    });
    $$('form[method="GET" i]').forEach((f) => f.addEventListener('submit', mulaiProgres));
    // Saat kembali dengan tombol Back (bfcache), pulihkan tampilan
    window.addEventListener('pageshow', () => { body.classList.remove('keluar', 'menu-terbuka'); progres?.classList.remove('jalan'); });

    /* ------------------------------------------------------------------
     * 4. Efek riak (ripple) pada tombol
     * ------------------------------------------------------------------ */
    document.addEventListener('pointerdown', (e) => {
        const btn = e.target.closest('.tk-btn');
        if (!btn || kurangiGerak) return;
        const r = btn.getBoundingClientRect();
        const s = Math.max(r.width, r.height);
        const riak = document.createElement('span');
        riak.className = 'tk-riak';
        riak.style.cssText = `width:${s}px;height:${s}px;left:${e.clientX - r.left - s / 2}px;top:${e.clientY - r.top - s / 2}px`;
        btn.appendChild(riak);
        riak.addEventListener('animationend', () => riak.remove());
    });

    /* ------------------------------------------------------------------
     * 5. Jam digital (disinkronkan dengan waktu server database)
     * ------------------------------------------------------------------ */
    const parseWaktu = (teks) => {
        // "YYYY-MM-DD HH:MM:SS" atau "YYYY-MM-DDTHH:MM:SS" → Date (waktu dinding, tanpa zona)
        const m = String(teks || '').match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):?(\d{2})?/);
        return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0)) : null;
    };

    const jam = $('#tkClock');
    const serverAwal = jam ? parseWaktu(jam.dataset.server) : null;
    const selisih = serverAwal ? serverAwal.getTime() - Date.now() : 0;
    const sekarang = () => new Date(Date.now() + selisih);

    if (jam) {
        const digit = $$('.tk-digit', jam);
        const sapaan = $('#tkSapaan');
        const tanggal = $('#tkTanggal');
        const fmtTanggal = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
        let tglTerakhir = '';

        const tick = () => {
            const n = sekarang();
            const teks = dua(n.getHours()) + dua(n.getMinutes()) + dua(n.getSeconds());
            digit.forEach((d, i) => {
                if (d.textContent !== teks[i]) {
                    d.textContent = teks[i];
                    if (!kurangiGerak) { d.classList.remove('flip'); void d.offsetWidth; d.classList.add('flip'); }
                }
            });
            const h = n.getHours();
            if (sapaan) sapaan.textContent = h < 11 ? 'Selamat pagi' : h < 15 ? 'Selamat siang' : h < 18 ? 'Selamat sore' : 'Selamat malam';
            const tgl = fmtTanggal.format(n);
            if (tanggal && tgl !== tglTerakhir) { tanggal.textContent = tgl; tglTerakhir = tgl; }
        };
        tick();
        setInterval(tick, 1000);
    }

    /* ------------------------------------------------------------------
     * 6. Status absensi: durasi kerja langsung + check-in/out tanpa reload
     * ------------------------------------------------------------------ */
    const kartu = $('#tkStatusCard');
    const LABEL = { belum: 'Belum Check-in', masuk: 'Sedang Bekerja', selesai: 'Selesai' };
    let timerDurasi = null;

    const jalankanDurasi = () => {
        clearInterval(timerDurasi);
        const el = $('#tkDurasi');
        const masuk = parseWaktu(kartu?.dataset.masuk);
        if (!el || !masuk || kartu.dataset.status !== 'masuk') return;
        const perbarui = () => {
            const d = Math.max(0, Math.floor((sekarang() - masuk) / 1000));
            el.textContent = dua(Math.floor(d / 3600)) + ':' + dua(Math.floor((d % 3600) / 60)) + ':' + dua(d % 60);
        };
        perbarui();
        timerDurasi = setInterval(perbarui, 1000);
    };

    const gantiStatus = (data) => {
        if (!kartu || !data.status) return;
        if (data.jam_masuk !== undefined) $('#tkJamMasuk').textContent = data.jam_masuk || '—';
        if (data.jam_keluar !== undefined) $('#tkJamKeluar').textContent = data.jam_keluar || '—';
        if (data.masuk_iso) kartu.dataset.masuk = data.masuk_iso;
        if (data.lokasi) tampilkanSemuaLokasi(data.lokasi);
        if (kartu.dataset.status !== data.status) {
            kartu.dataset.status = data.status;
            $('#tkStatusLabel').textContent = LABEL[data.status] || data.status;
            const badge = $('#tkStatusBadge');
            badge.classList.remove('ganti'); void badge.offsetWidth; badge.classList.add('ganti');
        }
        jalankanDurasi();
    };

    /* ------------------------------------------------------------------
     * 6a. Lokasi (GPS) saat check-in/check-out — wajib, hanya ditampilkan
     * ------------------------------------------------------------------ */
    const PESAN_LOKASI = {
        1: 'Izin lokasi ditolak. Aktifkan izin lokasi untuk situs ini di pengaturan browser, lalu coba lagi.',
        2: 'Lokasi tidak dapat ditentukan. Pastikan GPS/lokasi perangkat aktif, lalu coba lagi.',
        3: 'Waktu mengambil lokasi habis. Pastikan sinyal GPS baik, lalu coba lagi.',
    };

    const ambilLokasi = () => new Promise((resolve, reject) => {
        if (!window.isSecureContext) {
            reject(new Error('Akses lokasi hanya bisa dipakai lewat HTTPS atau localhost. Hubungi admin sistem.'));
            return;
        }
        if (!('geolocation' in navigator)) {
            reject(new Error('Browser ini tidak mendukung akses lokasi. Gunakan browser lain (Chrome/Firefox/Safari terbaru).'));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (posisi) => resolve(posisi.coords),
            (galat) => reject(new Error(PESAN_LOKASI[galat.code] || 'Lokasi tidak dapat diambil. Silakan coba lagi.')),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });

    const tampilkanLokasi = (el, lokasi) => {
        el.textContent = '';
        const lat = Number(lokasi?.latitude);
        const lng = Number(lokasi?.longitude);
        if (!lokasi || !Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const koordinat = document.createElement('span');
        koordinat.className = 'block font-mono';
        koordinat.textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
        el.appendChild(koordinat);

        if (Number.isFinite(Number(lokasi.akurasi)) && lokasi.akurasi !== null) {
            const akurasi = document.createElement('span');
            akurasi.className = 'block';
            akurasi.textContent = 'Akurasi ±' + Math.round(lokasi.akurasi) + ' m';
            el.appendChild(akurasi);
        }

        const peta = document.createElement('a');
        peta.href = 'https://www.google.com/maps?q=' + lat + ',' + lng;
        peta.target = '_blank';
        peta.rel = 'noopener';
        peta.className = 'font-medium text-sji-biru hover:underline';
        peta.textContent = 'Lihat di peta';
        el.appendChild(peta);
    };

    const tampilkanSemuaLokasi = (lokasi) => {
        $$('[data-lokasi]').forEach((el) => tampilkanLokasi(el, lokasi[el.dataset.lokasi]));
    };

    $$('[data-lokasi-awal]').forEach((el) => {
        try { tampilkanLokasi(el, JSON.parse(el.dataset.lokasiAwal)); } catch (_) { /* tidak ada lokasi */ }
    });

    const konfeti = (asal) => {
        if (kurangiGerak) return;
        const r = asal.getBoundingClientRect();
        const warna = ['#2f6b66', '#1f4a63', '#7fd1c7', '#d3d3d3', '#2e8b6a', '#e2b65c'];
        for (let i = 0; i < 28; i++) {
            const k = document.createElement('span');
            k.className = 'tk-konfeti';
            k.style.left = r.left + r.width / 2 + 'px';
            k.style.top = r.top + r.height / 2 + 'px';
            k.style.background = warna[i % warna.length];
            k.style.setProperty('--x', (Math.random() * 260 - 130) + 'px');
            k.style.setProperty('--y', (Math.random() * -180 - 40) + 'px');
            k.style.setProperty('--r', (Math.random() * 720 - 360) + 'deg');
            document.body.appendChild(k);
            k.addEventListener('animationend', () => k.remove());
        }
    };

    if (kartu) {
        jalankanDurasi();

        $$('.tk-form-absen', kartu).forEach((form) => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const tombol = $('button[type="submit"]', form);
                if (tombol.classList.contains('memuat')) return;
                if (form.dataset.konfirmasi && !window.confirm(form.dataset.konfirmasi)) return;

                tombol.classList.add('memuat');
                tombol.disabled = true;

                // Lokasi wajib: ambil dulu sebelum mengirim absensi.
                let koordinat;
                try {
                    koordinat = await ambilLokasi();
                } catch (galat) {
                    TK.toast('gagal', galat.message);
                    tombol.classList.remove('memuat');
                    tombol.disabled = false;
                    return;
                }

                const minimal = new Promise((r) => setTimeout(r, 600)); // agar loading terlihat halus
                const isian = new FormData(form);
                isian.append('latitude', koordinat.latitude);
                isian.append('longitude', koordinat.longitude);
                if (Number.isFinite(koordinat.accuracy)) isian.append('akurasi', koordinat.accuracy);

                try {
                    const res = await fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
                        },
                        body: isian,
                        credentials: 'same-origin',
                    });
                    await minimal;

                    let data = {};
                    try { data = await res.json(); } catch (_) { /* bukan JSON */ }

                    if (res.status === 419) {
                        TK.toast('gagal', 'Sesi halaman sudah kedaluwarsa. Halaman akan dimuat ulang.');
                        setTimeout(() => location.reload(), 1800);
                        return;
                    }
                    if (res.status === 429) {
                        TK.toast('gagal', 'Terlalu banyak percobaan. Tunggu sebentar lalu coba lagi.');
                        return;
                    }

                    TK.toast(data.ok ? 'sukses' : 'gagal', data.pesan || (data.ok ? 'Berhasil.' : 'Terjadi kesalahan. Silakan coba lagi.'));
                    if (data.ok) konfeti(tombol);
                    gantiStatus(data);
                } catch (_) {
                    await minimal;
                    TK.toast('gagal', 'Tidak dapat terhubung ke server. Periksa koneksi Anda lalu coba lagi.');
                } finally {
                    tombol.classList.remove('memuat');
                    tombol.disabled = false;
                }
            });
        });
    }

    /* ------------------------------------------------------------------
     * 7. Riwayat: pencarian di tabel + filter tanggal cepat
     * ------------------------------------------------------------------ */
    const cari = $('#tkCari');
    const tabel = $('#tkTabel');
    if (cari && tabel) {
        const baris = $$('tbody tr', tabel);
        baris.forEach((tr) => $$('td', tr).forEach((td) => { td.dataset.asli = td.innerHTML; }));
        const escRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const escHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        let jeda;
        cari.addEventListener('input', () => {
            clearTimeout(jeda);
            jeda = setTimeout(() => {
                const q = cari.value.trim().toLowerCase();
                let terlihat = 0;
                baris.forEach((tr) => {
                    const cocok = !q || tr.textContent.toLowerCase().includes(q);
                    tr.classList.toggle('tersembunyi', !cocok);
                    if (cocok) terlihat++;
                    $$('td', tr).forEach((td) => {
                        td.innerHTML = td.dataset.asli;
                        if (!q || !cocok || td.querySelector('*')) return; // hanya sel teks biasa
                        const re = new RegExp('(' + escRegex(escHtml(q)) + ')', 'gi');
                        td.innerHTML = td.dataset.asli.replace(re, '<mark>$1</mark>');
                    });
                });
                $('#tkKosongCari')?.classList.toggle('hidden', terlihat > 0);
            }, 150);
        });
    }

    $$('[data-preset]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const n = new Date();
            const iso = (d) => d.getFullYear() + '-' + dua(d.getMonth() + 1) + '-' + dua(d.getDate());
            let dari, sampai = n;
            const p = btn.dataset.preset;
            if (p === 'bulan-ini') dari = new Date(n.getFullYear(), n.getMonth(), 1);
            else if (p === 'bulan-lalu') { dari = new Date(n.getFullYear(), n.getMonth() - 1, 1); sampai = new Date(n.getFullYear(), n.getMonth(), 0); }
            else dari = new Date(n.getFullYear(), n.getMonth(), n.getDate() - (parseInt(p, 10) - 1));
            $('#dari').value = iso(dari);
            $('#sampai').value = iso(sampai);
            mulaiProgres();
            $('#tkFormFilter').submit();
        });
    });

    /* ------------------------------------------------------------------
     * 8. Slip gaji: pilih periode di layar kecil
     * ------------------------------------------------------------------ */
    $('#tkPilihPeriode')?.addEventListener('change', (e) => {
        mulaiProgres();
        location.href = e.target.value;
    });
})();
