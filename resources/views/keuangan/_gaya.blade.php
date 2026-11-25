{{--
    Gaya khusus halaman Keuangan (Komponen Gaji, Payroll, Invoice Klien).
    Memakai token tema aplikasi (--op-*) dan hanya berlaku di dalam .keu-halaman,
    sehingga sidebar, header, dan halaman role lain tidak terpengaruh.
--}}
@once
    @push('styles')
        <style>
            .keu-halaman .keu-panel {
                border: 1px solid var(--op-line);
                border-radius: 14px;
                background: #fff;
            }

            .keu-halaman .keu-panel-kepala {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 12px 20px;
                padding: 16px 20px;
                border-bottom: 1px solid var(--op-line-2);
            }

            .keu-halaman .keu-judul-panel {
                font-size: 15px;
                line-height: 22px;
                font-weight: 700;
                color: var(--op-ink);
            }

            .keu-halaman .keu-sub {
                font-size: 12.5px;
                line-height: 18px;
                color: var(--op-text-3);
            }

            /* Catatan perhitungan yang bisa dibuka-tutup */
            .keu-halaman .keu-catatan {
                border: 1px solid var(--op-line);
                border-radius: 12px;
                background: var(--op-subtle);
            }

            .keu-halaman .keu-catatan > summary {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 12px 16px;
                cursor: pointer;
                list-style: none;
                font-size: 13px;
                font-weight: 700;
                color: var(--op-ink);
            }

            .keu-halaman .keu-catatan > summary::-webkit-details-marker {
                display: none;
            }

            .keu-halaman .keu-catatan > summary svg {
                transition: transform 0.2s;
            }

            .keu-halaman .keu-catatan[open] > summary svg {
                transform: rotate(90deg);
            }

            .keu-halaman .keu-catatan-isi {
                display: grid;
                gap: 14px 28px;
                padding: 4px 16px 16px 40px;
                font-size: 13px;
                line-height: 20px;
                color: var(--op-text-2);
            }

            @media (min-width: 1024px) {
                .keu-halaman .keu-catatan-isi {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            .keu-halaman .keu-catatan-isi h3 {
                margin-bottom: 2px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.06em;
                text-transform: uppercase;
                color: var(--op-gold-2);
            }

            /* Tabel keuangan */
            .keu-halaman .keu-tabel th.keu-grup {
                padding-top: 10px;
                padding-bottom: 6px;
                border-bottom: 0;
                background: #fff;
                font-size: 11px;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                text-align: center;
                color: var(--op-text-3);
            }

            .keu-halaman .keu-tabel th.keu-grup > span {
                display: block;
                padding-bottom: 6px;
                border-bottom: 2px solid var(--op-line-strong);
            }

            .keu-halaman .keu-tabel th.keu-grup.is-masuk > span {
                border-color: var(--op-green);
                color: var(--op-green);
            }

            .keu-halaman .keu-tabel th.keu-grup.is-potong > span {
                border-color: var(--op-red);
                color: var(--op-red);
            }

            .keu-halaman .keu-tabel td {
                vertical-align: top;
            }

            .keu-halaman .keu-tabel .keu-kolom-total {
                background: #f5f7fb;
            }

            .keu-halaman .keu-tabel thead .keu-kolom-total {
                background: var(--op-navy-soft);
                color: var(--op-ink);
            }

            .keu-halaman .keu-tabel .keu-kolom-isi {
                background: #fdf8ec;
            }

            .keu-halaman .keu-tabel thead .keu-kolom-isi {
                background: var(--op-gold-soft);
                color: #7e5710;
            }

            .keu-halaman .keu-angka {
                font-variant-numeric: tabular-nums;
                white-space: nowrap;
                text-align: right;
            }

            .keu-halaman .keu-minus {
                color: var(--op-red);
            }

            .keu-halaman .keu-ket {
                display: block;
                margin-top: 2px;
                font-size: 11.5px;
                line-height: 16px;
                color: var(--op-text-3);
            }

            /* Keterangan yang perlu perhatian (mis. gaji pokok belum diinput, BPJS belum diatur). */
            .keu-halaman .keu-ket.is-peringatan {
                color: #a3640b;
                font-weight: 600;
            }

            /* Keterangan panjang (mis. BPJS + nama klien) turun ke baris berikut, tidak melewati garis kolom. */
            .keu-halaman .keu-ket.is-bungkus {
                white-space: normal;
                overflow-wrap: anywhere;
            }

            /* Pencarian */
            .keu-halaman .keu-cari {
                width: 100%;
                max-width: 440px;
            }

            .keu-halaman .keu-cari-kotak {
                display: flex;
                align-items: center;
                gap: 4px;
                height: 42px;
                padding: 0 4px 0 12px;
                border: 1px solid var(--op-line-strong);
                border-radius: 999px;
                background: #fff;
                transition: border-color 0.2s, box-shadow 0.2s;
            }

            .keu-halaman .keu-cari-kotak:focus-within {
                border-color: var(--op-navy-2);
                box-shadow: 0 0 0 3px rgb(36 64 122 / 0.14);
            }

            .keu-halaman .keu-cari-ikon {
                width: 18px;
                height: 18px;
                flex-shrink: 0;
                color: var(--op-text-3);
            }

            .keu-halaman .keu-cari-kotak input {
                flex: 1;
                min-width: 0;
                height: 100%;
                padding: 0 6px;
                border: 0;
                background: transparent;
                font-size: 14px;
                color: var(--op-text);
                outline: none;
                box-shadow: none;
            }

            .keu-halaman .keu-cari-kotak input::-webkit-search-cancel-button {
                display: none;
            }

            .keu-halaman .keu-cari-hapus {
                display: grid;
                place-items: center;
                width: 28px;
                height: 28px;
                flex-shrink: 0;
                border-radius: 50%;
                color: var(--op-text-3);
            }

            .keu-halaman .keu-cari-hapus:hover {
                background: var(--op-paper);
                color: var(--op-ink);
            }

            .keu-halaman .keu-cari-hapus svg {
                width: 14px;
                height: 14px;
            }

            .keu-halaman .keu-cari-tombol {
                height: 34px;
                padding: 0 16px;
                flex-shrink: 0;
                border-radius: 999px;
                background: var(--op-ink);
                font-size: 13px;
                font-weight: 700;
                color: #fff;
                transition: background-color 0.2s;
            }

            .keu-halaman .keu-cari-tombol:hover {
                background: var(--op-navy);
            }

            .keu-halaman .keu-hasil-cari {
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px 12px;
                padding: 10px 20px;
                border-bottom: 1px solid var(--op-line-2);
                background: var(--op-gold-soft);
                font-size: 13px;
                color: #6b4a0e;
            }

            .keu-halaman .keu-hasil-cari a {
                font-weight: 700;
                color: var(--op-ink);
                text-decoration: underline;
                text-underline-offset: 3px;
            }

            .keu-halaman .keu-kosong {
                padding: 48px 24px;
                text-align: center;
            }

            .keu-halaman .keu-kosong p:first-child {
                font-size: 15px;
                font-weight: 700;
                color: var(--op-ink);
            }

            .keu-halaman .keu-kosong p + p {
                margin-top: 4px;
                font-size: 13px;
                color: var(--op-text-3);
            }

            /* Tombol aksi kecil berbingkai */
            .keu-halaman .keu-btn-garis {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                padding: 5px 12px;
                border: 1px solid var(--op-line-strong);
                border-radius: 999px;
                background: #fff;
                font-size: 12px;
                font-weight: 600;
                line-height: 18px;
                white-space: nowrap;
                color: var(--op-text);
                transition: border-color 0.2s, background-color 0.2s, color 0.2s;
            }

            .keu-halaman .keu-btn-garis:hover {
                border-color: var(--op-ink);
                color: var(--op-ink);
            }

            .keu-halaman .keu-btn-garis svg {
                width: 14px;
                height: 14px;
                flex-shrink: 0;
            }

            .keu-halaman .keu-btn-garis.is-wa {
                border-color: #9fd9b4;
                color: #1d7a43;
            }

            .keu-halaman .keu-btn-garis.is-wa:hover {
                border-color: #25d366;
                background: #effbf3;
            }

            .keu-halaman .keu-btn-garis.is-lunas {
                border-color: var(--op-green);
                background: var(--op-green);
                color: #fff;
            }

            .keu-halaman .keu-btn-garis.is-lunas:hover {
                background: #1f5237;
            }

            /* Bilah simpan Komponen Gaji yang menempel di bawah layar */
            .keu-halaman .keu-bilah-simpan {
                position: sticky;
                bottom: 0;
                z-index: 20;
                display: flex;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 14px 20px;
                border-top: 1px solid var(--op-line);
                border-radius: 0 0 14px 14px;
                background: rgb(255 255 255 / 0.96);
                backdrop-filter: blur(6px);
            }
        </style>
    @endpush

    @push('scripts')
        <script>
            // Bulan setelah bulan berjalan dinonaktifkan pada <select data-batas-periode="idInputTahun">;
            // server tetap menolak generate periode yang belum berjalan.
            (() => {
                const tahunIni = {{ now()->year }};
                const bulanIni = {{ now()->month }};
                document.querySelectorAll('select[data-batas-periode]').forEach((pilihBulan) => {
                    const inputTahun = document.getElementById(pilihBulan.dataset.batasPeriode);
                    if (!inputTahun) return;
                    inputTahun.max = String(tahunIni);
                    const perbarui = () => {
                        const tahun = Number(inputTahun.value);
                        [...pilihBulan.options].forEach((opsi) => {
                            const bulan = Number(opsi.value);
                            const nanti = tahun > tahunIni || (tahun === tahunIni && bulan > bulanIni);
                            opsi.disabled = nanti;
                            opsi.textContent = opsi.textContent.replace(' (belum berjalan)', '') + (nanti ? ' (belum berjalan)' : '');
                        });
                        if (pilihBulan.selectedOptions[0]?.disabled && tahun === tahunIni) pilihBulan.value = String(bulanIni);
                    };
                    inputTahun.addEventListener('input', perbarui);
                    perbarui();
                });
            })();
        </script>
    @endpush
@endonce
