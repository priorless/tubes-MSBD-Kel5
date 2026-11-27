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
        .summary td { border: 1px solid #dbe2ea; padding: 7px 8px; width: 25%; }
        .summary-label { color: #64748b; font-size: 8px; }
        .summary-value { color: #123b66; font-size: 13px; font-weight: bold; margin-top: 3px; }
        h2 { color: #123b66; font-size: 12px; margin: 12px 0 6px; }
        .details { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .details thead { display: table-header-group; }
        .details th { background: #123b66; color: #fff; font-size: 8px; padding: 7px 5px; text-align: left; }
        .details td { border-bottom: 1px solid #dbe2ea; padding: 6px 5px; vertical-align: top; word-wrap: break-word; }
        .details tr { page-break-inside: avoid; }
        .muted { color: #64748b; }
        .center { text-align: center; }
        .empty { border: 1px solid #dbe2ea; color: #64748b; padding: 14px; text-align: center; }
        .note { color: #64748b; font-size: 8px; margin-top: 8px; }
    </style>
</head>
<body>
    <header class="heading">
        <h1>Rekap Absensi Karyawan</h1>
        <p class="subtitle">Laporan absensi berdasarkan data kehadiran dan penempatan.</p>
    </header>

    <table class="filters">
        <tr>
            <td class="label">Periode</td>
            <td>{{ \App\Support\FormatKolom::tanggal($filter['tanggal_awal'], 'd F Y') }} – {{ \App\Support\FormatKolom::tanggal($filter['tanggal_akhir'], 'd F Y') }}</td>
            <td class="label">Dicetak</td>
            <td>{{ $waktuCetak->locale('id')->translatedFormat('d F Y, H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Karyawan</td>
            <td>{{ $karyawan ? $karyawan->nama.' ('.$karyawan->nip.')' : 'Semua karyawan' }}</td>
            <td class="label">Klien</td>
            <td>{{ $klien ?? 'Semua klien' }}</td>
        </tr>
    </table>

    <h2>Ringkasan</h2>
    <table class="summary">
        <tr>
            <td><div class="summary-label">Hadir</div><div class="summary-value">{{ $ringkasanRekap->hadir }}</div></td>
            <td><div class="summary-label">Terlambat</div><div class="summary-value">{{ $ringkasanRekap->terlambat }} kali</div><div class="muted">{{ $ringkasanRekap->total_menit_terlambat }} menit</div></td>
            <td><div class="summary-label">Izin / Sakit / Cuti</div><div class="summary-value">{{ $ringkasanRekap->izin }} / {{ $ringkasanRekap->sakit }} / {{ $ringkasanRekap->cuti }}</div></td>
            <td><div class="summary-label">Alpha tercatat</div><div class="summary-value">{{ $ringkasanRekap->alpha }}</div></td>
        </tr>
        <tr>
            <td><div class="summary-label">Tanpa catatan</div><div class="summary-value">{{ $ringkasanRekap->tanpa_catatan }}</div></td>
            <td><div class="summary-label">Belum check-out</div><div class="summary-value">{{ $ringkasanRekap->belum_check_out }}</div></td>
            <td><div class="summary-label">Lembur</div><div class="summary-value">{{ $ringkasanRekap->lembur }} kali</div></td>
            <td><div class="summary-label">Jam lembur</div><div class="summary-value">{{ $ringkasanRekap->jam_lembur }} jam</div></td>
        </tr>
    </table>

    <h2>Detail Absensi</h2>
    @if ($rekap->isEmpty())
        <div class="empty">Tidak ada catatan absensi yang cocok dengan periode dan filter ini.</div>
    @else
        <table class="details">
            <thead>
                <tr>
                    <th style="width: 8%">Tanggal</th>
                    <th style="width: 10%">NIP</th>
                    <th style="width: 15%">Karyawan</th>
                    <th style="width: 20%">Klien / lokasi</th>
                    <th style="width: 9%">Status</th>
                    <th style="width: 9%">Terlambat</th>
                    <th style="width: 8%">Masuk</th>
                    <th style="width: 8%">Keluar</th>
                    <th style="width: 13%">Lembur</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rekap as $baris)
                    <tr>
                        <td>{{ \App\Support\FormatKolom::tanggal($baris->tgl, 'd/m/Y') }}</td>
                        <td>{{ $baris->nip }}</td>
                        <td>{{ $baris->nama }}</td>
                        <td>{{ $baris->nama_klien ?? '—' }}@if ($baris->lokasi_kerja)<br><span class="muted">{{ $baris->lokasi_kerja }}</span>@endif</td>
                        <td>{{ $baris->status_kehadiran }}</td>
                        <td class="center">{{ (int) $baris->menit_terlambat > 0 ? $baris->menit_terlambat.' mnt' : '—' }}</td>
                        <td class="center">{{ \App\Support\FormatKolom::jam($baris->jam_masuk) }}</td>
                        <td class="center">{{ \App\Support\FormatKolom::jam($baris->jam_keluar) }}</td>
                        <td>{{ (int) $baris->jam_lembur > 0 ? $baris->jam_lembur.' jam' : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="note">Hari tanpa catatan bukan Alpha. Angka tersebut dihitung dari tanggal kalender dalam rentang penempatan karena kalender hari kerja mingguan tidak tersedia pada data penempatan.</p>
</body>
</html>
