<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sesi Tidak Dikenali · SJI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="{{ asset('css/tenaga-kerja.css') }}?v=1" rel="stylesheet">
</head>
<body class="tk-body tk-body-tengah">
    <section class="tk-card tk-gerbang tk-reveal tampil">
        <div class="tk-brand-logo tk-brand-logo-besar mx-auto mb-3">SJI</div>
        <div class="tk-kosong-ilustrasi"><i class="bi bi-shield-lock"></i></div>
        <h1 class="tk-panel-judul">Sesi Anda tidak dikenali</h1>
        <p class="tk-panel-sub">Halaman Portal Karyawan hanya dapat dibuka oleh karyawan yang sudah masuk (login).</p>
        @if (app()->environment('local'))
            <div class="tk-catatan text-start mt-3">
                <i class="bi bi-wrench"></i>
                <span><b>Untuk pengembang:</b> fitur login belum tersedia. Tambahkan
                <code>TK_DEV_ID_KARYAWAN=&lt;id_karyawan&gt;</code> di file <code>.env</code>, lalu jalankan
                <code>php artisan config:clear</code>.</span>
            </div>
        @endif
    </section>
</body>
</html>
