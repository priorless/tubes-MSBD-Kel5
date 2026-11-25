{{--
    Kotak pencarian halaman Keuangan (GET ?q=).
    Parameter:
      $id           id input pencarian
      $nilai        kata kunci aktif ('' bila tidak mencari)
      $placeholder  contoh isi, mis. "Cari NIP atau nama karyawan"
      $urlReset     URL tanpa ?q= (dipakai tombol hapus)
      $action       URL tujuan form (diabaikan bila $formId diisi)
      $tersembunyi  [nama => nilai] filter lain yang ikut dikirim (diabaikan bila $formId diisi)
      $formId       opsional: id form GET lain yang sudah ada; input dihubungkan lewat atribut form
--}}
@php
    $formId ??= null;
    $tersembunyi ??= [];
@endphp

@if ($formId === null)
    <form method="GET" action="{{ $action }}" role="search" class="keu-cari">
        @foreach ($tersembunyi as $nama => $isi)
            @if (filled($isi))
                <input type="hidden" name="{{ $nama }}" value="{{ $isi }}">
            @endif
        @endforeach
@else
    <div role="search" class="keu-cari">
@endif
        <label for="{{ $id }}" class="sr-only">{{ $placeholder }}</label>
        <div class="keu-cari-kotak">
            <svg class="keu-cari-ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" id="{{ $id }}" value="{{ $nilai }}" maxlength="100" autocomplete="off"
                   placeholder="{{ $placeholder }}" @if ($formId) form="{{ $formId }}" @endif>
            @if ($nilai !== '')
                <a href="{{ $urlReset }}" class="keu-cari-hapus" aria-label="Hapus pencarian" title="Hapus pencarian">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </a>
            @endif
            <button type="submit" class="keu-cari-tombol" @if ($formId) form="{{ $formId }}" @endif>Cari</button>
        </div>
@if ($formId === null)
    </form>
@else
    </div>
@endif
