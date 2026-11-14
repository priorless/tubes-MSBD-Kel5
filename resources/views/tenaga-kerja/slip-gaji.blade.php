@extends('layouts.tenaga-kerja')

@section('title', 'Slip Gaji')

@section('content')
@if ($periode->isEmpty())
    <section class="tk-reveal card px-6 py-12 text-center">
        <p class="font-semibold text-gray-800">Belum ada slip gaji</p>
        <p class="mt-1 text-sm text-gray-500">Slip gaji akan tersedia setelah bagian keuangan memproses penggajian periode Anda.</p>
    </section>
@else
<div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
    {{-- ===================== Daftar periode ===================== --}}
    <section class="tk-reveal tk-no-print card self-start p-4">
        <h2 class="card-title mb-3 px-2">Periode</h2>
        <select class="form-input lg:hidden" id="tkPilihPeriode" aria-label="Pilih periode">
            @foreach ($periode as $p)
                <option value="{{ route('tenaga-kerja.slip-gaji', ['periode' => $p['kunci']]) }}" @selected($p['kunci'] === $kunciDipilih)>{{ $p['label'] }}</option>
            @endforeach
        </select>
        <nav class="hidden flex-col gap-1 lg:flex">
            @foreach ($periode as $p)
                <a href="{{ route('tenaga-kerja.slip-gaji', ['periode' => $p['kunci']]) }}"
                   @class([
                       'flex items-center justify-between rounded-md px-3 py-2 text-sm',
                       'bg-blue-50 font-medium text-sji-biru' => $p['kunci'] === $kunciDipilih,
                       'text-gray-600 hover:bg-gray-50' => $p['kunci'] !== $kunciDipilih,
                   ])>
                    <span>{{ $p['label'] }}</span>
                    @if ($loop->first)<span class="badge badge-success">Terbaru</span>@endif
                </a>
            @endforeach
        </nav>
    </section>

    {{-- ===================== Dokumen slip ===================== --}}
    <div class="tk-reveal lg:col-span-3">
        @if (! $slip)
            <section class="card px-6 py-12 text-center">
                <p class="font-semibold text-gray-800">Periode tidak ditemukan</p>
                <p class="mt-1 text-sm text-gray-500">Silakan pilih periode lain di daftar.</p>
            </section>
        @else
            <article class="card overflow-hidden" id="tkSlip">
                <header class="flex flex-wrap items-center justify-between gap-4 border-b-4 border-sji-merah bg-sji-biru-tua px-6 py-5 text-white">
                    <div class="flex items-center gap-3">
                        <img src="{{ asset('images/logo-sji.png') }}" alt="Logo PT Satu Jaya Indonesia" class="size-14 object-contain">
                        <div>
                            <p class="text-lg font-bold tracking-wide">PT SATU JAYA INDONESIA</p>
                            <p class="text-xs text-blue-200">Penyedia Jasa Tenaga Kerja</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-xl font-bold tracking-widest text-sji-emas">SLIP GAJI</p>
                        <p class="text-sm text-blue-200">Periode {{ $labelPeriode }}</p>
                    </div>
                </header>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 border-b border-gray-200 px-6 py-5 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Nama Karyawan</dt><dd class="font-semibold text-gray-800">{{ $namaKaryawan }}</dd></div>
                    @if ($jabatan)<div class="flex justify-between gap-4"><dt class="text-gray-500">Jabatan</dt><dd class="font-semibold text-gray-800">{{ $jabatan }}</dd></div>@endif
                    @if ($kategori)<div class="flex justify-between gap-4"><dt class="text-gray-500">Kategori</dt><dd class="font-semibold text-gray-800">{{ $kategori }}</dd></div>@endif
                    @foreach ($rincian['info'] as $info)
                        <div class="flex justify-between gap-4"><dt class="text-gray-500">{{ $info['label'] }}</dt><dd class="font-semibold text-gray-800">{{ $info['nilai'] }}</dd></div>
                    @endforeach
                </dl>

                <div class="grid grid-cols-1 gap-6 px-6 py-5 md:grid-cols-2">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold tracking-wide text-emerald-700 uppercase">Penghasilan</h3>
                        @forelse ($rincian['penghasilan'] as $item)
                            <div class="flex justify-between border-b border-dashed border-gray-200 py-2 text-sm"><span class="text-gray-600">{{ $item['label'] }}</span><span class="font-medium tabular-nums">{{ $item['nilai'] }}</span></div>
                        @empty
                            <p class="text-sm text-gray-400">Tidak ada rincian penghasilan.</p>
                        @endforelse
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold tracking-wide text-red-700 uppercase">Potongan</h3>
                        @forelse ($rincian['potongan'] as $item)
                            <div class="flex justify-between border-b border-dashed border-gray-200 py-2 text-sm"><span class="text-gray-600">{{ $item['label'] }}</span><span class="font-medium text-red-600 tabular-nums">{{ $item['nilai'] }}</span></div>
                        @empty
                            <p class="text-sm text-gray-400">Tidak ada potongan.</p>
                        @endforelse
                    </div>
                </div>

                @if ($rincian['total'])
                    <div class="mx-6 mb-5 flex flex-wrap items-center justify-between gap-4 rounded-lg bg-blue-50 px-5 py-4">
                        <div>
                            <p class="font-semibold text-sji-biru-tua">{{ $rincian['total']['label'] }}</p>
                            <p class="text-xs text-gray-500">Dihitung otomatis oleh sistem penggajian</p>
                        </div>
                        <p class="text-2xl font-bold text-sji-biru tabular-nums" data-rupiah="{{ $rincian['total']['mentah'] }}">{{ $rincian['total']['nilai'] }}</p>
                    </div>
                @endif

                <footer class="flex flex-wrap justify-between gap-2 border-t border-gray-200 bg-gray-50 px-6 py-3 text-xs text-gray-500">
                    <span>Dokumen ini dibuat secara elektronik dan sah tanpa tanda tangan.</span>
                    <span>Dicetak: {{ now()->locale('id')->translatedFormat('d F Y, H:i') }}</span>
                </footer>
            </article>

            <div class="tk-no-print mt-4 flex justify-end">
                <button type="button" class="tk-btn btn-primary" onclick="window.print()">Cetak / Simpan PDF</button>
            </div>
        @endif
    </div>
</div>
@endif
@endsection
