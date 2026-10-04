<?php

namespace App\Penyewaan;

use App\Models\Pusat\Toko;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

/**
 * Melahirkan, memigrasi, dan (hanya atas perintah pengelola) menghapus basis
 * data satu toko. MySQL di server; SQLite untuk pengembangan dan uji.
 */
class PenyediaBasisData
{
    public function __construct(private readonly Penyewaan $penyewaan) {}

    /** Buat → migrasi → tanam peran & izin; status toko mencatat hasilnya. */
    public function siapkan(Toko $toko): void
    {
        try {
            $this->buat($toko);
            $this->migrasi($toko, tanam: true);
            $toko->forceFill(['status_basis_data' => 'siap'])->save();
        } catch (Throwable $e) {
            $toko->forceFill(['status_basis_data' => 'gagal'])->save();

            throw $e;
        }
    }

    public function buat(Toko $toko): void
    {
        $nama = $this->namaAman($toko);

        if ($this->driver() === 'sqlite') {
            $jalur = $this->penyewaan->lokasiBasisData($toko);
            File::ensureDirectoryExists(dirname($jalur));
            if (! file_exists($jalur)) {
                touch($jalur);
            }

            return;
        }

        DB::connection($this->penyewaan->koneksiPusat())->statement(
            "CREATE DATABASE IF NOT EXISTS `{$nama}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    public function migrasi(Toko $toko, bool $tanam = false): string
    {
        return $this->penyewaan->denganToko($toko, function () use ($tanam) {
            $koneksi = $this->penyewaan->koneksiToko();
            Artisan::call('migrate', ['--database' => $koneksi, '--force' => true]);
            $keluaran = Artisan::output();

            if ($tanam) {
                Artisan::call('db:seed', [
                    '--database' => $koneksi,
                    '--class' => DatabaseSeeder::class,
                    '--force' => true,
                ]);
                $keluaran .= Artisan::output();
            }

            return $keluaran;
        });
    }

    /** Hanya untuk toko yang belum pernah membayar — dipanggil pengelola. */
    public function hapus(Toko $toko): void
    {
        $nama = $this->namaAman($toko);

        if ($this->penyewaan->toko()?->is($toko)) {
            $this->penyewaan->keluar();
        }
        DB::purge($this->penyewaan->koneksiToko());

        if ($this->driver() === 'sqlite') {
            File::delete($this->penyewaan->lokasiBasisData($toko));

            return;
        }

        DB::connection($this->penyewaan->koneksiPusat())->statement("DROP DATABASE IF EXISTS `{$nama}`");
    }

    private function driver(): string
    {
        return (string) config('database.connections.'.$this->penyewaan->koneksiPusat().'.driver');
    }

    private function namaAman(Toko $toko): string
    {
        $nama = $toko->namaBasisData();

        // Nama ini masuk ke DDL tanpa parameter terikat — MySQL tidak
        // menerimanya untuk nama basis data. Satu-satunya pagar: bentuknya.
        if (! preg_match('/^[a-z0-9_]{1,64}$/', $nama)) {
            throw new RuntimeException("Nama basis data toko tidak sah: {$nama}");
        }

        return $nama;
    }
}
