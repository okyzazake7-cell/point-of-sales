<?php

namespace App\Http\Controllers\Penyewaan;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Pemicu penjadwal untuk Cloud Run (dokumen 25 di repo Aishii).
 *
 * Tidak ada cron di layanan serverless: Cloud Scheduler memanggil rute ini
 * tiap menit, dan rute ini menjalankan `schedule:run` — persis yang dulu
 * dijalankan cron di VPS. Tiap menit, bukan tiap lima, sebab pembayaran
 * QRIS dijemput `langganan:periksa` tiap menit, dan panggilan yang rutin
 * membuat instansnya jarang tidur.
 *
 * Rahasia salah atau kosong menjawab 404, bukan 403: rute yang tidak
 * berpagar benar tidak perlu mengaku ada.
 */
class JalankanJadwal
{
    public function __invoke(Request $request): Response
    {
        $rahasia = (string) config('penyewaan.rahasia_jadwal');

        if ($rahasia === '' || ! hash_equals($rahasia, (string) $request->headers->get('X-Aishii-Jadwal'))) {
            abort(404);
        }

        Artisan::call('schedule:run');

        return response()->noContent();
    }
}
