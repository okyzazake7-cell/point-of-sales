<?php

namespace Tests;

use App\Models\Pusat\Toko;
use App\Models\User;
use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\PenyediaBasisData;
use App\Penyewaan\Penyewaan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * Dasar uji mode banyak toko (dokumen 24 di repo Aishii).
 *
 * Mode itu dibaca dari env SAAT APLIKASI MENYALA (bentuk rute publik
 * `/t/{toko}` ditentukan saat rute dimuat), jadi env-nya dipasang sebelum
 * aplikasi dibuat dan dicabut sesudahnya — uji mode satu toko yang berjalan
 * sesudahnya tidak ikut berubah.
 *
 * Basis data pusat SQLite di memori; tiap toko berkas SQLite sendiri.
 * Toko pertama dalam satu proses disiapkan lewat jalan SUNGGUHAN (migrasi +
 * penanaman, ±1,5 detik); berkasnya lalu menjadi templat bagi toko
 * berikutnya, supaya puluhan uji tidak menghabiskan menit.
 */
abstract class BanyakTokoTestCase extends TestCase
{
    private const ENV = ['POS_MULTI_TOKO', 'POS_DIREKTORI_SQLITE'];

    protected static ?string $templat = null;

    protected string $direktoriToko;

    public function createApplication()
    {
        $this->direktoriToko = dirname(__DIR__).'/storage/framework/testing/banyak-toko-'.getmypid();
        $this->pasangEnv('POS_MULTI_TOKO', 'true');
        $this->pasangEnv('POS_DIREKTORI_SQLITE', $this->direktoriToko);

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::deleteDirectory($this->direktoriToko);
        File::ensureDirectoryExists($this->direktoriToko);
        Artisan::call('pusat:migrasi', ['--force' => true]);

        $this->app->instance(PenyediaBasisData::class, new class(app(Penyewaan::class)) extends PenyediaBasisData
        {
            public function siapkan(Toko $toko): void
            {
                $templat = BanyakTokoTestCase::templat();
                $tujuan = app(Penyewaan::class)->lokasiBasisData($toko);

                if (! is_file($templat)) {
                    parent::siapkan($toko);
                    File::copy($tujuan, $templat);

                    return;
                }

                File::copy($templat, $tujuan);
                $toko->forceFill(['status_basis_data' => 'siap'])->save();
            }
        });
    }

    protected function tearDown(): void
    {
        app(Penyewaan::class)->keluar();
        parent::tearDown();

        File::deleteDirectory($this->direktoriToko);
        foreach (self::ENV as $nama) {
            putenv($nama);
            unset($_ENV[$nama], $_SERVER[$nama]);
        }
    }

    /**
     * Tiap permintaan uji berangkat seperti permintaan sungguhan di PHP-FPM:
     * tanpa toko yang tertinggal dan tanpa pengguna yang sudah dipegang
     * penjaga. Tanpa ini, aplikasi yang hidup sepanjang satu uji membawa
     * pengguna permintaan sebelumnya ke permintaan berikutnya — dan uji
     * pemisahan lulus atau gagal karena sisa itu, bukan karena kodenya.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app(Penyewaan::class)->keluar();
        $this->app['auth']->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public static function templat(): string
    {
        if (! static::$templat) {
            static::$templat = dirname(__DIR__).'/storage/framework/testing/templat-toko-'.getmypid().'.sqlite';
            $jalur = static::$templat;
            register_shutdown_function(fn () => @unlink($jalur));
        }

        return static::$templat;
    }

    /**
     * Toko baru lewat jalan /daftar yang sama (PendaftaranToko).
     *
     * @return array{0: Toko, 1: User}
     */
    protected function buatToko(string $nama, string $email, string $sandi = 'sandi-rahasia-1'): array
    {
        return app(PendaftaranToko::class)->daftarkan([
            'nama_toko' => $nama,
            'jenis_usaha' => 'retail',
            'nama' => 'Pemilik '.$nama,
            'email' => $email,
            'password' => $sandi,
        ]);
    }

    /** Masuk lewat formulir sungguhan — termasuk penjaga botnya. */
    protected function masukSebagai(string $email, string $sandi = 'sandi-rahasia-1')
    {
        return $this->post('/login', [
            'email' => $email,
            'password' => $sandi,
            ...$this->botGuardPayload(),
        ]);
    }

    /**
     * Pendaftaran bertahap (AU6): panggil /daftar/lanjut seperti halaman
     * kemajuan, sampai selesai atau gagal. Batasnya penjaga uji yang macet.
     */
    protected function selesaikanPendaftaran(int $batas = 500): TestResponse
    {
        for ($i = 0; $i < $batas; $i++) {
            $jawaban = $this->postJson('/daftar/lanjut')->assertOk();
            if ($jawaban->json('selesai') || $jawaban->json('gagal')) {
                return $jawaban;
            }
        }

        $this->fail("Pendaftaran belum selesai sesudah {$batas} panggilan.");
    }

    protected function diToko(Toko $toko, callable $kerja): mixed
    {
        return app(Penyewaan::class)->denganToko($toko, $kerja);
    }

    private function pasangEnv(string $nama, string $nilai): void
    {
        putenv("{$nama}={$nilai}");
        $_ENV[$nama] = $nilai;
        $_SERVER[$nama] = $nilai;
    }
}
