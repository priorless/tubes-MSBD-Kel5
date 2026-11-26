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
        .summary td { border: 1px solid #dbe2ea; padding: 7px 8px; }
        .summary-label { color: #64748b; font-size: 8px; }
        .summary-value { color: #123b66; font-size: 13px; font-weight: bold; margin-top: 3px; }
        .details { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .details thead { display: table-header-group; }
        .details th { background: #123b66; color: #fff; font-size: 8px; padding: 7px 5px; text-align: left; }
        .details td { border-bottom: 1px solid #dbe2ea; padding: 5px; vertical-align: top; word-wrap: break-word; }
        .details tr { page-break-inside: avoid; }
        .alpha { color: #b91c1c; font-weight: bold; }
        .muted { color: #94a3b8; }
        .note { color: #64748b; font-size: 8px; margin-top: 8px; }
    </style>
</head>
<body>
    @php
        $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $jam = static fn (?string $nilai): string => blank($nilai) ? '-' : substr($nilai, 0, 5);
    @endphp

    <header class="heading">
        <h1>Rekap Absensi Bulanan</h1>
        <p class="subtitle">Perbandingan hari wajib kerja (roster Operasional) dengan catatan absensi.</p>
    </header>

    <table class="filters">
        <tr>
            <td class="label">Nama</td>
            <td>{{ $karyawan->nama ?? '-' }}</td>
            <td class="label">Periode</td>
            <td>{{ $labelPeriode }}</td>
        </tr>
        <tr>
            <td class="label">NIP</td>
            <td>{{ $karyawan->nip ?? '-' }}</td>
            <td class="label">Dicetak</td>
            <td>{{ $dicetakPada }}</td>
        </tr>
        <tr>
            <td class="label">Jabatan</td>
            <td>{{ $layanan ?? '-' }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            @foreach ([
                'Hari Wajib Kerja' => $kehadiran['hari_wajib'],
                'Hadir' => $kehadiran['hadir'],
                'Terlambat' => $kehadiran['terlambat'],
                'Izin' => $kehadiran['izin'],
                'Sakit' => $kehadiran['sakit'],
                'Alpha (Tanpa Keterangan)' => $kehadiran['alpha'],
                'Belum Berjalan' => $kehadiran['belum_berjalan'],
            ] as $label => $nilai)
                <td><div class="summary-label">{{ $label }}</div><div class="summary-value">{{ $nilai }}</div></td>
            @endforeach
        </tr>
    </table>

    <table class="details">
        <thead>
            <tr>
                <th style="width: 11%">Tanggal</th>
                <th style="width: 9%">Roster</th>
                <th style="width: 14%">Shift</th>
                <th style="width: 15%">Lokasi</th>
                <th style="width: 7%">Masuk</th>
                <th style="width: 7%">Keluar</th>
                <th style="width: 13%">Kehadiran</th>
                <th style="width: 7%">Telat (menit)</th>
                <th style="width: 7%">Lembur (jam)</th>
                <th style="width: 10%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($kehadiran['harian'] as $baris)
                @php
                    $tanggal = \Carbon\CarbonImmutable::parse($baris['tanggal']);
                    $absensi = $baris['absensi'];
                @endphp
                <tr>
                    <td>{{ $hari[$tanggal->dayOfWeek] }}, {{ $tanggal->format('d/m/Y') }}</td>
                    <td>{{ $baris['status_roster'] }}</td>
                    <td>{{ $baris['shift'] ?? '-' }}</td>
                    <td>{{ $baris['klien'] ?? '-' }}</td>
                    <td>{{ $jam($absensi->jam_masuk ?? null) }}</td>
                    <td>{{ $jam($absensi->jam_keluar ?? null) }}</td>
                    <td @class(['alpha' => str_starts_with($baris['keterangan_hadir'], 'Alpha'), 'muted' => $baris['keterangan_hadir'] === '-'])>{{ $baris['keterangan_hadir'] }}</td>
                    <td>{{ $absensi ? (int) $absensi->menit_terlambat : '-' }}</td>
                    <td>{{ $absensi ? (int) $absensi->jam_lembur : '-' }}</td>
                    <td>{{ $absensi->keterangan ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="note">
        Hari wajib = jadwal Kerja dan backup shift pada roster. Hari wajib yang sudah lewat tanpa absensi dihitung Alpha (tanpa keterangan).
        Toleransi terlambat 30 menit: telat 1–30 menit tetap Hadir (0 menit); mulai 31 menit seluruh menit telat dicatat.
        Lembur dihitung per jam penuh (dibulatkan ke bawah). Waktu dalam WIB.
    </p>
</body>
</html>
