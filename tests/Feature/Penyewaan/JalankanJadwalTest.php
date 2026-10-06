<?php

namespace Tests\Feature\Penyewaan;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Cloud Scheduler → POST /_jadwal → schedule:run (dokumen 25 di repo
 * Aishii). Tanpa rahasia yang benar rutenya tidak mengaku ada.
 */
class JalankanJadwalTest extends TestCase
{
    private const RAHASIA = 'rahasia-jadwal-uji-yang-panjang';

    public function test_rahasia_benar_menjalankan_penjadwal(): void
    {
        config(['penyewaan.rahasia_jadwal' => self::RAHASIA]);
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);

        $this->post('/_jadwal', [], ['X-Aishii-Jadwal' => self::RAHASIA])->assertNoContent();
    }

    public function test_rahasia_salah_atau_tidak_terkonfigurasi_menjawab_404(): void
    {
        Artisan::shouldReceive('call')->never();

        config(['penyewaan.rahasia_jadwal' => self::RAHASIA]);
        $this->post('/_jadwal', [], ['X-Aishii-Jadwal' => 'tebakan'])->assertNotFound();
        $this->post('/_jadwal')->assertNotFound();

        config(['penyewaan.rahasia_jadwal' => '']);
        $this->post('/_jadwal', [], ['X-Aishii-Jadwal' => ''])->assertNotFound();
    }

    public function test_rute_mesin_tanpa_sesi_dan_tanpa_csrf(): void
    {
        config(['penyewaan.rahasia_jadwal' => self::RAHASIA]);
        Artisan::shouldReceive('call')->once()->andReturn(0);

        // Tanpa token CSRF dan tanpa kuki apa pun — seperti Cloud Scheduler.
        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post('/_jadwal', [], ['X-Aishii-Jadwal' => self::RAHASIA])
            ->assertNoContent()
            ->assertCookieMissing(config('session.cookie'));
    }
}
