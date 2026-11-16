@extends('layouts.app')

@section('title', 'Log Aktivitas')

@php
    $warnaAksi = ['INSERT' => 'success', 'UPDATE' => 'info', 'DELETE' => 'danger'];
    $labelAksi = ['INSERT' => 'Tambah', 'UPDATE' => 'Ubah', 'DELETE' => 'Hapus'];
@endphp

@section('content')
    <div class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Log Aktivitas Sistem</h2>
                <p class="text-sm text-gray-500">Dicatat otomatis oleh database setiap ada perubahan penting. Log tidak dapat diubah atau dihapus dari aplikasi.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('ceo.log-aktivitas') }}" class="grid grid-cols-1 gap-3 border-b border-gray-200 bg-gray-50 px-6 py-4 md:grid-cols-12">
            <select name="tabel" class="form-input md:col-span-4" aria-label="Filter tabel">
                <option value="">Semua data</option>
                @foreach ($daftarTabel as $tabel => $label)
                    <option value="{{ $tabel }}" @selected(($filter['tabel'] ?? '') === $tabel)>{{ $label }}</option>
                @endforeach
            </select>
            <div class="md:col-span-3">
                <label for="dari" class="sr-only">Dari tanggal</label>
                <input type="date" id="dari" name="dari" value="{{ $filter['dari'] ?? '' }}" class="form-input" title="Dari tanggal">
            </div>
            <div class="md:col-span-3">
                <label for="sampai" class="sr-only">Sampai tanggal</label>
                <input type="date" id="sampai" name="sampai" value="{{ $filter['sampai'] ?? '' }}" class="form-input" title="Sampai tanggal">
            </div>
            <div class="flex gap-2 md:col-span-2">
                <button type="submit" class="btn-secondary flex-1">Terapkan</button>
                @if (array_filter($filter))
                    <a href="{{ route('ceo.log-aktivitas') }}" class="btn-secondary" title="Hapus filter">&times;</a>
                @endif
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Data</th>
                        <th>Aksi</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($log as $item)
                        <tr>
                            <td class="whitespace-nowrap text-gray-600">{{ \Illuminate\Support\Carbon::parse($item->waktu)->format('d/m/Y H:i:s') }}</td>
                            <td class="whitespace-nowrap">
                                @if ($item->username)
                                    <span class="font-medium text-gray-800">{{ $item->nama_user ?: $item->username }}</span>
                                    <p class="font-mono text-xs text-gray-400">{{ $item->username }}</p>
                                @elseif ($item->id_user)
                                    <span class="text-gray-500">Akun #{{ $item->id_user }} (sudah dihapus)</span>
                                @else
                                    <span class="text-gray-400">Sistem / di luar aplikasi</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">{{ $daftarTabel[$item->nama_tabel] ?? $item->nama_tabel }} <span class="text-xs text-gray-400">#{{ $item->id_data }}</span></td>
                            <td><span class="badge badge-{{ $warnaAksi[$item->aksi] ?? 'secondary' }}">{{ $labelAksi[$item->aksi] ?? $item->aksi }}</span></td>
                            <td class="text-gray-700">{{ $item->keterangan }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="table-empty">Belum ada aktivitas yang sesuai filter.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer">
            <span>Menampilkan {{ $log->firstItem() ?? 0 }}&ndash;{{ $log->lastItem() ?? 0 }} dari {{ $log->total() }} aktivitas</span>
            @include('partials.pagination', ['paginator' => $log])
        </div>
    </div>
@endsection
