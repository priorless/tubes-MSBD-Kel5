<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backup harian database aplikasi (storage/app/backup, disimpan 7 hari). Butuh scheduler: php artisan schedule:work
// saat pengembangan, atau cron/Task Scheduler yang menjalankan php artisan schedule:run setiap menit.
Schedule::command('sji:backup-db')->dailyAt('01:00')->withoutOverlapping();

// Pengajuan backup shift yang lewat batas tanggapan rekan / keputusan Operasional ditandai Kedaluwarsa.
// Hitungan badge & Beranda juga menyinkronkan status ini setiap kali dibuka.
Schedule::command('pertukaran-shift:kedaluwarsa')->everyFiveMinutes()->withoutOverlapping();
