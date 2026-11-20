{{-- Kop surat PT Satu Jaya Indonesia untuk dokumen PDF tagihan (dipakai keuangan.invoice.pdf). --}}
<table class="kop">
    <tr>
        <td style="width: 62px;"><img src="{{ public_path('images/logo-sji.png') }}" alt="Logo"></td>
        <td>
            <p class="kop-nama">{{ strtoupper($perusahaan['nama']) }}</p>
            <p class="kop-rinci">Penyedia Jasa Tenaga Kerja Outsourcing</p>
            <p class="kop-rinci">
                <span class="{{ filled($perusahaan['alamat_jalan']) ? '' : 'placeholder' }}">{{ $isi($perusahaan['alamat_jalan'], 'Alamat jalan') }}</span>,
                {{ $perusahaan['alamat_wilayah'] }}
            </p>
            <p class="kop-rinci">Telp. {{ $isi($perusahaan['telepon'], 'Telepon') }} &middot; Email {{ $isi($perusahaan['email'], 'Email') }}</p>
        </td>
    </tr>
</table>
<div class="kop-garis"></div>
