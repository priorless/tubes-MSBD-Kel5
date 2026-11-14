@extends('layouts.app')

@section('title', 'Invoice Klien')

@section('content')
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('keuangan.invoice.generate') }}" class="card self-start">
            @csrf

            <div class="card-header">
                <h2 class="card-title">Generate Invoice</h2>
            </div>

            <div class="card-body space-y-4">
                <div>
                    <label for="id_klien" class="form-label">Klien</label>
                    <select name="id_klien" id="id_klien" @class(['form-input', 'form-input-error' => $errors->has('id_klien')]) required>
                        <option value="">&mdash; Pilih klien &mdash;</option>
                        @foreach ($klien as $item)
                            <option value="{{ $item->id }}" @selected((int) old('id_klien') === (int) $item->id)>
                                {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-12 gap-3">
                    <div class="col-span-7">
                        <label for="bulan" class="form-label">Bulan</label>
                        <select name="bulan" id="bulan" @class(['form-input', 'form-input-error' => $errors->has('bulan')]) required>
                            @foreach ($daftarBulan as $angka => $nama)
                                <option value="{{ $angka }}" @selected((int) old('bulan', $bulanIni) === $angka)>{{ $nama }}</option>
                            @endforeach
                        </select>
                        @error('bulan') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="col-span-5">
                        <label for="tahun" class="form-label">Tahun</label>
                        <input type="number" name="tahun" id="tahun" min="2000" max="2100" value="{{ old('tahun', $tahunIni) }}"
                               @class(['form-input', 'form-input-error' => $errors->has('tahun')]) required>
                        @error('tahun') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="text-xs text-gray-500">
                    Jumlah tenaga dan total tagihan dihitung otomatis oleh database dari penempatan aktif klien pada periode tersebut.
                </p>
            </div>

            <div class="card-footer">
                <button type="submit" class="btn-primary w-full">Generate Invoice</button>
            </div>
        </form>

        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Daftar invoice</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>No. Invoice</th>
                            <th>Klien</th>
                            <th>Periode</th>
                            <th class="text-center">Tenaga</th>
                            <th class="text-right">Total Tagihan</th>
                            <th>Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoice as $item)
                            <tr>
                                <td class="font-mono whitespace-nowrap text-gray-600">{{ $item->nomor_invoice }}</td>
                                <td>{{ $item->nama_perusahaan }}</td>
                                <td class="whitespace-nowrap">{{ $daftarBulan[$item->periode_bulan] ?? $item->periode_bulan }} {{ $item->periode_tahun }}</td>
                                <td class="text-center">{{ $item->jumlah_tenaga }}</td>
                                <td class="text-right whitespace-nowrap tabular-nums">Rp {{ number_format((float) $item->total_tagihan, 0, ',', '.') }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($item->status === 'Lunas')
                                        <span class="badge badge-success">Lunas</span>
                                        <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Carbon::parse($item->tgl_pembayaran)->format('d/m/Y') }}</p>
                                    @else
                                        <span class="badge badge-danger">Belum Lunas</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($item->status !== 'Lunas')
                                        <form method="POST" action="{{ route('keuangan.invoice.lunas', $item->id) }}" class="flex items-center justify-center gap-2"
                                              onsubmit="return confirm(@js('Tandai invoice '.$item->nomor_invoice.' sebagai lunas?'));">
                                            @csrf
                                            @method('PATCH')
                                            <label for="tgl_pembayaran_{{ $item->id }}" class="sr-only">Tanggal pembayaran</label>
                                            <input type="date" name="tgl_pembayaran" id="tgl_pembayaran_{{ $item->id }}"
                                                   value="{{ now()->toDateString() }}" class="form-input w-auto py-1.5" required>
                                            <button type="submit" class="btn-success btn-sm">Tandai Lunas</button>
                                        </form>
                                    @else
                                        <span class="block text-center text-gray-400">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="table-empty">Belum ada invoice.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card-footer">
                <span>Menampilkan {{ $invoice->firstItem() ?? 0 }}&ndash;{{ $invoice->lastItem() ?? 0 }} dari {{ $invoice->total() }} invoice</span>
                @include('partials.pagination', ['paginator' => $invoice])
            </div>
        </div>
    </div>
@endsection
