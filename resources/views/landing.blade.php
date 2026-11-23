{{--
    Landing page publik PT Satu Jaya Indonesia (route "/", nama route: landing).
    Gaya ada di resources/css/landing.css dan seluruhnya di-scope ke <body class="lp">,
    sehingga tidak memengaruhi dashboard maupun halaman role lain.
    Konten hanya memuat informasi yang tersedia di sistem: nama perusahaan, tiga jenis layanan
    (Cleaning Service, Satpam, Driver), dan alur kerja modul HRD–Operasional–Keuangan.
--}}
@php
    $pengguna = auth()->user();
    $urlMasuk = $pengguna?->homeUrl() ?? route('login');
    $labelMasuk = $pengguna ? 'Buka Dashboard' : 'Masuk';
    $menuLanding = [
        ['Beranda', '#beranda'],
        ['Layanan', '#layanan'],
        ['Tentang Kami', '#tentang'],
        ['Keunggulan', '#keunggulan'],
        ['Alur Kerja', '#alur'],
    ];
    $keunggulan = [
        ['data', 'Tenaga kerja terdata', 'Data karyawan dan sertifikat kompetensi dikelola oleh bagian HRD.'],
        ['penempatan', 'Penempatan terstruktur', 'Penempatan, rotasi, dan jadwal kerja diatur oleh tim Operasional.'],
        ['jam', 'Kehadiran terpantau', 'Absensi harian dicatat sesuai jadwal dan lokasi penempatan.'],
        ['dompet', 'Penggajian dan tagihan rapi', 'Slip gaji karyawan dan invoice klien disusun dari data yang tercatat di sistem.'],
    ];
    $alurKerja = [
        ['Pendataan karyawan', 'Data diri dan kompetensi tenaga kerja dicatat.', 'HRD'],
        ['Penempatan ke klien', 'Karyawan ditempatkan sesuai layanan dan lokasi kerja.', 'Operasional'],
        ['Jadwal dan absensi', 'Jadwal kerja diatur, kehadiran dicatat setiap hari.', 'Operasional · Karyawan'],
        ['Penggajian dan tagihan', 'Gaji karyawan dan tagihan klien disusun.', 'Keuangan'],
        ['Laporan', 'Rekap pekerjaan dan kinerja ditinjau manajemen.', 'Manajemen'],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT Satu Jaya Indonesia - Penyedia Jasa Tenaga Kerja</title>
    <meta name="description" content="PT Satu Jaya Indonesia, penyedia jasa tenaga kerja outsourcing untuk layanan Cleaning Service, Satpam, dan Driver.">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    @include('layouts.partials.font-tema')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>document.documentElement.classList.add('lp-js');</script>
</head>
<body class="lp">
    <a href="#beranda" class="lp-skip">Lewati ke konten utama</a>

    {{-- Navbar --}}
    <header class="lp-nav" data-lp-nav>
        <div class="lp-wrap lp-nav-inner">
            <a href="#beranda" class="lp-brand" aria-label="PT Satu Jaya Indonesia, ke bagian atas">
                <img src="{{ asset('images/logo-sji.png') }}" alt="" width="40" height="40">
                <span><strong>PT Satu Jaya</strong><small>Indonesia</small></span>
            </a>

            <nav class="lp-menu" id="lp-menu" aria-label="Navigasi landing page">
                @foreach ($menuLanding as [$labelMenu, $tautanMenu])
                    <a href="{{ $tautanMenu }}">{{ $labelMenu }}</a>
                @endforeach
                <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-gold lp-menu-cta">{{ $labelMasuk }}</a>
            </nav>

            <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-gold lp-nav-cta" data-lp-masuk>{{ $labelMasuk }}</a>

            <button type="button" class="lp-burger" aria-controls="lp-menu" aria-expanded="false" data-lp-burger>
                <span class="lp-sr">Buka menu</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>
    </header>

    <main>
        {{-- Hero --}}
        <section class="lp-hero" id="beranda">
            <div class="lp-wrap lp-hero-grid">
                <div class="lp-hero-text lp-reveal">
                    <p class="lp-eyebrow">Penyedia Jasa Tenaga Kerja</p>
                    <h1>Tenaga kerja andal, dikelola dengan <em>tertib</em>.</h1>
                    <p class="lp-lede">PT Satu Jaya Indonesia menempatkan tenaga Cleaning Service, Satpam, dan Driver di perusahaan klien, lalu mengelola jadwal, kehadiran, dan penggajiannya dalam satu sistem.</p>
                    <div class="lp-actions">
                        <a href="#layanan" class="lp-btn lp-btn-gold">Lihat Layanan</a>
                        <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-ghost">{{ $pengguna ? 'Buka Dashboard' : 'Masuk ke Sistem' }}</a>
                    </div>
                    <ul class="lp-hero-tags" aria-label="Jenis layanan">
                        <li>Cleaning Service</li>
                        <li>Satpam</li>
                        <li>Driver</li>
                    </ul>
                </div>

                {{-- Visual: potongan antarmuka penempatan (label saja, tanpa angka) --}}
                <div class="lp-hero-visual lp-reveal" aria-hidden="true">
                    <div class="lp-panel">
                        <div class="lp-panel-head">
                            <span class="lp-dot"></span><span class="lp-dot"></span><span class="lp-dot"></span>
                            <span class="lp-panel-title">Penempatan tenaga kerja</span>
                        </div>
                        <ul class="lp-panel-list">
                            <li>
                                <span class="lp-panel-ic">@include('partials.landing-ikon', ['ikon' => 'kebersihan'])</span>
                                <span><strong>Cleaning Service</strong><small>Kebersihan area kerja</small></span>
                                <span class="lp-chip is-ok">Terjadwal</span>
                            </li>
                            <li>
                                <span class="lp-panel-ic">@include('partials.landing-ikon', ['ikon' => 'keamanan'])</span>
                                <span><strong>Satpam</strong><small>Keamanan lokasi</small></span>
                                <span class="lp-chip is-ok">Terjadwal</span>
                            </li>
                            <li>
                                <span class="lp-panel-ic">@include('partials.landing-ikon', ['ikon' => 'kemudi'])</span>
                                <span><strong>Driver</strong><small>Mobilitas klien</small></span>
                                <span class="lp-chip is-gold">Bergerak</span>
                            </li>
                        </ul>
                        <div class="lp-week">
                            <span class="lp-week-label">Jadwal kerja</span>
                            <div class="lp-week-grid">
                                @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $urutanHari => $namaHari)
                                    <span class="lp-week-day">
                                        <i @class(['is-libur' => $urutanHari >= 5])></i>{{ $namaHari }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="lp-float lp-float-a"><span class="lp-float-ic">@include('partials.landing-ikon', ['ikon' => 'centang'])</span>Absensi tercatat</div>
                    <div class="lp-float lp-float-b"><span class="lp-float-ic">@include('partials.landing-ikon', ['ikon' => 'dompet'])</span>Slip gaji tersedia</div>
                </div>
            </div>
        </section>

        {{-- Layanan --}}
        <section class="lp-section" id="layanan" aria-labelledby="judul-layanan">
            <div class="lp-wrap">
                <div class="lp-head lp-reveal">
                    <p class="lp-kicker">Layanan</p>
                    <h2 id="judul-layanan">Tiga layanan tenaga kerja untuk operasional klien</h2>
                    <p>Setiap tenaga kerja ditempatkan sesuai jenis layanan dan lokasi kerja di perusahaan klien.</p>
                </div>
                <div class="lp-services">
                    <article class="lp-service lp-reveal">
                        <div class="lp-illus">@include('partials.landing-ilustrasi', ['ilustrasi' => 'kebersihan'])</div>
                        <h3>Cleaning Service</h3>
                        <p>Menjaga kebersihan dan kerapian area kerja klien, termasuk dukungan office boy untuk kebutuhan harian kantor.</p>
                    </article>
                    <article class="lp-service lp-reveal">
                        <div class="lp-illus">@include('partials.landing-ilustrasi', ['ilustrasi' => 'keamanan'])</div>
                        <h3>Satpam</h3>
                        <p>Menjaga keamanan lokasi klien sesuai jadwal kerja dan area penempatan yang telah ditentukan.</p>
                    </article>
                    <article class="lp-service lp-reveal">
                        <div class="lp-illus">@include('partials.landing-ilustrasi', ['ilustrasi' => 'kemudi'])</div>
                        <h3>Driver</h3>
                        <p>Mendukung mobilitas klien dengan pengemudi yang jadwal dan kehadirannya tetap tercatat meski bertugas berpindah lokasi.</p>
                    </article>
                </div>
            </div>
        </section>

        {{-- Tentang Kami --}}
        <section class="lp-section lp-about" id="tentang" aria-labelledby="judul-tentang">
            <div class="lp-wrap lp-about-grid">
                <div class="lp-reveal">
                    <p class="lp-kicker">Tentang Kami</p>
                    <h2 id="judul-tentang">PT Satu Jaya Indonesia</h2>
                    <p class="lp-about-lede">Perusahaan penyedia jasa tenaga kerja outsourcing. Kami menempatkan tenaga Cleaning Service, Satpam, dan Driver di perusahaan klien, lalu mengelola pekerjaan mereka melalui sistem informasi internal.</p>
                    <p>Pengelolaan dibagi ke tim yang jelas: HRD mengelola data dan kompetensi karyawan, Operasional mengatur penempatan dan jadwal kerja, dan Keuangan mengurus penggajian serta tagihan klien.</p>
                </div>
                <dl class="lp-facts lp-reveal">
                    <div><dt>3</dt><dd>Jenis layanan tenaga kerja</dd></div>
                    <div><dt>1</dt><dd>Sistem untuk penempatan, jadwal, absensi, dan penggajian</dd></div>
                    <div><dt>HRD · Operasional · Keuangan</dt><dd>Tim yang mengelola setiap tahap</dd></div>
                </dl>
            </div>
        </section>

        {{-- Keunggulan --}}
        <section class="lp-section" id="keunggulan" aria-labelledby="judul-keunggulan">
            <div class="lp-wrap">
                <div class="lp-head lp-reveal">
                    <p class="lp-kicker">Keunggulan</p>
                    <h2 id="judul-keunggulan">Dikelola dengan proses yang tercatat</h2>
                </div>
                <div class="lp-benefits">
                    @foreach ($keunggulan as $urutanKeunggulan => [$ikonKeunggulan, $judulKeunggulan, $isiKeunggulan])
                        <article class="lp-benefit lp-reveal" style="--d: {{ $urutanKeunggulan }}">
                            <span class="lp-benefit-ic">@include('partials.landing-ikon', ['ikon' => $ikonKeunggulan])</span>
                            <h3>{{ $judulKeunggulan }}</h3>
                            <p>{{ $isiKeunggulan }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Alur Kerja --}}
        <section class="lp-section lp-flow" id="alur" aria-labelledby="judul-alur">
            <div class="lp-wrap">
                <div class="lp-head lp-reveal">
                    <p class="lp-kicker">Alur Kerja</p>
                    <h2 id="judul-alur">Dari pendataan hingga laporan</h2>
                    <p>Setiap tahap ditangani tim yang berbeda dan tercatat di sistem yang sama.</p>
                </div>
                <ol class="lp-steps">
                    @foreach ($alurKerja as $urutanLangkah => [$judulLangkah, $isiLangkah, $timLangkah])
                        <li class="lp-step lp-reveal" style="--d: {{ $urutanLangkah }}">
                            <span class="lp-step-no">{{ str_pad((string) ($urutanLangkah + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3>{{ $judulLangkah }}</h3>
                            <p>{{ $isiLangkah }}</p>
                            <span class="lp-step-tim">{{ $timLangkah }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- CTA --}}
        <section class="lp-cta" aria-labelledby="judul-cta">
            <div class="lp-wrap lp-cta-inner lp-reveal">
                <div>
                    <h2 id="judul-cta">{{ $pengguna ? 'Lanjutkan pekerjaan Anda' : 'Bagian dari tim Satu Jaya?' }}</h2>
                    <p>Karyawan dan staf PT Satu Jaya Indonesia dapat masuk ke sistem untuk melihat jadwal, absensi, dan slip gaji, atau mengelola pekerjaan sesuai perannya.</p>
                </div>
                <div class="lp-actions">
                    <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-gold">{{ $pengguna ? 'Buka Dashboard' : 'Masuk ke Sistem' }}</a>
                    <a href="#layanan" class="lp-btn lp-btn-ghost">Lihat Layanan</a>
                </div>
            </div>
        </section>
    </main>

    {{-- Footer --}}
    <footer class="lp-footer">
        <div class="lp-wrap lp-footer-grid">
            <div class="lp-footer-brand">
                <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" width="48" height="48">
                <div>
                    <strong>PT Satu Jaya Indonesia</strong>
                    <p>Sistem Informasi Perusahaan Penyedia Jasa Tenaga Kerja</p>
                </div>
            </div>
            <nav aria-label="Tautan footer" class="lp-footer-nav">
                @foreach ($menuLanding as [$labelMenu, $tautanMenu])
                    <a href="{{ $tautanMenu }}">{{ $labelMenu }}</a>
                @endforeach
                <a href="{{ $urlMasuk }}">{{ $labelMasuk }}</a>
            </nav>
        </div>
        <div class="lp-wrap lp-footer-bottom">© {{ now()->year }} PT Satu Jaya Indonesia</div>
    </footer>

    <script>
        (() => {
            const nav = document.querySelector('[data-lp-nav]');
            const tombolMenu = document.querySelector('[data-lp-burger]');
            const menu = document.getElementById('lp-menu');

            const tutupMenu = () => {
                menu.classList.remove('is-open');
                tombolMenu.setAttribute('aria-expanded', 'false');
            };

            tombolMenu.addEventListener('click', () => {
                const terbuka = menu.classList.toggle('is-open');
                tombolMenu.setAttribute('aria-expanded', String(terbuka));
            });
            menu.querySelectorAll('a').forEach((tautan) => tautan.addEventListener('click', tutupMenu));
            document.addEventListener('keydown', (event) => event.key === 'Escape' && tutupMenu());

            const aturNav = () => nav.classList.toggle('is-scrolled', window.scrollY > 8);
            aturNav();
            window.addEventListener('scroll', aturNav, { passive: true });

            const elemenMuncul = document.querySelectorAll('.lp-reveal');
            if (! ('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                elemenMuncul.forEach((elemen) => elemen.classList.add('is-in'));
                return;
            }
            const pengamat = new IntersectionObserver((entri) => entri.forEach((item) => {
                if (item.isIntersecting) {
                    item.target.classList.add('is-in');
                    pengamat.unobserve(item.target);
                }
            }), { rootMargin: '0px 0px -8% 0px' });
            elemenMuncul.forEach((elemen) => pengamat.observe(elemen));
        })();
    </script>
</body>
</html>
