<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        @page { size: A4 landscape; margin: 14mm 12mm 16mm; }
        body { color: #1f2937; font-family: "DejaVu Sans", sans-serif; font-size: 9px; }
        h1 { color: #123b66; font-size: 19px; margin: 0 0 4px; }
        p { margin: 3px 0; }
        .subtitle { color: #64748b; font-size: 10px; }
        .heading { border-bottom: 2px solid #c49a45; margin-bottom: 12px; padding-bottom: 9px; }
        .filters { border-collapse: collapse; margin: 10px 0 12px; width: 100%; }
        .filters td { padding: 4px 8px 4px 0; vertical-align: top; }
        .label { color: #64748b; font-weight: bold; width: 100px; }
        .summary { border-collapse: collapse; margin: 8px 0 14px; table-layout: fixed; width: 100%; }
        .summary td { border: 1px solid #dbe2ea; padding: 7px 8px; width: 20%; }
        .summary-label { color: #64748b; font-size: 8px; }
        .summary-value { color: #123b66; font-size: 13px; font-weight: bold; margin-top: 3px; }
        .details { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .details thead { display: table-header-group; }
        .details th { background: #123b66; color: #fff; font-size: 8px; padding: 7px 5px; text-align: left; }
        .details td { border-bottom: 1px solid #dbe2ea; padding: 6px 5px; vertical-align: top; word-wrap: break-word; }
        .details tr { page-break-inside: avoid; }
        .muted { color: #64748b; }
        .empty { border: 1px solid #dbe2ea; color: #64748b; padding: 14px; text-align: center; }
    </style>
</head>
<body>
    <header class="heading">
        <h1>Rekap Operasional</h1>
        <p class="subtitle">Jadwal kerja dibandingkan dengan realisasi absensi per karyawan.</p>
    </header>

    <table class="filters">
        <tr>
            <td class="label">Periode</td>
            <td>{{ $rekap['bulan']->locale('id')->translatedFormat('F Y') }}</td>
            <td class="label">Dicetak</td>
            <td>{{ $waktuCetak->locale('id')->translatedFormat('d F Y, H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Klien</td>
            <td colspan="3">{{ $klien ?? 'Semua klien' }}</td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td><div class="summary-label">Karyawan</div><div class="summary-value">{{ $rekap['total']['karyawan'] }}</div></td>
            <td><div class="summary-label">Hari kerja terjadwal</div><div class="summary-value">{{ $rekap['total']['kerja_terjadwal'] }}</div></td>
            <td><div class="summary-label">Hari hadir</div><div class="summary-value">{{ $rekap['total']['hadir'] }}</div><div class="muted">{{ $rekap['total']['terlambat'] }} hari terlambat</div></td>
            <td><div class="summary-label">Tidak hadir</div><div class="summary-value">{{ $rekap['total']['tidak_hadir'] }}</div></td>
            <td><div class="summary-label">Jam lembur</div><div class="summary-value">{{ $rekap['total']['jam_lembur'] }}</div><div class="muted">{{ $rekap['total']['jam_kerja'] }} jam kerja tercatat</div></td>
        </tr>
    </table>

    @if ($rekap['baris']->isEmpty())
        <p class="empty">Tidak ada penempatan pada periode dan filter yang dipilih.</p>
    @else
        <table class="details">
            <thead>
                <tr>
                    <th style="width: 8%">NIP</th>
                    <th style="width: 14%">Nama</th>
                    <th style="width: 14%">Klien</th>
                    <th>Kerja / Libur / Belum diatur</th>
                    <th>Hadir</th>
                    <th>Terlambat</th>
                    <th>Izin / Sakit / Cuti / Alpha</th>
                    <th>Tanpa catatan</th>
                    <th>Jam kerja</th>
                    <th>Lembur</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rekap['baris'] as $baris)
                    <tr>
                        <td>{{ $baris->nip }}</td>
                        <td>{{ $baris->nama }}</td>
                        <td>{{ $baris->klien }}</td>
                        <td>{{ $baris->kerja_terjadwal }} / {{ $baris->libur_terjadwal }} / {{ $baris->belum_diatur }}</td>
                        <td>{{ $baris->hadir }}</td>
                        <td>
                            {{ $baris->terlambat }} hari
                            @if ($rekap['kolom']['menit_terlambat'])
                                <div class="muted">{{ $baris->menit_terlambat }} menit</div>
                            @endif
                        </td>
                        <td>{{ $baris->izin }} / {{ $baris->sakit }} / {{ $baris->cuti }} / {{ $baris->alpha }}</td>
                        <td>{{ count($baris->tanggal_tanpa_absensi) }}</td>
                        <td>{{ $baris->jam_kerja }} jam</td>
                        <td>{{ $baris->jam_lembur }} jam</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
