@extends('layouts.app')

@section('title', 'Jam Kerja Tersimpan')

@section('content')
    <div class="space-y-6">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2 class="card-title">Jam Kerja Tersimpan</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Jam masuk dan pulang yang bisa dipilih di Jadwal Kerja. Untuk pola satu bulan, gunakan
                        <a href="{{ route('operasional.jadwal-kerja.template-jadwal.index') }}" class="text-sji-biru hover:underline">Pola Jadwal Bulanan</a>.
                    </p>
                </div>
                <a href="{{ route('operasional.jadwal-kerja') }}" class="btn-secondary">Kembali ke Jadwal Kerja</a>
            </div>

            <form action="{{ route('operasional.jadwal-kerja.template-shift.store') }}" method="POST" class="card-body space-y-4">
                @csrf
                @if ($errors->any())
                    <div class="callout callout-danger">
                        <p class="font-semibold">Periksa kembali isian jam tersimpan.</p>
                        <ul class="mt-2 list-inside list-disc">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <h3 class="font-semibold text-gray-800">Tambah jam tersimpan</h3>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label for="id_klien" class="form-label">Klien</label>
                        <select id="id_klien" name="id_klien" class="form-input" required>
                            <option value="">Pilih klien</option>
                            @foreach ($klien as $item)
                                <option value="{{ $item->id }}" @selected((string) old('id_klien') === (string) $item->id)>
                                    {{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}
                                </option>
                            @endforeach
                        </select>
                        @error('id_klien') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="nama_shift" class="form-label">Nama</label>
                        <input id="nama_shift" name="nama_shift" type="text" maxlength="100" class="form-input"
                            value="{{ old('nama_shift') }}" required>
                        @error('nama_shift') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="jam_masuk" class="form-label">Jam Masuk</label>
                        <input id="jam_masuk" name="jam_masuk" type="time" class="form-input"
                            value="{{ old('jam_masuk') }}" required>
                        @error('jam_masuk') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="jam_pulang" class="form-label">Jam Pulang</label>
                        <input id="jam_pulang" name="jam_pulang" type="time" class="form-input"
                            value="{{ old('jam_pulang') }}" required>
                        @error('jam_pulang') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="card-footer justify-start">
                    <button type="submit" class="btn-primary" @disabled($klien->isEmpty())>Simpan Jam</button>
                    @if ($klien->isEmpty())
                        <p class="form-hint">Belum ada data klien.</p>
                    @endif
                </div>
            </form>
        </div>

        <div class="card">
            <form action="{{ route('operasional.jadwal-kerja.template-shift.standar') }}" method="POST" class="card-body space-y-3">
                @csrf
                <h3 class="font-semibold text-gray-800">Jam standar</h3>
                <p class="text-sm text-gray-500">
                    Shift Pagi 08:00&ndash;16:00, Shift Malam 20:00&ndash;04:00, Shift Siang 08:00&ndash;20:00, Shift Malam 12 Jam 20:00&ndash;08:00.
                </p>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label for="id_klien_standar" class="form-label">Klien</label>
                        <select id="id_klien_standar" name="id_klien" class="form-input" required>
                            <option value="">Pilih klien</option>
                            @foreach ($klien as $item)
                                <option value="{{ $item->id }}">{{ $item->kode_klien }} &mdash; {{ $item->nama_perusahaan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn-secondary" @disabled($klien->isEmpty())>Buat Jam Standar</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Jam tersimpan yang tersedia</h3>
            </div>
            <div class="card-body">
                @if ($templateShift->isEmpty())
                    <p class="text-sm text-gray-500">Belum ada jam tersimpan.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($templateShift as $item)
                            <div class="rounded-lg border border-gray-200 p-4">
                                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h4 class="font-semibold text-gray-800">{{ $item->nama_perusahaan }}</h4>
                                        <p class="text-sm text-gray-500">{{ $item->kode_klien }}</p>
                                    </div>
                                    <span @class(['badge badge-success' => $item->status === 'Aktif', 'badge badge-secondary' => $item->status !== 'Aktif'])>
                                        {{ $item->status }}
                                    </span>
                                </div>

                                <form action="{{ route('operasional.jadwal-kerja.template-shift.update', $item->id) }}"
                                      method="POST" class="grid grid-cols-1 items-end gap-4 md:grid-cols-2 xl:grid-cols-4">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label for="nama_shift_{{ $item->id }}" class="form-label">Nama</label>
                                        <input id="nama_shift_{{ $item->id }}" name="nama_shift" type="text" maxlength="100"
                                            class="form-input" value="{{ $item->nama_shift }}" required>
                                    </div>
                                    <div>
                                        <label for="jam_masuk_{{ $item->id }}" class="form-label">Jam Masuk</label>
                                        <input id="jam_masuk_{{ $item->id }}" name="jam_masuk" type="time" class="form-input"
                                            value="{{ substr($item->jam_masuk, 0, 5) }}" required>
                                    </div>
                                    <div>
                                        <label for="jam_pulang_{{ $item->id }}" class="form-label">Jam Pulang</label>
                                        <input id="jam_pulang_{{ $item->id }}" name="jam_pulang" type="time" class="form-input"
                                            value="{{ substr($item->jam_pulang, 0, 5) }}" required>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button type="submit" class="btn-primary">Simpan Perubahan</button>
                                        @php
                                            $durasiJam = intdiv((int) $item->durasi_menit, 60);
                                            $durasiSisaMenit = (int) $item->durasi_menit % 60;
                                            $pulangHariBerikutnya = substr($item->jam_pulang, 0, 5) <= substr($item->jam_masuk, 0, 5);
                                        @endphp
                                        <span class="text-sm text-gray-500">
                                            {{ $durasiJam }} jam
                                            @if ($durasiSisaMenit > 0)
                                                {{ $durasiSisaMenit }} menit
                                            @endif
                                            @if ($pulangHariBerikutnya)
                                                (pulang hari berikutnya)
                                            @endif
                                        </span>
                                    </div>
                                </form>

                                @if ($item->status === 'Aktif')
                                    <form action="{{ route('operasional.jadwal-kerja.template-shift.deactivate', $item->id) }}"
                                          method="POST" class="mt-3">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-secondary btn-sm">Nonaktifkan</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
