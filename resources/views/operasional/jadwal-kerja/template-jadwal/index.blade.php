@extends('layouts.app')

@section('title', 'Pola Jadwal Bulanan')

@section('content')
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Pola Jadwal Bulanan</h2>
                <p class="mt-1 text-sm text-gray-500">Pola jadwal yang dapat dipakai berulang kali. Berbeda dengan Salin Jadwal Bulanan yang menyalin jadwal aktual dari satu bulan sumber.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('operasional.jadwal-kerja') }}" class="btn-secondary btn-sm">Kembali ke Jadwal Kerja</a>
                <a href="{{ route('operasional.jadwal-kerja.template-jadwal.create', array_filter(['jenis' => $jenis])) }}" class="btn-primary btn-sm">+ Buat Pola</a>
            </div>
        </div>

        <div class="card-body space-y-4">
            <nav class="flex flex-wrap gap-2" aria-label="Filter jenis pola">
                @foreach ([null => 'Semua', 'Pribadi' => 'Pola Pribadi', 'Bersama' => 'Pola Bersama'] as $nilai => $label)
                    @php $nilai = $nilai === '' ? null : $nilai; @endphp
                    <a href="{{ route('operasional.jadwal-kerja.template-jadwal.index', array_filter(['jenis' => $nilai])) }}"
                       @class(['btn-sm', 'btn-primary' => $jenis === $nilai, 'btn-secondary' => $jenis !== $nilai])
                       @if ($jenis === $nilai) aria-current="page" @endif>
                        {{ $label }}
                        ({{ $nilai === null ? $jumlahPerJenis->sum() : ($jumlahPerJenis[$nilai] ?? 0) }})
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nama Pola</th>
                        <th>Jenis</th>
                        <th>Berlaku Untuk</th>
                        <th>Pola</th>
                        <th>Status</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($daftarTemplate as $item)
                        <tr>
                            <td>
                                <a href="{{ route('operasional.jadwal-kerja.template-jadwal.show', $item->id) }}" class="font-medium text-sji-biru hover:underline">{{ $item->nama_template }}</a>
                                @if ($item->keterangan)
                                    <p class="text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($item->keterangan, 80) }}</p>
                                @endif
                            </td>
                            <td><span class="badge badge-{{ $item->jenis === 'Pribadi' ? 'info' : 'primary' }}">{{ $item->jenis }}</span></td>
                            <td>
                                @if ($item->jenis === 'Pribadi')
                                    {{ $item->nip }} &mdash; {{ $item->nama_karyawan }}
                                @else
                                    {{ $item->nama_perusahaan }}
                                    <p class="text-xs text-gray-400">{{ $item->nama_layanan ?? 'Semua jenis layanan' }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                {{ $item->jumlah_hari }} hari diatur
                                <p class="text-xs text-gray-400">Dipakai {{ $item->jumlah_penerapan }} kali</p>
                            </td>
                            <td><span class="badge badge-{{ $item->status === 'Aktif' ? 'success' : 'secondary' }}">{{ $item->status }}</span></td>
                            <td>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <a href="{{ route('operasional.jadwal-kerja.template-jadwal.show', $item->id) }}" class="btn-secondary btn-sm">Detail</a>
                                    @if ($item->status === 'Aktif')
                                        <a href="{{ route('operasional.jadwal-kerja.template-jadwal.apply.preview', $item->id) }}" class="btn-primary btn-sm">Gunakan Pola</a>
                                        <a href="{{ route('operasional.jadwal-kerja.template-jadwal.edit', $item->id) }}" class="btn-secondary btn-sm">Edit</a>
                                        <form action="{{ route('operasional.jadwal-kerja.template-jadwal.deactivate', $item->id) }}" method="POST"
                                              onsubmit="return confirm('Nonaktifkan pola ini? Jadwal yang sudah dibuat dari pola ini tidak berubah.')">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-outline-danger btn-sm">Nonaktifkan</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="table-empty">
                                Belum ada pola{{ $jenis === null ? '' : ' '.$jenis }}. Buat pola baru, atau simpan jadwal bulanan karyawan sebagai pola dari halaman Jadwal Kerja.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
