{{--
    Judul halaman + penjelasan singkat + (opsional) tombol aksi utama di slot.
    Pemakaian: <x-ui.page-header title="Data Klien" description="..."> <a class="btn-primary">..</a> </x-ui.page-header>
    Opsional: kicker="Data utama" — label kecil di atas judul (dipakai tema Operasional).
--}}
@props(['title', 'description' => null, 'kicker' => null])

<div {{ $attributes->class('page-header mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        @if ($kicker)
            <p class="page-header-kicker mb-2 text-xs font-semibold text-gray-500 uppercase">{{ $kicker }}</p>
        @endif
        <h2 class="page-header-title text-xl font-semibold text-gray-800">{{ $title }}</h2>
        @if ($description)
            <p class="page-header-desc mt-1 max-w-3xl text-sm text-gray-500">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 flex-wrap gap-2">{{ $slot }}</div>
    @endif
</div>
