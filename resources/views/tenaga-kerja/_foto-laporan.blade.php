{{-- Ringkasan satu laporan aktivitas: foto utama, foto koreksi, keterangan, dan waktu server. Variabel: $laporan. --}}
<div class="flex flex-wrap items-start gap-3">
    @foreach (['utama' => 'Utama', 'koreksi' => 'Koreksi'] as $versi => $labelVersi)
        @if (filled($laporan->{'foto_'.$versi}))
            @php $urlFoto = route('absensi.laporan.foto', ['laporan' => $laporan->id, 'versi' => $versi]); @endphp
            <a href="{{ $urlFoto }}" target="_blank" rel="noopener" data-no-transisi class="block text-center" title="Buka foto {{ strtolower($labelVersi) }}">
                <img src="{{ $urlFoto }}" alt="Foto {{ strtolower($labelVersi) }}" loading="lazy"
                     @class(['h-16 w-24 rounded-md border object-cover', 'border-gray-200 opacity-60' => $versi === 'utama' && filled($laporan->foto_koreksi), 'border-gray-200' => ! ($versi === 'utama' && filled($laporan->foto_koreksi))])>
                <span class="text-xs text-gray-500">{{ $labelVersi }}</span>
            </a>
        @endif
    @endforeach
    <div class="min-w-40 flex-1 text-sm">
        <p class="text-gray-800">{{ $laporan->keterangan }}</p>
        <p class="mt-1 text-xs text-gray-400">
            Dilaporkan {{ \Carbon\CarbonImmutable::parse($laporan->waktu_lapor)->format('H:i') }}
            @if ($laporan->waktu_koreksi)
                &middot; dikoreksi {{ \Carbon\CarbonImmutable::parse($laporan->waktu_koreksi)->format('H:i') }}
            @endif
        </p>
    </div>
</div>
