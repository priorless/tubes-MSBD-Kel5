{{--
    Form "Akhiri Penempatan" (sp_selesai_penempatan), dipakai di Monitoring dan Detail Penempatan.
    Variabel: $idPenempatan, $tglMulai, $namaKaryawan, $namaKlien, $idInput (id unik elemen input);
    opsional: $kelasForm, $tampilkanLabel.
    Tanggal selesai dibatasi antara tanggal mulai dan hari ini (lihat AkhiriPenempatanController).
--}}
@php
    $batasAkhir = \App\Http\Controllers\Operasional\AkhiriPenempatanController::batasTanggalSelesai((string) $tglMulai);
    $pesanKonfirmasi = 'Akhiri penempatan '.$namaKaryawan.' di '.$namaKlien.'? '
        .'Penempatan & kontrak menjadi Selesai dan karyawan kembali Tersedia. Tindakan ini tidak dapat dibatalkan.';
@endphp

<form method="POST" action="{{ route('operasional.penempatan.akhiri', $idPenempatan) }}"
      class="{{ $kelasForm ?? '' }}" onsubmit="return confirm(@js($pesanKonfirmasi))">
    @csrf
    <label for="{{ $idInput }}" class="{{ ($tampilkanLabel ?? false) ? 'form-label' : 'sr-only' }}">Tanggal terakhir bekerja</label>
    <div class="flex flex-wrap items-center gap-2">
        <input type="date" id="{{ $idInput }}" name="tgl_selesai" required
               value="{{ $batasAkhir }}" min="{{ substr((string) $tglMulai, 0, 10) }}" max="{{ $batasAkhir }}"
               class="form-input w-auto py-1.5">
        <button type="submit" class="btn-danger btn-sm">Akhiri</button>
    </div>
</form>
