{{--
    Notifikasi flash untuk semua modul, termasuk pesan SIGNAL SQLSTATE 45000 dari database.
    Kunci sesi: success/sukses, warning, error/gagal (modul Tenaga Kerja memakai sukses/gagal).
--}}
@php
    $pesanFlash = [
        ['kelas' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'pesan' => session('success') ?? session('sukses')],
        ['kelas' => 'border-amber-200 bg-amber-50 text-amber-800', 'pesan' => session('warning')],
        ['kelas' => 'border-red-200 bg-red-50 text-red-800', 'pesan' => session('error') ?? session('gagal') ?? ($error ?? null)],
    ];
    $daftarErrorValidasi = ! ($tanpaRingkasanValidasi ?? false) && isset($errors) ? $errors->all() : [];
@endphp

@foreach ($pesanFlash as $flash)
    @if (filled($flash['pesan']))
        <div class="mb-4 flex items-start justify-between gap-4 rounded-lg border px-4 py-3 text-sm {{ $flash['kelas'] }}" role="alert">
            <p>{{ $flash['pesan'] }}</p>
            <button type="button" class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100" data-flash-close aria-label="Tutup">&times;</button>
        </div>
    @endif
@endforeach

@if (count($daftarErrorValidasi) > 0)
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" role="alert">
        <div class="flex items-start justify-between gap-4">
            <p class="font-medium">Periksa kembali isian berikut:</p>
            <button type="button" class="shrink-0 text-lg leading-none opacity-60 hover:opacity-100" data-flash-close aria-label="Tutup">&times;</button>
        </div>
        <ul class="mt-1 list-inside list-disc">
            @foreach ($daftarErrorValidasi as $pesanError)
                <li>{{ $pesanError }}</li>
            @endforeach
        </ul>
    </div>
@endif
