{{-- Unggahan koreksi (kesempatan terakhir) untuk satu laporan aktivitas. Variabel: $laporan, $judulFoto. --}}
<details class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3">
    <summary class="cursor-pointer text-sm font-semibold text-amber-800">Koreksi foto (kesempatan terakhir)</summary>
    <form method="POST" action="{{ route('tenaga-kerja.laporan.koreksi', $laporan->id) }}" enctype="multipart/form-data" class="mt-2 space-y-2"
          onsubmit="return confirm('Unggah foto koreksi? Setelah ini foto laporan tidak dapat diganti lagi.')">
        @csrf
        @include('tenaga-kerja._input-foto', ['nama' => 'foto', 'id' => 'tkFotoKoreksi'.$laporan->id, 'label' => $judulFoto.' (koreksi)'])
        <div>
            <label for="tkKeteranganKoreksi{{ $laporan->id }}" class="form-label">Keterangan (opsional, mengganti keterangan sebelumnya)</label>
            <textarea id="tkKeteranganKoreksi{{ $laporan->id }}" name="keterangan" rows="2" minlength="3" maxlength="500" class="form-input"></textarea>
        </div>
        <button type="submit" class="btn-secondary">Unggah Koreksi</button>
    </form>
</details>
