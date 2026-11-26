{{-- Unggahan koreksi foto untuk satu laporan aktivitas. Variabel: $laporan, $judulFoto. --}}
<details class="mt-2 rounded-md border border-gray-200 bg-gray-50 p-3">
    <summary class="cursor-pointer text-sm font-semibold text-gray-700">Koreksi Foto</summary>
    <form method="POST" action="{{ route('tenaga-kerja.laporan.koreksi', $laporan->id) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
        @csrf
        @include('tenaga-kerja._input-foto', ['nama' => 'foto', 'id' => 'tkFotoKoreksi'.$laporan->id, 'label' => $judulFoto.' (koreksi)'])
        <div>
            <label for="tkKeteranganKoreksi{{ $laporan->id }}" class="form-label">Keterangan (opsional, mengganti keterangan sebelumnya)</label>
            <textarea id="tkKeteranganKoreksi{{ $laporan->id }}" name="keterangan" rows="2" minlength="3" maxlength="500" class="form-input"></textarea>
        </div>
        <button type="submit" class="btn-secondary">Unggah Koreksi Foto</button>
    </form>
</details>
