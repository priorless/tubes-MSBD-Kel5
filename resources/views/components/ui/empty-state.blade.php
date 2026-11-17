{{-- Keadaan kosong yang memberi tahu langkah berikutnya; tombol/tautan opsional di slot. --}}
@props(['title', 'message' => null])

<div {{ $attributes->class('rounded-lg border border-dashed border-gray-300 px-6 py-8 text-center') }}>
    <p class="font-medium text-gray-700">{{ $title }}</p>
    @if ($message)
        <p class="mt-1 text-sm text-gray-500">{{ $message }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-4 flex flex-wrap justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
