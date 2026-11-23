{{-- Ikon garis kecil untuk landing page. Pemakaian: @include('partials.landing-ikon', ['ikon' => 'keamanan']) --}}
@php
    $jalurIkon = [
        'kebersihan' => '<path d="M14 3 10 14"/><path d="M6 14h8l1.5 6h-11z"/><path d="M18 5v4M16 7h4"/>',
        'keamanan' => '<path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
        'kemudi' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 14.5V21M9.6 11.2 3.4 9.5M14.4 11.2l6.2-1.7"/>',
        'centang' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'dompet' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><path d="M16 14.5h2"/>',
        'data' => '<rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="2.5"/><path d="M8.5 16.5c.6-1.8 2-2.7 3.5-2.7s2.9.9 3.5 2.7"/>',
        'penempatan' => '<path d="M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'jam' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
@endphp
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $jalurIkon[$ikon] ?? '' !!}</svg>
