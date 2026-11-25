@extends('layouts.app')

@section('title', 'Monitoring Tenaga Kerja')

@use('App\Support\FormatKolom')

@php
    $labelPeriode = static function (object $item): string {
        if ($item->jenis === \App\Services\LaporanAktivitasService::JENIS_PERJALANAN) {
            return $item->tahapan ?: 'Laporan perjalanan';
        }

        $label = 'Laporan ke-'.($item->periode_ke ?? '-');

        return $item->periode_mulai && $item->periode_selesai
            ? $label.' · '.FormatKolom::jam($item->periode_mulai).'–'.FormatKolom::jam($item->periode_selesai)
            : $label;
    };
@endphp

@section('content')
    <x-ui.page-header :kicker="now()->locale('id')->translatedFormat('l, j F Y').' · Monitoring'"
        title="Monitoring Tenaga Kerja"
        description="Laporan aktivitas berfoto yang dikirim tenaga kerja dari halaman Absensi, dikelompokkan per tenaga kerja." />

    <form method="GET" action="{{ route('hrd.monitoring') }}" class="card mb-6">
        <div class="card-body grid grid-cols-1 gap-4 md:grid-cols-12 md:items-end">
            <div class="md:col-span-3">
                <label for="tanggal" class="form-label">Tanggal laporan</label>
                <input id="tanggal" name="tanggal" type="date" value="{{ $tanggal }}"
                    @class(['form-input', 'form-input-error' => $errors->has('tanggal')])>
                @error('tanggal') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="md:col-span-5">
                <label for="q" class="form-label">Nama tenaga kerja</label>
                <input id="q" name="q" type="search" value="{{ $kataKunci }}" maxlength="100" placeholder="Cari nama atau NIP"
                    @class(['form-input', 'form-input-error' => $errors->has('q')])>
                @error('q') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2 md:col-span-4">
                <button type="submit" class="btn-primary">Terapkan</button>
            </div>
        </div>
    </form>

    @if ($tenagaKerja === null && $laporan === null)
        <x-ui.empty-state title="Fitur laporan aktivitas belum tersedia." message="Tabel laporan aktivitas belum ada di database." />
    @elseif ($detailKaryawanId !== null)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold text-gray-800">{{ $karyawan->nama ?? 'Tenaga kerja' }}</h2>
                @if ($karyawan)
                    <p class="text-sm text-gray-500">{{ $karyawan->nip }} · {{ $karyawan->nama_layanan ?? '—' }}</p>
                @endif
                <p class="text-sm text-gray-500">{{ $laporan->total() }} laporan sesuai filter</p>
            </div>
            <a href="{{ route('hrd.monitoring', request()->only('tanggal', 'q')) }}" class="btn-secondary">Kembali ke daftar tenaga kerja</a>
        </div>

        @if ($laporan->isEmpty())
            <x-ui.empty-state title="Belum ada laporan aktivitas."
                :message="$tanggal || $kataKunci !== '' ? 'Tidak ada laporan yang cocok dengan filter ini.' : 'Tenaga kerja ini belum memiliki laporan aktivitas.'" />
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($laporan as $item)
                    @php
                        $dikoreksi = $item->foto_koreksi !== null;
                        $urlFoto = route('absensi.laporan.foto', ['laporan' => $item->id, 'versi' => $dikoreksi ? 'koreksi' : 'utama']);
                        $tanggalLaporan = FormatKolom::tanggal($item->waktu_lapor, 'd/m/Y');
                        $periode = $labelPeriode($item);
                    @endphp
                    <article class="card flex flex-col overflow-hidden" data-laporan="{{ $item->id }}">
                        <button type="button" class="group relative block aspect-[4/3] w-full overflow-hidden bg-gray-100"
                            aria-haspopup="dialog" aria-label="Lihat laporan {{ $item->nama }} {{ $tanggalLaporan }}"
                            data-laporan-buka
                            data-foto-url="{{ $urlFoto }}"
                            data-foto-awal-url="{{ $dikoreksi ? route('absensi.laporan.foto', ['laporan' => $item->id, 'versi' => 'utama']) : '' }}"
                            data-judul="{{ $item->nama }} ({{ $item->nip }})"
                            data-info="{{ $tanggalLaporan }} · {{ $periode }} · dikirim {{ FormatKolom::jam($item->waktu_lapor) }}{{ $dikoreksi ? ' · dikoreksi '.FormatKolom::jam($item->waktu_koreksi) : '' }}"
                            data-keterangan="{{ $item->keterangan }}">
                            <img src="{{ $urlFoto }}" alt="Foto laporan {{ $item->nama }} {{ $tanggalLaporan }}" loading="lazy"
                                class="size-full object-cover transition-transform group-hover:scale-105">
                        </button>
                        <div class="flex flex-1 flex-col gap-2 p-4 text-sm">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate font-semibold text-gray-800">{{ $item->nama }}</p>
                                    <p class="text-xs text-gray-500">{{ $item->nip }} · {{ $item->nama_layanan ?? '—' }}</p>
                                </div>
                                <span class="badge {{ $dikoreksi ? 'badge-warning' : 'badge-success' }} shrink-0">{{ $dikoreksi ? 'Dikoreksi' : 'Terkirim' }}</span>
                            </div>
                            <p class="text-gray-700">{{ $tanggalLaporan }} · {{ $periode }}</p>
                            <p class="line-clamp-2 text-gray-600">{{ $item->keterangan }}</p>
                            <p class="mt-auto text-xs text-gray-400">
                                Dikirim {{ FormatKolom::jam($item->waktu_lapor) }}
                                @if ($item->waktu_koreksi) · Dikoreksi {{ FormatKolom::jam($item->waktu_koreksi) }} @endif
                                @if ($item->nama_perusahaan) · {{ $item->nama_perusahaan }} @endif
                                @if ($item->lokasi_kerja) · {{ $item->lokasi_kerja }} @endif
                            </p>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="card-footer mt-6 rounded-lg border border-gray-200 bg-white">
                <span>Menampilkan {{ $laporan->firstItem() ?? 0 }}–{{ $laporan->lastItem() ?? 0 }} dari {{ $laporan->total() }} laporan</span>
                @include('partials.pagination', ['paginator' => $laporan])
            </div>
        @endif

        <dialog id="laporanModal" aria-labelledby="laporanModalJudul"
                class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-gray-900/70">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
                <div class="min-w-0">
                    <h2 id="laporanModalJudul" class="truncate text-sm font-semibold text-gray-800"></h2>
                    <p id="laporanModalInfo" class="text-xs text-gray-500"></p>
                </div>
                <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800" data-laporan-tutup aria-label="Tutup laporan">
                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="flex max-h-[62vh] justify-center bg-gray-900 p-3">
                <img id="laporanModalGambar" alt="" class="max-h-[58vh] max-w-full object-contain">
            </div>
            <div class="space-y-2 px-5 py-4 text-sm">
                <p class="form-label mb-0">Keterangan aktivitas</p>
                <p id="laporanModalKeterangan" class="whitespace-pre-line text-gray-700"></p>
                <a id="laporanModalFotoAwal" href="#" target="_blank" rel="noopener" class="hidden text-sm font-medium text-sji-biru hover:underline">Lihat foto sebelum dikoreksi</a>
            </div>
        </dialog>
    @elseif ($tenagaKerja->isEmpty())
        <x-ui.empty-state title="Belum ada laporan aktivitas."
            :message="$tanggal || $kataKunci !== '' ? 'Tidak ada laporan yang cocok dengan filter ini.' : 'Laporan yang dikirim tenaga kerja dari halaman Absensi akan tampil di sini.'" />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            @foreach ($tenagaKerja as $item)
                <a href="{{ route('hrd.monitoring', array_merge(request()->only('tanggal', 'q'), ['karyawan' => $item->id_karyawan])) }}"
                    class="card flex min-h-44 flex-col justify-between gap-4 p-5 transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-sji-biru"
                    data-tenaga-kerja="{{ $item->id_karyawan }}">
                    <div>
                        <h2 class="truncate font-semibold text-gray-800">{{ $item->nama }}</h2>
                        <p class="text-sm text-gray-500">{{ $item->nip }} · {{ $item->nama_layanan ?? '—' }}</p>
                        <p class="mt-3 text-sm text-gray-600">Tanggal monitoring: {{ FormatKolom::tanggal($item->tanggal_monitoring, 'd/m/Y') }}</p>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="badge badge-info">{{ $item->jumlah_laporan }} laporan</span>
                        <span class="text-sm font-medium text-sji-biru">Lihat Semua Laporan →</span>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="card-footer mt-6 rounded-lg border border-gray-200 bg-white">
            <span>Menampilkan {{ $tenagaKerja->firstItem() ?? 0 }}–{{ $tenagaKerja->lastItem() ?? 0 }} dari {{ $tenagaKerja->total() }} tenaga kerja</span>
            @include('partials.pagination', ['paginator' => $tenagaKerja])
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        (() => {
            const modal = document.getElementById('laporanModal');
            if (!modal) return;

            const gambar = document.getElementById('laporanModalGambar');
            const fotoAwal = document.getElementById('laporanModalFotoAwal');

            document.querySelectorAll('[data-laporan-buka]').forEach((tombol) => {
                tombol.addEventListener('click', () => {
                    gambar.src = tombol.dataset.fotoUrl;
                    gambar.alt = 'Foto laporan ' + tombol.dataset.judul;
                    document.getElementById('laporanModalJudul').textContent = tombol.dataset.judul;
                    document.getElementById('laporanModalInfo').textContent = tombol.dataset.info;
                    document.getElementById('laporanModalKeterangan').textContent = tombol.dataset.keterangan;
                    fotoAwal.href = tombol.dataset.fotoAwalUrl || '#';
                    fotoAwal.classList.toggle('hidden', !tombol.dataset.fotoAwalUrl);
                    modal.showModal();
                });
            });

            modal.querySelector('[data-laporan-tutup]').addEventListener('click', () => modal.close());
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.close();
            });
        })();
    </script>
@endpush
