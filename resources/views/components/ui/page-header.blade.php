{{--
    Judul halaman + penjelasan singkat + (opsional) tombol aksi utama di slot.
    Pemakaian: <x-ui.page-header title="Data Klien" description="..."> <a class="btn-primary">..</a> </x-ui.page-header>
--}}
@props(['title', 'description' => null])

<div {{ $attributes->class('mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between') }}>
    <div class="min-w-0">
        <h2 class="text-xl font-semibold text-gray-800">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1 max-w-3xl text-sm text-gray-500">{{ $description }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex shrink-0 flex-wrap gap-2">{{ $slot }}</div>
    @endif
</div>
