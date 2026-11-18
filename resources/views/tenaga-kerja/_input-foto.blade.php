{{--
    Input foto dengan pratinjau sebelum diunggah (pratinjau oleh public/js/tenaga-kerja.js, data-tk-pratinjau).
    Variabel: $nama, $id, $label, $form (opsional, atribut form=), $wajib (default true), $petunjuk (opsional).
--}}
@php
    $wajib ??= true;
    $ukuranMaksMb = intdiv((int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360), 1024);
@endphp
<div>
    <label for="{{ $id }}" class="form-label">{{ $label }} @if ($wajib)<span class="text-red-600">*</span>@endif</label>
    <input type="file" id="{{ $id }}" name="{{ $nama }}" class="form-input" accept="image/jpeg,image/png,image/webp"
           data-tk-pratinjau="{{ $id }}Pratinjau" data-ukuran-maks-kb="{{ (int) config('tenaga_kerja.foto.ukuran_maks_kb', 15360) }}"
           @if (! empty($form)) form="{{ $form }}" @endif @required($wajib)>
    <div class="mt-2 hidden overflow-hidden rounded-md bg-gray-900" id="{{ $id }}PratinjauWadah">
        <img id="{{ $id }}Pratinjau" alt="Pratinjau {{ strtolower($label) }}" class="max-h-56 w-full object-contain">
    </div>
    <p class="form-hint" id="{{ $id }}Status">{{ $petunjuk ?? "JPG, PNG, atau WEBP, maksimal $ukuranMaksMb MB." }}</p>
</div>
