<?php

namespace App\Console\Commands\Penyewaan;

use App\AkunAishii\KlienAishii;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Dijalankan tiap kali instans Cloud Run menyala, SEBELUM Apache menerima
 * permintaan (`docker/mulai.sh`, dokumen 25 di repo Aishii).
 *
 * Tidak ada terminal di layanan serverless dan pemilik layanan tidak
 * menjalankan perintah apa pun sesudah terbit, jadi tiga pekerjaan VPS
 * pindah ke sini:
 *
 *   1. Migrasi pusat dan SELURUH toko — hanya bila daftar berkas migrasinya
 *      berubah sejak terbit terakhir. Instans menyala tiap pagi; memeriksa
 *      migrasi puluhan toko di tiap nyala menambah detik pada kasir pertama
 *      yang membuka aplikasi.
 *   2. Data wilayah, sekali, bila pusatnya masih kosong.
 *   3. Akun pengelola, bila `POS_PENGELOLA_SUREL` dan `POS_PENGELOLA_SANDI`
 *      diisi — langkah P10 pemilik; sesudah masuk, keduanya dihapus lagi.
 *
 * Dua instans yang menyala bersamaan tidak boleh memigrasi bersamaan: kunci
 * tembolok di pusat yang mengantrekannya. Gagal = keluar bukan nol, dan
 * Cloud Run tetap melayani revisi lama — terbit yang rusak tidak pernah
 * menggantikan yang jalan.
 */
class SiapkanTerbit extends Command
{
    protected $signature = 'pos:siapkan';

    protected $description = 'Siapkan Aishii POS saat instans menyala: migrasi bila ada yang baru, wilayah, akun pengelola dari env';

    private const KUNCI_SIDIK = 'pos.sidik_migrasi';

    public function handle(Penyewaan $penyewaan): int
    {
        if (! $penyewaan->aktif()) {
            $this->info('Mode satu toko: tidak ada yang disiapkan.');

            return self::SUCCESS;
        }

        $pusat = Schema::connection($penyewaan->koneksiPusat());

        // Terbit pertama: tabel tembolok belum ada, jadi belum ada kunci yang
        // bisa dipegang — dan belum ada instans lain yang sempat menyala.
        if (! $pusat->hasTable('cache') || ! $pusat->hasTable('cache_locks')) {
            $hasil = $this->migrasi();
        } else {
            $hasil = Cache::lock('pos-siapkan', 900)->block(900, fn () => $this->migrasiBilaPerlu());
        }

        if ($hasil !== self::SUCCESS) {
            return $hasil;
        }

        // Melewati dirinya sendiri bila desa sudah terisi — satu kueri.
        if ($this->call('pusat:wilayah') !== self::SUCCESS) {
            return self::FAILURE;
        }

        $surel = trim((string) env('POS_PENGELOLA_SUREL', ''));
        if ($surel !== '' && (string) env('POS_PENGELOLA_SANDI', '') !== '') {
            if ($this->call('pengelola:buat', ['email' => $surel, '--sandi-dari-env' => 'POS_PENGELOLA_SANDI']) !== self::SUCCESS) {
                return self::FAILURE;
            }
        } elseif ($surel !== '' && app(KlienAishii::class)->aktif()) {
            // AU5: pengelola masuk lewat akun Aishii — cukup surelnya, tanpa
            // sandi, dan variabelnya boleh menetap (tidak ada rahasia di sana).
            if ($this->call('pengelola:buat', ['email' => $surel, '--akun-aishii' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function migrasiBilaPerlu(): int
    {
        if (Cache::get(self::KUNCI_SIDIK) === $this->sidik()) {
            $this->info('Migrasi tidak berubah sejak terbit terakhir — dilewati.');

            return self::SUCCESS;
        }

        return $this->migrasi();
    }

    private function migrasi(): int
    {
        if ($this->call('pusat:migrasi', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        if ($this->call('toko:migrasi', ['--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        Cache::forever(self::KUNCI_SIDIK, $this->sidik());

        return self::SUCCESS;
    }

    /** Sidik daftar berkas migrasi pusat dan toko — berubah tiap ada migrasi baru. */
    private function sidik(): string
    {
        $berkas = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(database_path('migrations/pusat/*.php')) ?: [],
        );
        $nama = array_map('basename', $berkas);
        sort($nama);

        return md5(implode("\n", $nama));
    }
}
