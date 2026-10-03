<?php

namespace App\Console\Commands\Penyewaan;

use App\Langganan\Langganan;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;

/**
 * Tiap menit (routes/console.php): menanyakan buku tagihan Aishii apakah
 * tagihan yang menunggu sudah dibayar, menerapkan yang lunas, dan
 * mengedaluwarsakan yang lewat waktunya. POS menjemput; Aishii tidak perlu
 * tahu alamat server ini (dokumen 24 §7 butir 5).
 */
class PeriksaPembayaran extends Command
{
    protected $signature = 'langganan:periksa';

    protected $description = 'Jemput status pembayaran tagihan Aishii POS dari buku tagihan Aishii';

    public function handle(Penyewaan $penyewaan, Langganan $langganan): int
    {
        if (! $penyewaan->aktif()) {
            return self::SUCCESS;
        }

        $lunas = $langganan->periksaPembayaran();
        if ($lunas > 0) {
            $this->info("{$lunas} tagihan lunas diterapkan.");
        }

        return self::SUCCESS;
    }
}
