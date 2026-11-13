@extends('layouts.tenaga-kerja')

@section('title', 'Slip Gaji')

@section('content')
@if ($periode->isEmpty())
    <section class="tk-card tk-reveal">
        <div class="tk-kosong">
            <div class="tk-kosong-ilustrasi"><i class="bi bi-receipt"></i></div>
            <p class="tk-panel-judul">Belum ada slip gaji</p>
            <p class="tk-panel-sub">Slip gaji akan tersedia setelah bagian keuangan memproses penggajian periode Anda.</p>
        </div>
    </section>
@else
<div class="row g-4">
    {{-- ===================== DAFTAR PERIODE ===================== --}}
    <div class="col-12 col-lg-3 tk-reveal tk-no-print">
        <section class="tk-card tk-periode">
            <h2 class="tk-card-title"><i class="bi bi-clock-history"></i> Periode</h2>
            <select class="form-select tk-input d-lg-none mb-2" id="tkPilihPeriode" aria-label="Pilih periode">
                @foreach ($periode as $p)
                    <option value="{{ route('tenaga-kerja.slip-gaji', ['periode' => $p['kunci']]) }}" @selected($p['kunci'] === $kunciDipilih)>{{ $p['label'] }}</option>
                @endforeach
            </select>
            <div class="tk-periode-list d-none d-lg-flex">
                @foreach ($periode as $p)
                    <a href="{{ route('tenaga-kerja.slip-gaji', ['periode' => $p['kunci']]) }}"
                       class="tk-periode-item {{ $p['kunci'] === $kunciDipilih ? 'aktif' : '' }}">
                        <i class="bi bi-file-earmark-text"></i>
                        <span>{{ $p['label'] }}</span>
                        @if ($loop->first)<span class="tk-badge tk-badge-sukses ms-auto">Terbaru</span>@endif
                    </a>
                @endforeach
            </div>
        </section>
    </div>

    {{-- ===================== DOKUMEN SLIP ===================== --}}
    <div class="col-12 col-lg-9 tk-reveal">
        @if (!$slip)
            <section class="tk-card">
                <div class="tk-kosong">
                    <div class="tk-kosong-ilustrasi"><i class="bi bi-question-circle"></i></div>
                    <p class="tk-panel-judul">Periode tidak ditemukan</p>
                    <p class="tk-panel-sub">Silakan pilih periode lain di daftar.</p>
                </div>
            </section>
        @else
        <article class="tk-slip" id="tkSlip">
            <header class="tk-slip-head">
                <div class="d-flex align-items-center gap-3">
                    <div class="tk-brand-logo tk-brand-logo-besar">SJI</div>
                    <div>
                        <div class="tk-slip-perusahaan">PT SJI</div>
                        <div class="tk-slip-sub">Penyedia Jasa Tenaga Kerja</div>
                    </div>
                </div>
                <div class="text-md-end">
                    <div class="tk-slip-judul">SLIP GAJI</div>
                    <div class="tk-slip-periode"><i class="bi bi-calendar-month"></i> Periode {{ $labelPeriode }}</div>
                </div>
            </header>

            <div class="tk-slip-identitas">
                <div><span>Nama Karyawan</span><strong>{{ $namaKaryawan }}</strong></div>
                @if ($jabatan)<div><span>Jabatan</span><strong>{{ $jabatan }}</strong></div>@endif
                @if ($kategori)<div><span>Kategori</span><strong>{{ $kategori }}</strong></div>@endif
                @foreach ($rincian['info'] as $info)
                    <div><span>{{ $info['label'] }}</span><strong>{{ $info['nilai'] }}</strong></div>
                @endforeach
            </div>

            <div class="row g-4 tk-slip-rincian">
                <div class="col-md-6">
                    <h3 class="tk-slip-kolom-judul tk-plus"><i class="bi bi-plus-circle"></i> Penghasilan</h3>
                    @forelse ($rincian['penghasilan'] as $item)
                        <div class="tk-slip-baris"><span>{{ $item['label'] }}</span><span>{{ $item['nilai'] }}</span></div>
                    @empty
                        <div class="tk-muted small">Tidak ada rincian penghasilan.</div>
                    @endforelse
                </div>
                <div class="col-md-6">
                    <h3 class="tk-slip-kolom-judul tk-minus"><i class="bi bi-dash-circle"></i> Potongan</h3>
                    @forelse ($rincian['potongan'] as $item)
                        <div class="tk-slip-baris"><span>{{ $item['label'] }}</span><span class="text-danger-emphasis">{{ $item['nilai'] }}</span></div>
                    @empty
                        <div class="tk-muted small">Tidak ada potongan.</div>
                    @endforelse
                </div>
            </div>

            @if ($rincian['total'])
                <div class="tk-slip-total">
                    <div>
                        <div class="tk-slip-total-label">{{ $rincian['total']['label'] }}</div>
                        <div class="tk-slip-total-sub">Dihitung otomatis oleh sistem penggajian</div>
                    </div>
                    <div class="tk-slip-total-nilai" data-rupiah="{{ $rincian['total']['mentah'] }}">{{ $rincian['total']['nilai'] }}</div>
                </div>
            @endif

            <footer class="tk-slip-foot">
                <div><i class="bi bi-shield-check"></i> Dokumen ini dibuat secara elektronik dan sah tanpa tanda tangan.</div>
                <div>Dicetak: {{ now()->locale('id')->translatedFormat('d F Y, H:i') }}</div>
            </footer>
        </article>

        <div class="d-flex gap-2 justify-content-end mt-3 tk-no-print">
            <button type="button" class="tk-btn tk-btn-utama" onclick="window.print()">
                <i class="bi bi-printer"></i> Cetak / Simpan PDF
            </button>
        </div>
        @endif
    </div>
</div>
@endif
@endsection
