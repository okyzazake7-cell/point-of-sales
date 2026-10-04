<?php

namespace App\Penyewaan;

use App\Models\Pusat\Toko;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pintu masuk-keluar basis data toko (mode banyak toko, dokumen 24 §3).
 *
 * Seluruh pemisahan antar-toko yang TIDAK dijaga oleh koneksi hidup di
 * sini, satu per satu, supaya tidak ada yang tercecer di middleware,
 * perintah konsol, dan uji:
 *
 *   1. koneksi bawaan → basis data toko;
 *   2. kunci tembolok izin Spatie → per toko, dan koleksi izin di memori
 *      dibuang (tanpa ini peran toko A melayani toko B);
 *   3. parameter `{toko}` bawaan bagi `route()`, supaya tautan publik hulu
 *      (`route('transactions.public', …)`) tetap benar tanpa disunting;
 *   4. konteks log.
 *
 * Sesi, tembolok, antrean, dan wilayah TIDAK ikut berpindah — mereka diikat
 * ke koneksi pusat sekali saat aplikasi menyala (`pasangKoneksi`).
 */
class Penyewaan
{
    private ?Toko $toko = null;

    private ?string $kunciIzinAsal = null;

    public function aktif(): bool
    {
        return (bool) config('penyewaan.aktif');
    }

    public function toko(): ?Toko
    {
        return $this->toko;
    }

    public function koneksiPusat(): string
    {
        return config('penyewaan.koneksi_pusat');
    }

    public function koneksiToko(): string
    {
        return config('penyewaan.koneksi_toko');
    }

    /**
     * Melahirkan koneksi `pusat` dan `toko` dari koneksi DB_CONNECTION, lalu
     * mengikat sesi/tembolok/antrean/wilayah ke pusat. Aman dipanggil
     * berulang (config:cache memanggilnya, lalu aplikasi memanggilnya lagi
     * di atas konfigurasi yang sudah tersimpan).
     */
    public function pasangKoneksi(): void
    {
        $pusat = $this->koneksiPusat();
        $koneksiToko = $this->koneksiToko();
        $bawaan = config('database.default');

        if ($bawaan !== $pusat) {
            $dasar = config("database.connections.{$bawaan}");
            config([
                "database.connections.{$pusat}" => $dasar,
                // Basis data dikosongkan sampai sebuah toko dimasuki: kode yang
                // keliru memakai koneksi toko di luar toko harus PECAH, bukan
                // diam-diam membaca basis data pusat.
                "database.connections.{$koneksiToko}" => array_merge($dasar, ['database' => '']),
                'database.default' => $pusat,
            ]);
        }

        // Eksplisit, bukan "kebetulan" karena sesi dibuka sebelum toko
        // dikenali: urutan middleware boleh berubah, alamat tabel tidak.
        config([
            'session.connection' => $pusat,
            'cache.stores.database.connection' => $pusat,
            'cache.stores.database.lock_connection' => $pusat,
            'queue.connections.database.connection' => $pusat,
            'queue.batching.database' => $pusat,
            'queue.failed.database' => $pusat,
            'indonesia.database.connection' => $pusat,
        ]);
    }

    /** Alamat basis data toko untuk driver koneksi yang dipakai. */
    public function lokasiBasisData(Toko $toko): string
    {
        $driver = config('database.connections.'.$this->koneksiToko().'.driver');

        if ($driver === 'sqlite') {
            return rtrim(config('penyewaan.direktori_sqlite'), '/').'/'.$toko->namaBasisData().'.sqlite';
        }

        return $toko->namaBasisData();
    }

    public function masuk(Toko $toko): void
    {
        $koneksi = $this->koneksiToko();

        if (! $this->toko || ! $this->toko->is($toko)) {
            config(["database.connections.{$koneksi}.database" => $this->lokasiBasisData($toko)]);
            DB::purge($koneksi);
        }
        DB::setDefaultConnection($koneksi);

        $this->kunciIzinAsal ??= (string) config('permission.cache.key');
        config(['permission.cache.key' => $this->kunciIzinAsal.'.toko.'.$toko->getKey()]);
        app(PermissionRegistrar::class)->initializeCache();

        URL::defaults(['toko' => $toko->kode]);
        Context::add('toko_id', $toko->getKey());

        $this->toko = $toko;
    }

    public function keluar(): void
    {
        if (! $this->toko) {
            return;
        }

        DB::setDefaultConnection($this->koneksiPusat());
        DB::purge($this->koneksiToko());

        config(['permission.cache.key' => $this->kunciIzinAsal]);
        app(PermissionRegistrar::class)->initializeCache();

        URL::defaults(['toko' => null]);
        Context::forget('toko_id');

        $this->toko = null;
    }

    /**
     * Menjalankan pekerjaan di dalam satu toko lalu kembali ke keadaan
     * semula — dipakai penjadwal, perintah konsol, dan pendaftaran.
     *
     * @template T
     *
     * @param  callable(Toko): T  $kerja
     * @return T
     */
    public function denganToko(Toko $toko, callable $kerja): mixed
    {
        $sebelumnya = $this->toko;
        $this->masuk($toko);

        try {
            return $kerja($toko);
        } finally {
            $sebelumnya ? $this->masuk($sebelumnya) : $this->keluar();
        }
    }

    /**
     * Atribut grup rute untuk tautan publik toko (struk, portal, QR meja):
     * berawalan `/t/{toko}` dalam mode banyak toko, apa adanya dalam mode
     * satu toko.
     */
    public static function grupPublik(): array
    {
        if (! config('penyewaan.aktif')) {
            return [];
        }

        return ['prefix' => 't/{toko}', 'middleware' => 'toko.jalur'];
    }
}
