<?php

namespace App\Console\Commands\Penyewaan;

use App\Models\Pusat\Toko;
use App\Penyewaan\PenyediaBasisData;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Throwable;

/**
 * Menjalankan migrasi aplikasi di basis data SETIAP toko — dipanggil deploy
 * sesudah `pusat:migrasi`. Satu toko yang gagal tidak menghentikan toko
 * lain, tetapi kode keluarnya gagal supaya deploy tidak terbaca hijau.
 */
class MigrasiToko extends Command
{
    protected $signature = 'toko:migrasi
        {--toko=* : Nomor atau kode toko tertentu (bawaan: semua yang siap)}
        {--force : Jalankan di produksi tanpa bertanya}';

    protected $description = 'Migrasi basis data seluruh toko Aishii POS';

    public function handle(Penyewaan $penyewaan, PenyediaBasisData $penyedia): int
    {
        if (! $penyewaan->aktif()) {
            $this->error('Mode banyak toko mati (POS_MULTI_TOKO).');

            return self::FAILURE;
        }
        if (! $this->option('force') && app()->isProduction() && ! $this->confirm('Migrasi seluruh toko di produksi?')) {
            return self::FAILURE;
        }

        $gagal = 0;
        foreach (PilihToko::dari($this->option('toko')) as $toko) {
            try {
                $penyedia->migrasi($toko);
                $this->line("✓ {$toko->kode} ({$toko->namaBasisData()})");
            } catch (Throwable $e) {
                $gagal++;
                $this->error("✗ {$toko->kode}: {$e->getMessage()}");
            }
        }

        return $gagal === 0 ? self::SUCCESS : self::FAILURE;
    }
}
