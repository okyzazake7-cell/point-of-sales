<?php

namespace App\Console\Commands\Penyewaan;

use App\Models\Pusat\Pengelola;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Akun pengelola layanan Aishii POS (pintu /pengelola). Dibuat dari server,
 * bukan dari halaman mana pun: tidak ada formulir publik yang bisa
 * melahirkan pengelola.
 *
 * `--sandi-dari-env=NAMA` untuk Cloud Run (dokumen 25 di repo Aishii):
 * layanan serverless tidak punya terminal untuk menjawab pertanyaan sandi,
 * jadi sandinya dibaca dari variabel lingkungan bernama itu — tidak pernah
 * dari argumen, yang tercatat di riwayat dan log perintah.
 */
class BuatPengelola extends Command
{
    protected $signature = 'pengelola:buat {email} {--nama=Pengelola Aishii POS} {--sandi-dari-env= : Nama variabel lingkungan yang memuat sandinya}';

    protected $description = 'Buat atau ganti sandi akun pengelola Aishii POS';

    public function handle(Penyewaan $penyewaan): int
    {
        if (! $penyewaan->aktif()) {
            $this->error('Mode banyak toko mati (POS_MULTI_TOKO).');

            return self::FAILURE;
        }

        $namaEnv = (string) $this->option('sandi-dari-env');
        $sandi = $namaEnv !== ''
            ? (string) env($namaEnv, '')
            : (string) $this->secret('Kata sandi (minimal 12 huruf)');
        if (mb_strlen($sandi) < 12) {
            $this->error('Kata sandi minimal 12 huruf.');

            return self::FAILURE;
        }

        Pengelola::query()->updateOrCreate(
            ['email' => mb_strtolower(trim($this->argument('email')))],
            ['nama' => $this->option('nama'), 'password' => Hash::make($sandi)],
        );

        $this->info('Akun pengelola siap. Masuk di /pengelola/masuk.');

        return self::SUCCESS;
    }
}
