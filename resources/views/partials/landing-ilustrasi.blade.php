{{--
    Ilustrasi garis layanan untuk landing page (gaya seragam: garis 1,6 navy, aksen emas, latar lingkaran krem).
    Pemakaian: @include('partials.landing-ilustrasi', ['ilustrasi' => 'kebersihan' | 'keamanan' | 'kemudi'])
--}}
<svg viewBox="0 0 160 120" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <circle cx="80" cy="60" r="50" class="lp-il-bg"/>
    <path d="M24 102h112" class="lp-il-line"/>
    @switch($ilustrasi)
        @case('kebersihan')
            {{-- Ember berisi air, alat pel, dan kilau bersih --}}
            <path d="M46 62h36l-5 38H51z" class="lp-il-line lp-il-fill"/>
            <path d="M48 70h32" class="lp-il-gold"/>
            <path d="M50 62c0-14 28-14 28 0" class="lp-il-line"/>
            <path d="M112 22 102 92" class="lp-il-line"/>
            <path d="M90 92h24l5 10H85z" class="lp-il-line lp-il-fill"/>
            <path d="M94 96v6M102 96v6M110 96v6" class="lp-il-line"/>
            <path d="M126 40v14M119 47h14" class="lp-il-gold"/>
            <path d="M34 36v10M29 41h10" class="lp-il-gold"/>
            @break
        @case('keamanan')
            {{-- Perisai dengan tanda centang dan topi petugas --}}
            <path d="M80 26 52 36v22c0 20 12 32 28 38 16-6 28-18 28-38V36z" class="lp-il-line lp-il-fill"/>
            <path d="m67 60 9 9 18-19" class="lp-il-gold lp-il-tebal"/>
            <path d="M58 22c7-6 37-6 44 0l-4 6H62z" class="lp-il-line"/>
            <path d="M30 56h10M120 56h10M36 38l7 5M124 38l-7 5" class="lp-il-gold"/>
            @break
        @case('kemudi')
            {{-- Mobil dengan lampu menyala dan garis jalan --}}
            <path d="M34 78l9-20c2-5 6-8 11-8h52c5 0 9 3 11 8l9 20v14H34z" class="lp-il-line lp-il-fill"/>
            <path d="M48 70l6-12c1-2 3-3 5-3h42c2 0 4 1 5 3l6 12z" class="lp-il-line"/>
            <circle cx="56" cy="92" r="9" class="lp-il-line lp-il-fill"/>
            <circle cx="104" cy="92" r="9" class="lp-il-line lp-il-fill"/>
            <path d="M38 82h8M114 82h8" class="lp-il-gold lp-il-tebal"/>
            <path d="M14 112h14M40 112h14M66 112h14M92 112h14M118 112h14" class="lp-il-gold"/>
            @break
    @endswitch
</svg>
