<?php

namespace App\Console\Commands\Penyewaan;

use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Data wilayah Indonesia (provinsi sampai desa) di basis data PUSAT — satu
 * salinan untuk semua toko, dibaca formulir alamat pelanggan dan member.
 *
 * Kenapa perintah sendiri (AS16, docs/permintaan-3okt.md di repo Aishii):
 * `laravolt:indonesia:seed` memanggil `db:seed` TANPA `--database`, dan dalam
 * mode banyak toko bentuk itu ditolak pagar migrasi (ia akan menulis ke
 * koneksi bawaan tanpa ada yang memutuskan ke mana). Akibatnya keempat tabel
 * wilayah kosong dan pilihan provinsi/kota kosong di semua toko. Di sini
 * koneksinya disebut: pusat, tempat model wilayah membaca.
 *
 * Aman dijalankan tiap deploy: begitu tabel desa — yang terakhir diisi —
 * sudah berisi, ia berhenti; dan seeder hulunya `insertOrIgnore`, jadi
 * pengisian yang terputus di tengah cukup diulang.
 */
class IsiWilayahPusat extends Command
{
    protected $signature = 'pusat:wilayah {--ulang : Isi lagi walau sudah terisi (baris yang ada dilewati)}';

    protected $description = 'Isi data wilayah Indonesia (provinsi sampai desa) di basis data pusat Aishii POS';

    public function handle(Penyewaan $penyewaan): int
    {
        if (! $penyewaan->aktif()) {
            $this->error('Mode banyak toko mati (POS_MULTI_TOKO). Pakai `php artisan laravolt:indonesia:seed` biasa.');

            return self::FAILURE;
        }

        $pusat = $penyewaan->koneksiPusat();
        $desa = config('laravolt.indonesia.table_prefix').'villages';

        if (! $this->option('ulang') && DB::connection($pusat)->table($desa)->exists()) {
            $this->info('Data wilayah sudah terisi — dilewati.');

            return self::SUCCESS;
        }

        return $this->call('db:seed', [
            '--class' => 'Laravolt\\Indonesia\\Seeds\\DatabaseSeeder',
            '--database' => $pusat,
            '--force' => true,
        ]);
    }
}
