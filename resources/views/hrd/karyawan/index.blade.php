@extends('layouts.app')

@php
    $warnaStatus = ['Tersedia' => 'info', 'Ditempatkan' => 'primary', 'Aktif' => 'success', 'Cuti' => 'warning', 'Nonaktif' => 'secondary'];
@endphp

@section('title', 'Data Karyawan')

@section('content')
    <x-ui.page-header title="Data Karyawan" description="Daftar data karyawan SJI.">
        <a href="{{ route('hrd.karyawan.create') }}" class="btn-primary">+ Tambah Karyawan</a>
    </x-ui.page-header>

    <div class="card overflow-hidden">
        <form method="GET" action="{{ route('hrd.karyawan.index') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <input type="search" name="q" value="{{ request('q') }}" class="form-input md:col-span-5" placeholder="Cari nama atau NIP" aria-label="Cari nama atau NIP">
            <select name="kategori" class="form-input md:col-span-3" aria-label="Filter kategori">
                <option value="">Semua kategori</option>
                @foreach (['Lapangan', 'Internal'] as $kategori)
                    <option value="{{ $kategori }}" @selected(request('kategori') === $kategori)>{{ $kategori }}</option>
                @endforeach
            </select>
            <select name="status" class="form-input md:col-span-2" aria-label="Filter status">
                <option value="">Semua status</option>
                @foreach (array_keys($warnaStatus) as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn-secondary md:col-span-2">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th class="min-w-56">Karyawan</th>
                        <th class="min-w-36">Layanan</th>
                        <th class="min-w-36 text-right">Gaji Pokok</th>
                        <th class="min-w-44">Kontak</th>
                        <th class="min-w-40">Jaminan Kesehatan</th>
                        <th class="min-w-28">Status</th>
                        <th class="min-w-32">Akun Login</th>
                        <th class="min-w-32 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($karyawan as $item)
                        <tr>
                            <td>
                                <div class="flex items-center gap-3">
                                    @if ($item->foto)
                                        <button type="button"
                                                class="size-9 shrink-0 cursor-zoom-in overflow-hidden rounded-full transition-transform hover:scale-105 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-sji-biru"
                                                data-foto-karyawan
                                                data-foto-url="{{ asset('storage/'.$item->foto) }}"
                                                data-foto-nama="{{ $item->nama }}"
                                                aria-haspopup="dialog"
                                                aria-controls="fotoKaryawanModal"
                                                aria-label="Lihat foto {{ $item->nama }}">
                                            <img src="{{ asset('storage/'.$item->foto) }}" alt="" class="size-9 rounded-full object-cover">
                                        </button>
                                    @else
                                        <span class="flex size-9 items-center justify-center rounded-full bg-gray-200 text-sm font-semibold text-gray-500">{{ mb_substr($item->nama, 0, 1) }}</span>
                                    @endif
                                    <div>
                                        <p class="font-medium whitespace-nowrap text-gray-800">{{ $item->nama }}</p>
                                        <p class="text-xs text-gray-400">NIP {{ $item->nip }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="whitespace-nowrap">{{ $item->nama_layanan ?: '-' }}</td>
                            <td class="whitespace-nowrap text-right">
                                @if ($item->gaji_pokok_tampil !== null)
                                    Rp {{ number_format((float) $item->gaji_pokok_tampil, 0, ',', '.') }}
                                @else
                                    -
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $item->no_telp }}
                                @if ($item->email)
                                    <p class="text-xs text-gray-400">{{ $item->email }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($item->jenis_jaminan_kesehatan)
                                    <span class="badge badge-{{ $item->jenis_jaminan_kesehatan === 'BPJS' ? 'success' : 'primary' }}">{{ $item->jenis_jaminan_kesehatan }}</span>
                                @else
                                    <span class="text-gray-400">Belum diisi</span>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $warnaStatus[$item->status] ?? 'secondary' }}">{{ $item->status }}</span></td>
                            <td>
                                @if ($item->username)
                                    <span class="font-mono text-gray-600">{{ $item->username }}</span>
                                @else
                                    <span class="text-gray-400">&mdash;</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('hrd.karyawan.edit', $item->id) }}" class="btn-secondary btn-sm">Ubah</a>
                                    @if ($item->status !== 'Nonaktif')
                                    <form action="{{ route('hrd.karyawan.destroy', $item->id) }}" method="POST"
                                          onsubmit="return confirm(@js('Nonaktifkan karyawan '.$item->nama.' beserta akun loginnya?'))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn-outline-danger btn-sm">Nonaktifkan</button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="table-empty">Belum ada data karyawan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $karyawan->firstItem() ?? 0 }}&ndash;{{ $karyawan->lastItem() ?? 0 }} dari {{ $karyawan->total() }} karyawan</span>
            @include('partials.pagination', ['paginator' => $karyawan])
        </div>
    </div>

    <dialog id="fotoKaryawanModal" aria-labelledby="fotoKaryawanModalJudul"
            class="m-auto max-h-[92vh] w-[calc(100%-2rem)] max-w-5xl overflow-hidden rounded-xl border-0 bg-white p-0 shadow-2xl backdrop:bg-gray-900/70">
        <div class="flex items-center justify-between gap-3 border-b border-gray-200 px-5 py-3">
            <h2 id="fotoKaryawanModalJudul" class="text-sm font-semibold text-gray-800">Foto profil karyawan</h2>
            <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-800"
                    data-foto-modal-tutup aria-label="Tutup foto">
                <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="flex max-h-[80vh] justify-center bg-gray-900 p-3">
            <img id="fotoKaryawanModalGambar" alt="" class="max-h-[76vh] max-w-full object-contain">
        </div>
    </dialog>
@endsection

@push('scripts')
    <script>
        (() => {
            const modal = document.getElementById('fotoKaryawanModal');
            const gambar = document.getElementById('fotoKaryawanModalGambar');
            const judul = document.getElementById('fotoKaryawanModalJudul');

            document.querySelectorAll('[data-foto-karyawan]').forEach((tombol) => {
                tombol.addEventListener('click', () => {
                    gambar.src = tombol.dataset.fotoUrl;
                    gambar.alt = `Foto ${tombol.dataset.fotoNama}`;
                    judul.textContent = `Foto profil ${tombol.dataset.fotoNama}`;
                    modal.showModal();
                });
            });

            modal.querySelector('[data-foto-modal-tutup]').addEventListener('click', () => modal.close());
            modal.addEventListener('click', (event) => {
                if (event.target !== gambar) {
                    modal.close();
                }
            });
        })();
    </script>
@endpush
