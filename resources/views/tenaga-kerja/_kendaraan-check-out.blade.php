{{--
    Kondisi akhir kendaraan Driver sebelum check-out. Isian terhubung ke form check-out (#tkFormCheckOut).
--}}
@use('App\Services\LaporanAktivitasService')

<section class="tk-reveal card p-6 xl:col-span-12" id="kendaraan-akhir">
    <div class="mb-4">
        <h2 class="card-title">Kondisi Akhir Kendaraan &mdash; Wajib Sebelum Check-out</h2>
        <p class="mt-1 text-sm text-gray-500">Isi setelah kendaraan kembali ke kantor. Foto diri diunggah setelah menekan tombol Check-out.</p>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div>
            <span class="form-label">Kondisi akhir kendaraan <span class="text-red-600">*</span></span>
            <div class="flex flex-wrap gap-2">
                @foreach (LaporanAktivitasService::KONDISI as $kondisi)
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 has-[:checked]:border-sji-biru has-[:checked]:bg-blue-50 has-[:checked]:font-semibold">
                        <input type="radio" name="kondisi_akhir" value="{{ $kondisi }}" form="tkFormCheckOut" required class="form-check">
                        {{ $kondisi }}
                    </label>
                @endforeach
            </div>
            <label for="tkCatatanAkhir" class="form-label mt-4">Catatan kondisi akhir</label>
            <textarea id="tkCatatanAkhir" name="catatan_akhir" form="tkFormCheckOut" rows="3" maxlength="500" class="form-input"
                      placeholder="Wajib diisi bila kondisi tidak Baik, mis. lampu sein kiri mati"></textarea>
        </div>
        @include('tenaga-kerja._input-foto', [
            'nama' => 'foto_kendaraan', 'id' => 'tkFotoKendaraanAkhir', 'label' => 'Foto kendaraan setelah digunakan', 'form' => 'tkFormCheckOut',
        ])
    </div>
</section>
