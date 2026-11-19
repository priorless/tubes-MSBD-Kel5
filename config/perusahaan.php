<?php

/*
 * Identitas PT Satu Jaya Indonesia untuk dokumen tagihan klien (surat permohonan, invoice, lampiran).
 * Diambil dari contoh surat tagihan resmi perusahaan. Nilai null = data belum tersedia dan ditampilkan
 * sebagai placeholder pada dokumen; lengkapi lewat .env tanpa mengubah kode.
 */
return [
    'nama' => 'PT Satu Jaya Indonesia',
    'kota' => env('SJI_KOTA', 'Medan'),
    // Nama jalan & nomor terpotong pada contoh surat sehingga belum tersedia.
    'alamat_jalan' => env('SJI_ALAMAT_JALAN'),
    'alamat_wilayah' => env('SJI_ALAMAT_WILAYAH', 'Kec. Medan Amplas, Kota Medan, Sumatera Utara 20219'),
    'telepon' => env('SJI_TELEPON', '0822 1383 6657'),
    'email' => env('SJI_EMAIL', 'satujayaindonesia@gmail.com'),
    'direktur' => env('SJI_DIREKTUR'),
    'kode_surat' => env('SJI_KODE_SURAT_KEUANGAN', 'SJI-KEU'),
    'rekening' => [
        'bank' => env('SJI_REKENING_BANK', 'PT. Bank Mestika, Tbk'),
        'alamat' => env('SJI_REKENING_ALAMAT', 'Jl. Brigjen Katamso'),
        'nama' => env('SJI_REKENING_NAMA', 'PT. Satu Jaya Indonesia'),
        'nomor' => env('SJI_REKENING_NOMOR', '10112235428'),
    ],
];
