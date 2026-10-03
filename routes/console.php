<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Mode banyak toko: perintah hulu tidak tahu-menahu soal toko, jadi tiap
// perintah dijalankan sekali PER TOKO lewat `toko:jalankan` (dokumen 24 §3).
$perToko = fn (string $perintah) => config('penyewaan.aktif') ? "toko:jalankan {$perintah}" : $perintah;

Schedule::command($perToko('crm:sync-segments'))->dailyAt('01:00');
Schedule::command($perToko('crm:generate-reminders'))->dailyAt('01:15');
Schedule::command($perToko('reorder:generate'))->dailyAt('02:00');
Schedule::command($perToko('transactions:expire'))->hourly();
