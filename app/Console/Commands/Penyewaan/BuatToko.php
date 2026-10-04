<?php

namespace App\Console\Commands\Penyewaan;

use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Membuat toko dari baris perintah — jalan yang sama dengan /daftar
 * (PendaftaranToko). Untuk pengelola dan untuk menyiapkan data demo/audit.
 */
class BuatToko extends Command
{
    protected $signature = 'toko:buat
        {nama : Nama toko}
        {email : Surel pemilik}
        {--pemilik= : Nama pemilik (bawaan: bagian depan surel)}
        {--sandi= : Kata sandi pemilik (bawaan: dibuat acak dan dicetak sekali)}
        {--jenis=retail : Jenis usaha: food, retail, grocery, fashion, pharmacy, services}';

    protected $description = 'Buat toko Aishii POS baru beserta basis datanya';

    public function handle(Penyewaan $penyewaan, PendaftaranToko $pendaftaran): int
    {
        if (! $penyewaan->aktif()) {
            $this->error('Mode banyak toko mati (POS_MULTI_TOKO).');

            return self::FAILURE;
        }

        $sandi = $this->option('sandi') ?: Str::password(16, symbols: false);
        [$toko] = $pendaftaran->daftarkan([
            'nama_toko' => $this->argument('nama'),
            'jenis_usaha' => $this->option('jenis'),
            'nama' => $this->option('pemilik') ?: Str::before($this->argument('email'), '@'),
            'email' => $this->argument('email'),
            'password' => $sandi,
        ]);

        $this->info("Toko {$toko->nama} siap: kode {$toko->kode}, basis data {$toko->namaBasisData()}.");
        if (! $this->option('sandi')) {
            $this->warn("Kata sandi pemilik (hanya dicetak sekali): {$sandi}");
        }
        $this->line('Toko lahir TERKUNCI sampai tagihan pertamanya lunas (tanpa masa coba).');

        return self::SUCCESS;
    }
}
