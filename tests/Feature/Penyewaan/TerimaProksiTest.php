<?php

namespace Tests\Feature\Penyewaan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Worker penerus pos.aishiierp.com → Cloud Run (dokumen 25 di repo Aishii).
 * Dua arah: rahasia yang benar memulihkan alamat pengunjung dan host; yang
 * datang langsung ke run.app tanpa rahasia tidak dilayani sama sekali.
 */
class TerimaProksiTest extends TestCase
{
    private const RAHASIA = 'rahasia-proksi-uji-yang-panjang';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://pos.contoh.id']);

        Route::get('/_uji/proksi', fn (Request $permintaan) => response()->json([
            'ip' => $permintaan->ip(),
            'tautan' => url('/dashboard'),
            'aman' => $permintaan->secure(),
            'rahasia_tersisa' => $permintaan->headers->has('X-Aishii-Proksi'),
        ]));
    }

    public function test_tanpa_rahasia_terkonfigurasi_middleware_diam(): void
    {
        config(['penyewaan.rahasia_proksi' => '']);

        $this->getJson('/_uji/proksi')->assertOk();
    }

    public function test_datang_langsung_ke_run_app_tanpa_rahasia_ditolak(): void
    {
        config(['penyewaan.rahasia_proksi' => self::RAHASIA]);

        $this->getJson('/_uji/proksi')->assertNotFound();
        $this->getJson('/_uji/proksi', ['X-Aishii-Proksi' => 'tebakan'])->assertNotFound();
    }

    public function test_lewat_worker_alamat_pengunjung_dan_host_dipulihkan(): void
    {
        config(['penyewaan.rahasia_proksi' => self::RAHASIA]);

        $this->getJson('/_uji/proksi', [
            'X-Aishii-Proksi' => self::RAHASIA,
            'X-Aishii-Klien' => '203.0.113.7',
            // Yang ditambahkan Google di depan Cloud Run: alamat Worker,
            // bukan pengunjung. Bila suatu hari semua proksi dipercaya
            // (trustProxies '*'), kepala ini yang terbaca — dan pembatas laju
            // menghitung seluruh pengguna sebagai satu alamat Cloudflare.
            'X-Forwarded-For' => '198.51.100.9',
            'X-Forwarded-Proto' => 'http',
        ])->assertOk()->assertExactJson([
            'ip' => '203.0.113.7',
            'tautan' => 'https://pos.contoh.id/dashboard',
            'aman' => true,
            'rahasia_tersisa' => false,
        ]);
    }

    public function test_kepala_pengunjung_yang_bukan_ip_diabaikan(): void
    {
        config(['penyewaan.rahasia_proksi' => self::RAHASIA]);

        $this->getJson('/_uji/proksi', [
            'X-Aishii-Proksi' => self::RAHASIA,
            'X-Aishii-Klien' => 'bukan-alamat',
        ])->assertOk()->assertJsonPath('ip', '127.0.0.1');
    }

    public function test_pemeriksa_kesehatan_cloud_run_tetap_terjawab_tanpa_rahasia(): void
    {
        config(['penyewaan.rahasia_proksi' => self::RAHASIA]);

        $this->get('/up')->assertOk();
    }
}
