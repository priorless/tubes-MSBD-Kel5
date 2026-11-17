{{-- Kartu ringkasan angka; bila href diisi, kartu menjadi tautan ke halaman terkait. --}}
@props(['label', 'value', 'hint' => null, 'href' => null, 'tone' => 'default'])

@php
    $kelasNilai = match ($tone) {
        'warning' => 'text-amber-600',
        'success' => 'text-emerald-600',
        default => 'text-gray-800',
    };
@endphp

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['stat-card block', 'transition-colors hover:border-sji-biru' => $href]) }}>
    <p class="stat-label">{{ $label }}</p>
    <p class="stat-value {{ $kelasNilai }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
    @endif
</{{ $href ? 'a' : 'div' }}>
