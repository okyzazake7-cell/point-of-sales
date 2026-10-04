<?php

namespace App\Console\Commands\Penyewaan;

use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;

/**
 * Migrasi basis data PUSAT (mode banyak toko): tabel di
 * database/migrations/pusat ditambah empat tabel wilayah Indonesia — wilayah
 * cukup satu salinan, dan modelnya menunjuk koneksi pusat.
 */
class MigrasiPusat extends Command
{
    protected $signature = 'pusat:migrasi {--force : Jalankan di produksi tanpa bertanya}';

    protected $description = 'Migrasi basis data pusat Aishii POS (daftar toko, tagihan, sesi, wilayah)';

    public function handle(Penyewaan $penyewaan): int
    {
        if (! $penyewaan->aktif()) {
            $this->error('Mode banyak toko mati (POS_MULTI_TOKO). Pakai `php artisan migrate` biasa.');

            return self::FAILURE;
        }

        $jalur = ['database/migrations/pusat'];
        foreach (glob(database_path('migrations/2016_08_03_*.php')) as $wilayah) {
            $jalur[] = 'database/migrations/'.basename($wilayah);
        }

        return $this->call('migrate', [
            '--database' => $penyewaan->koneksiPusat(),
            '--path' => $jalur,
            '--force' => (bool) $this->option('force'),
        ]);
    }
}
