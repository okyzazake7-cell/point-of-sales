<?php

namespace App\Console\Commands\Penyewaan;

use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Menjalankan satu perintah artisan di tiap toko — bentuk penjadwal dalam
 * mode banyak toko (`toko:jalankan crm:sync-segments`). Perintah hulu tidak
 * tahu-menahu soal toko; ia hanya mendapati basis data bawaannya sudah
 * menunjuk toko yang benar.
 */
class JalankanDiToko extends Command
{
    protected $signature = 'toko:jalankan
        {perintah : Nama perintah artisan, mis. crm:sync-segments}
        {--toko=* : Nomor atau kode toko tertentu (bawaan: semua yang siap)}';

    protected $description = 'Jalankan satu perintah artisan di setiap toko Aishii POS';

    public function handle(Penyewaan $penyewaan): int
    {
        if (! $penyewaan->aktif()) {
            return $this->call($this->argument('perintah'));
        }

        $gagal = 0;
        foreach (PilihToko::dari($this->option('toko')) as $toko) {
            try {
                $kode = $penyewaan->denganToko($toko, fn () => Artisan::call($this->argument('perintah')));
                if ($kode !== self::SUCCESS) {
                    $gagal++;
                    $this->error("✗ {$toko->kode}: kode keluar {$kode}");
                }
            } catch (Throwable $e) {
                $gagal++;
                report($e);
                $this->error("✗ {$toko->kode}: {$e->getMessage()}");
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
