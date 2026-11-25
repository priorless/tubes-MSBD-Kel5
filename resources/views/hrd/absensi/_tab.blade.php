{{-- Tab bagian Absensi Karyawan (HRD). Variabel: $tab = harian | rekap | koreksi. --}}
<nav class="mb-6 flex gap-2 border-b border-gray-200" aria-label="Bagian absensi karyawan">
    <a href="{{ route('hrd.absensi', ['tab' => 'harian']) }}" @class(['border-b-2 px-4 py-3 text-sm font-medium', 'border-sji-biru text-sji-biru' => $tab === 'harian', 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== 'harian']) @if ($tab === 'harian') aria-current="page" @endif>Absensi Harian</a>
    <a href="{{ route('hrd.absensi', ['tab' => 'rekap']) }}" @class(['border-b-2 px-4 py-3 text-sm font-medium', 'border-sji-biru text-sji-biru' => $tab === 'rekap', 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== 'rekap']) @if ($tab === 'rekap') aria-current="page" @endif>Rekap Absensi</a>
    <a href="{{ route('hrd.absensi.koreksi-check-out.index') }}" @class(['border-b-2 px-4 py-3 text-sm font-medium', 'border-sji-biru text-sji-biru' => $tab === 'koreksi', 'border-transparent text-gray-500 hover:text-gray-700' => $tab !== 'koreksi']) @if ($tab === 'koreksi') aria-current="page" @endif>Koreksi Check-out</a>
</nav>
