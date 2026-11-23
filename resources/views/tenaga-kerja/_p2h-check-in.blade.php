{{--
    Checklist P2H (Pemeriksaan Harian Kendaraan) Driver sebelum check-in.
    Semua isian terhubung ke form check-in (#tkFormCheckIn) lewat atribut form=, dan divalidasi ulang di server.
--}}
@use('App\Services\LaporanAktivitasService')

<section class="tk-reveal card p-6 xl:col-span-12" id="p2h">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h2 class="card-title">P2H Kendaraan &mdash; Wajib Sebelum Check-in</h2>
            <p class="mt-1 text-sm text-gray-500">Periksa seluruh 9 komponen, pilih kondisinya, lalu unggah foto kondisi kendaraan. Isi keterangan bila kondisi tidak Baik.</p>
        </div>
        <span class="badge badge-warning" id="tkP2hProgres" data-total="{{ count(LaporanAktivitasService::KOMPONEN_P2H) }}">0/{{ count(LaporanAktivitasService::KOMPONEN_P2H) }} diperiksa</span>
    </div>

    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        @foreach (LaporanAktivitasService::KOMPONEN_P2H as $kunci => $label)
            <fieldset class="rounded-md border border-gray-200 bg-gray-50 p-4" data-p2h-item>
                <legend class="px-1 text-sm font-semibold text-gray-800">{{ $loop->iteration }}. {{ $label }}</legend>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach (LaporanAktivitasService::KONDISI as $kondisi)
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs text-gray-700 has-[:checked]:border-sji-biru has-[:checked]:bg-blue-50 has-[:checked]:font-semibold">
                            <input type="radio" name="p2h[{{ $kunci }}][kondisi]" value="{{ $kondisi }}" form="tkFormCheckIn" required
                                   class="form-check" data-p2h-kondisi>
                            {{ $kondisi }}
                        </label>
                    @endforeach
                </div>
                <input type="text" name="p2h[{{ $kunci }}][keterangan]" form="tkFormCheckIn" maxlength="255" class="form-input mt-2 text-sm"
                       placeholder="Keterangan (wajib bila tidak Baik)" data-p2h-keterangan aria-label="Keterangan {{ $label }}">
            </fieldset>
        @endforeach
    </div>

    <div class="mt-4 max-w-xl">
        @include('tenaga-kerja._input-foto', [
            'nama' => 'foto_kendaraan', 'id' => 'tkFotoKendaraanAwal', 'label' => 'Foto kondisi kendaraan', 'form' => 'tkFormCheckIn',
            'petunjuk' => 'Foto kendaraan secara keseluruhan sebelum digunakan. Foto diri diunggah setelah menekan tombol Check-in.',
        ])
    </div>
</section>
