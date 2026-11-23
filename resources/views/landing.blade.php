{{--
    Landing page publik PT Satu Jaya Indonesia (route "/", nama route: landing).
    Gaya ada di resources/css/landing.css dan seluruhnya di-scope ke <body class="lp">,
    sehingga tidak memengaruhi dashboard maupun halaman role lain.
    Konten hanya memuat informasi yang tersedia di sistem: nama perusahaan, tiga jenis layanan
    (Cleaning Service, Satpam/Security, Driver), dan alur kerja modul HRD–Operasional–Keuangan.
    Foto dokumentasi asli ada di public/images/{cleaning-service,security,driver,company-profile}.
--}}
@php
    $pengguna = auth()->user();
    $urlMasuk = $pengguna?->homeUrl() ?? route('login');
    $labelMasuk = $pengguna ? 'Buka Dashboard' : 'Masuk';
    $menuLanding = [
        ['Beranda', '#beranda'],
        ['Tentang Kami', '#tentang'],
        ['Layanan', '#layanan'],
        ['Keunggulan', '#keunggulan'],
        ['Alur Kerja', '#alur'],
        // Anchor #kontak dipertahankan agar tautan lama tetap berfungsi; elemen ketiga = label versi footer.
        ['Aktivitas', '#kontak', 'Aktivitas Perusahaan'],
    ];
    $layanan = [
        [
            'judul' => 'Cleaning Service',
            'label' => 'Kebersihan',
            'foto' => 'images/cleaning-service/cleaning-hero.jpg',
            'alt' => 'Petugas cleaning service PT Satu Jaya Indonesia membersihkan area kantor klien',
            'isi' => 'Menjaga kebersihan dan kerapian area kerja klien, termasuk dukungan office boy untuk kebutuhan harian kantor.',
        ],
        [
            'judul' => 'Security',
            'label' => 'Satpam',
            'foto' => 'images/security/security-hero.jpg',
            'alt' => 'Penyematan atribut kepada anggota satpam PT Satu Jaya Indonesia',
            'isi' => 'Menjaga keamanan lokasi klien sesuai jadwal kerja dan area penempatan yang telah ditentukan.',
        ],
        [
            'judul' => 'Driver',
            'label' => 'Pengemudi',
            'foto' => 'images/driver/driver-hero.jpg',
            'alt' => 'Pembekalan tenaga driver PT Satu Jaya Indonesia',
            'isi' => 'Mendukung mobilitas klien dengan pengemudi yang jadwal dan kehadirannya tetap tercatat meski bertugas berpindah lokasi.',
        ],
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
    $mediaSosial = [
        [
            'nama' => 'Instagram',
            'url' => 'https://www.instagram.com/satujayaindonesia?knvt=MXVtaGJ0ems1cXA0dQ==',
        ],
        [
            'nama' => 'YouTube',
            'url' => 'https://www.youtube.com/@pt.satujayaindonesia3187/videos',
        ],
    ];
    $deskripsiHalaman = 'PT Satu Jaya Indonesia, penyedia jasa tenaga kerja outsourcing untuk layanan Cleaning Service, Satpam, dan Driver.';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PT Satu Jaya Indonesia - Penyedia Jasa Tenaga Kerja</title>
    <meta name="description" content="{{ $deskripsiHalaman }}">
    <meta name="theme-color" content="#0b1a33">
    <meta property="og:type" content="website">
    <meta property="og:title" content="PT Satu Jaya Indonesia - Penyedia Jasa Tenaga Kerja">
    <meta property="og:description" content="{{ $deskripsiHalaman }}">
    <meta property="og:image" content="{{ asset('images/company-profile/company-team.jpg') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/logo-sji.png') }}">
    <link rel="preload" as="image" href="{{ asset('images/company-profile/company-team.jpg') }}" fetchpriority="high">
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
                <span><strong>PT SATU JAYA</strong><small>INDONESIA</small></span>
            </a>

            <nav class="lp-menu" id="lp-menu" aria-label="Navigasi landing page">
                @foreach ($menuLanding as [$labelMenu, $tautanMenu])
                    <a href="{{ $tautanMenu }}" class="lp-menu-link">{{ $labelMenu }}</a>
                @endforeach
                <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-primary lp-menu-cta">{{ $labelMasuk }}</a>
            </nav>

            <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-primary lp-nav-cta" data-lp-masuk>{{ $labelMasuk }}</a>

            <button type="button" class="lp-burger" aria-controls="lp-menu" aria-expanded="false" data-lp-burger>
                <span class="lp-sr">Buka menu</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path class="lp-burger-a" d="M4 7h16"/><path class="lp-burger-b" d="M4 12h16"/><path class="lp-burger-c" d="M4 17h16"/></svg>
            </button>
        </div>
    </header>

    <main>
        {{-- A. Hero --}}
        <section class="lp-hero" id="beranda" aria-labelledby="judul-hero" style="--lp-hero-foto: url('{{ asset('images/company-profile/company-team.jpg') }}')">
            <div class="lp-wrap lp-hero-grid">
                <div class="lp-hero-text">
                    <p class="lp-eyebrow lp-hero-kicker lp-enter" style="--i: 0">
                        <span class="lp-slogan" lang="en">NEAR FROM YOU</span>
                        <span class="lp-hero-kicker-sep" aria-hidden="true"></span>
                        <span>Penyedia Jasa Tenaga Kerja</span>
                    </p>
                    <h1 id="judul-hero" class="lp-enter" style="--i: 1">Tenaga Kerja Andal, Dikelola dengan <em>Tertib.</em></h1>
                    <p class="lp-lede lp-enter" style="--i: 2">PT Satu Jaya Indonesia menempatkan tenaga Cleaning Service, Satpam, dan Driver di perusahaan klien, lalu mengelola jadwal, kehadiran, dan penggajiannya dalam satu sistem.</p>
                    <div class="lp-actions lp-enter" style="--i: 3">
                        <a href="#layanan" class="lp-btn lp-btn-primary">
                            Lihat Layanan
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </a>
                        <a href="#kontak" class="lp-btn lp-btn-ghost">Lihat Aktivitas</a>
                    </div>
                    <ul class="lp-hero-tags lp-enter" style="--i: 4" aria-label="Jenis layanan">
                        <li>Cleaning Service</li>
                        <li>Security</li>
                        <li>Driver</li>
                    </ul>
                </div>

                {{-- Visual: potongan antarmuka penempatan (label saja, tanpa angka) --}}
                <div class="lp-hero-visual lp-enter" style="--i: 2" aria-hidden="true">
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
                                <span class="lp-chip is-accent">Pengganti</span>
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

        {{-- B. Tentang Kami --}}
        <section class="lp-section lp-about" id="tentang" aria-labelledby="judul-tentang">
            <div class="lp-wrap">
                <div class="lp-about-grid">
                    <figure class="lp-about-photo" data-lp-moment>
                        <img src="{{ asset('images/company-profile/company-team.jpg') }}" alt="Foto bersama karyawan PT Satu Jaya Indonesia" width="1280" height="1039" loading="lazy">
                    </figure>
                    <div class="lp-about-text" data-lp-reveal>
                        <p class="lp-kicker">Tentang Kami</p>
                        <h2 id="judul-tentang">PT Satu Jaya Indonesia</h2>
                        <p class="lp-about-lede">Perusahaan penyedia jasa tenaga kerja outsourcing. Kami menempatkan tenaga Cleaning Service, Satpam, dan Driver di perusahaan klien, lalu mengelola pekerjaan mereka melalui sistem informasi internal.</p>
                        <p>Pengelolaan dibagi ke tim yang jelas: HRD mengelola data dan kompetensi karyawan, Operasional mengatur penempatan dan jadwal kerja, dan Keuangan mengurus penggajian serta tagihan klien.</p>
                    </div>
                </div>
                <dl class="lp-facts" data-lp-reveal>
                    <div><dt>3</dt><dd>Jenis layanan tenaga kerja</dd></div>
                    <div><dt>1</dt><dd>Sistem untuk penempatan, jadwal, absensi, dan penggajian</dd></div>
                    <div><dt class="is-text">HRD · Operasional · Keuangan</dt><dd>Tim yang mengelola setiap tahap</dd></div>
                </dl>
            </div>
        </section>

        {{-- C. Layanan Kami --}}
        <section class="lp-section lp-services-section" id="layanan" aria-labelledby="judul-layanan">
            <div class="lp-wrap">
                <div class="lp-head lp-head-split" data-lp-reveal>
                    <div>
                        <p class="lp-kicker">Layanan Kami</p>
                        <h2 id="judul-layanan">Tiga layanan tenaga kerja untuk operasional klien</h2>
                    </div>
                    <p>Setiap tenaga kerja ditempatkan sesuai jenis layanan dan lokasi kerja di perusahaan klien.</p>
                </div>
                <div class="lp-services" data-lp-reveal style="--r: 1">
                    @foreach ($layanan as $itemLayanan)
                        <article class="lp-service">
                            <div class="lp-service-photo">
                                <img src="{{ asset($itemLayanan['foto']) }}" alt="{{ $itemLayanan['alt'] }}" loading="lazy">
                            </div>
                            <div class="lp-service-body">
                                <p class="lp-service-label">{{ $itemLayanan['label'] }}</p>
                                <h3>{{ $itemLayanan['judul'] }}</h3>
                                <p>{{ $itemLayanan['isi'] }}</p>
                            </div>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- D. Keunggulan Kami --}}
        <section class="lp-section lp-benefits-section" id="keunggulan" aria-labelledby="judul-keunggulan">
            <div class="lp-wrap lp-benefits-grid">
                <div class="lp-head" data-lp-reveal>
                    <p class="lp-kicker">Keunggulan Kami</p>
                    <h2 id="judul-keunggulan">Dikelola dengan proses yang tercatat</h2>
                </div>
                <div class="lp-benefits" data-lp-reveal style="--r: 1">
                    @foreach ($keunggulan as [$ikonKeunggulan, $judulKeunggulan, $isiKeunggulan])
                        <article class="lp-benefit">
                            <span class="lp-benefit-ic">@include('partials.landing-ikon', ['ikon' => $ikonKeunggulan])</span>
                            <h3>{{ $judulKeunggulan }}</h3>
                            <p>{{ $isiKeunggulan }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- E. Alur Kerja --}}
        <section class="lp-section lp-flow" id="alur" aria-labelledby="judul-alur">
            <div class="lp-wrap">
                <div class="lp-head lp-head-center" data-lp-reveal>
                    <p class="lp-kicker">Alur Kerja</p>
                    <h2 id="judul-alur">Dari pendataan hingga laporan</h2>
                    <p>Setiap tahap ditangani tim yang berbeda dan tercatat di sistem yang sama.</p>
                </div>
                <ol class="lp-steps" data-lp-moment>
                    @foreach ($alurKerja as $urutanLangkah => [$judulLangkah, $isiLangkah, $timLangkah])
                        <li class="lp-step" style="--d: {{ $urutanLangkah }}">
                            <span class="lp-step-no">{{ str_pad((string) ($urutanLangkah + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <div class="lp-step-body">
                                <h3>{{ $judulLangkah }}</h3>
                                <p>{{ $isiLangkah }}</p>
                                <span class="lp-step-tim">{{ $timLangkah }}</span>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </section>

        {{-- F. Bagian dari Satu Jaya --}}
        <section class="lp-cta" aria-labelledby="judul-cta">
            <img class="lp-cta-bg" src="{{ asset('images/security/security-team.jpg') }}" alt="" width="960" height="1280" loading="lazy">
            <div class="lp-wrap lp-cta-inner">
                <div class="lp-cta-text" data-lp-reveal>
                    <p class="lp-kicker">Bagian dari Satu Jaya</p>
                    <h2 id="judul-cta">{{ $pengguna ? 'Lanjutkan pekerjaan Anda' : 'Bagian dari tim Satu Jaya?' }}</h2>
                    <p>Karyawan dan staf PT Satu Jaya Indonesia dapat masuk ke sistem untuk melihat jadwal, absensi, dan slip gaji, atau mengelola pekerjaan sesuai perannya.</p>
                    <div class="lp-actions">
                        <a href="{{ $urlMasuk }}" class="lp-btn lp-btn-primary">{{ $pengguna ? 'Buka Dashboard' : 'Masuk ke Sistem' }}</a>
                        <a href="#layanan" class="lp-btn lp-btn-ghost">Lihat Layanan</a>
                    </div>
                </div>
            </div>
        </section>
    </main>

    {{-- G. Aktivitas Perusahaan (anchor #kontak) dan Footer --}}
    <footer class="lp-footer" id="kontak" aria-labelledby="judul-kontak">
        <div class="lp-wrap">
            <div class="lp-footer-top">
                <div class="lp-footer-intro" data-lp-reveal>
                    <p class="lp-kicker">Aktivitas Perusahaan</p>
                    <h2 id="judul-kontak">Mengenal Lebih Dekat Kegiatan Satu Jaya</h2>
                    <p>Dokumentasi kegiatan, pelatihan, dan penempatan tenaga kerja PT Satu Jaya Indonesia sebagai gambaran aktivitas dan komitmen perusahaan.</p>
                </div>
                <ul class="lp-social" aria-label="Media sosial resmi" data-lp-reveal style="--r: 1">
                    @foreach ($mediaSosial as $akunSosial)
                        <li>
                            <a href="{{ $akunSosial['url'] }}" @class(['lp-social-ic', 'is-instagram' => $akunSosial['nama'] === 'Instagram', 'is-youtube' => $akunSosial['nama'] === 'YouTube']) target="_blank" rel="noopener noreferrer" aria-label="{{ $akunSosial['nama'] }} PT Satu Jaya Indonesia (dibuka di tab baru)" title="{{ $akunSosial['nama'] }}">
                                @if ($akunSosial['nama'] === 'Instagram')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4.2"/><circle cx="17.4" cy="6.6" r="1.1" fill="currentColor" stroke="none"/></svg>
                                @else
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="lp-footer-grid">
                <div class="lp-footer-brand">
                    <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" width="48" height="48">
                    <div>
                        <strong>PT Satu Jaya Indonesia</strong>
                        <p>Sistem Informasi Perusahaan Penyedia Jasa Tenaga Kerja</p>
                        <p class="lp-footer-slogan" lang="en">NEAR FROM YOU</p>
                    </div>
                </div>
                <nav aria-label="Tautan footer" class="lp-footer-col">
                    <h3>Navigasi</h3>
                    @foreach ($menuLanding as $itemMenu)
                        <a href="{{ $itemMenu[1] }}">{{ $itemMenu[2] ?? $itemMenu[0] }}</a>
                    @endforeach
                    <a href="{{ $urlMasuk }}">{{ $labelMasuk }}</a>
                </nav>
                <div class="lp-footer-col">
                    <h3>Layanan</h3>
                    @foreach ($layanan as $itemLayanan)
                        <a href="#layanan">{{ $itemLayanan['judul'] }}</a>
                    @endforeach
                </div>
                <div class="lp-footer-col">
                    <h3>Media Sosial</h3>
                    @foreach ($mediaSosial as $akunSosial)
                        <a href="{{ $akunSosial['url'] }}" target="_blank" rel="noopener noreferrer">{{ $akunSosial['nama'] }}<span class="lp-sr"> (dibuka di tab baru)</span></a>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="lp-wrap"><p class="lp-footer-bottom">© {{ now()->year }} PT Satu Jaya Indonesia</p></div>
    </footer>

    <script>
        (() => {
            const nav = document.querySelector('[data-lp-nav]');
            const tombolMenu = document.querySelector('[data-lp-burger]');
            const menu = document.getElementById('lp-menu');

            const tutupMenu = () => {
                menu.classList.remove('is-open');
                nav.classList.remove('is-menu-open');
                tombolMenu.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('lp-lock');
            };

            tombolMenu.addEventListener('click', () => {
                const terbuka = menu.classList.toggle('is-open');
                nav.classList.toggle('is-menu-open', terbuka);
                tombolMenu.setAttribute('aria-expanded', String(terbuka));
                document.body.classList.toggle('lp-lock', terbuka);
            });
            menu.querySelectorAll('a').forEach((tautan) => tautan.addEventListener('click', tutupMenu));
            document.addEventListener('keydown', (event) => event.key === 'Escape' && tutupMenu());
            window.matchMedia('(min-width: 1024px)').addEventListener('change', tutupMenu);

            const aturNav = () => nav.classList.toggle('is-scrolled', window.scrollY > 8);
            aturNav();
            window.addEventListener('scroll', aturNav, { passive: true });

            const elemenMomen = document.querySelectorAll('[data-lp-moment], [data-lp-reveal]');
            if (! ('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                elemenMomen.forEach((elemen) => elemen.classList.add('is-in'));
                return;
            }
            const pengamat = new IntersectionObserver((entri) => entri.forEach((item) => {
                if (item.isIntersecting) {
                    item.target.classList.add('is-in');
                    pengamat.unobserve(item.target);
                }
            }), { rootMargin: '0px 0px -15% 0px' });
            elemenMomen.forEach((elemen) => pengamat.observe(elemen));
        })();
    </script>
</body>
</html>
