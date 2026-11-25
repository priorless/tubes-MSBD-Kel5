{{-- Tab menu Jadwal Kerja. Parameter: $tabAktif ('jadwal'|'pertukaran'), $jumlahMenunggu (int). --}}
@php
    $daftarTab = [
        ['kunci' => 'jadwal', 'label' => 'Jadwal Kerja', 'url' => route('operasional.jadwal-kerja')],
        ['kunci' => 'pertukaran', 'label' => 'Pengajuan Backup Shift', 'url' => route('operasional.jadwal-kerja.pertukaran-shift.index')],
    ];
@endphp

<nav class="flex flex-wrap gap-1 border-b border-gray-200" aria-label="Bagian Jadwal Kerja">
    @foreach ($daftarTab as $tab)
        @php
            $aktif = $tab['kunci'] === $tabAktif;
        @endphp
        <a href="{{ $tab['url'] }}"
           @if ($aktif) aria-current="page" @endif
           class="-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium transition {{ $aktif ? 'border-sji-biru text-sji-biru' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
            {{ $tab['label'] }}
            @if ($tab['kunci'] === 'pertukaran' && ($jumlahMenunggu ?? 0) > 0)
                <span class="badge badge-warning" title="Menunggu persetujuan">{{ $jumlahMenunggu }}</span>
            @endif
        </a>
    @endforeach
</nav>
