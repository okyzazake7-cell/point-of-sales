<?php

namespace Tests\Feature\BanyakToko;

use App\Models\Pusat\Pengelola;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\BanyakTokoTestCase;

/**
 * `pos:siapkan` — yang dijalankan tiap instans Cloud Run menyala (dokumen
 * 25 di repo Aishii). Instans menyala tiap pagi; memigrasi puluhan toko di
 * tiap nyala menambah detik pada kasir pertama, jadi migrasi hanya berjalan
 * bila daftar berkasnya berubah.
 */
class SiapkanTerbitTest extends BanyakTokoTestCase
{
    private const ENV = ['POS_PENGELOLA_SUREL', 'POS_PENGELOLA_SANDI'];

    protected function setUp(): void
    {
        parent::setUp();

        // Satu desa cukup supaya `pusat:wilayah` melewati dirinya: mengisi
        // 83 ribu desa di tiap uji hanya memperlambat suite.
        $pusat = DB::connection('pusat');
        $pusat->table('indonesia_provinces')->insert(['code' => '11', 'name' => 'ACEH']);
        $pusat->table('indonesia_cities')->insert(['code' => '1101', 'province_code' => '11', 'name' => 'SIMEULUE']);
        $pusat->table('indonesia_districts')->insert(['code' => '110101', 'city_code' => '1101', 'name' => 'TEUPAH SELATAN']);
        $pusat->table('indonesia_villages')->insert(['code' => '1101011001', 'district_code' => '110101', 'name' => 'LATIUNG']);
    }

    protected function tearDown(): void
    {
        foreach (self::ENV as $nama) {
            putenv($nama);
            unset($_ENV[$nama], $_SERVER[$nama]);
        }

        parent::tearDown();
    }

    public function test_nyala_pertama_memigrasi_semua_toko_nyala_berikutnya_melewati(): void
    {
        [$toko] = $this->buatToko('Toko Nyala', 'nyala@contoh.id');

        // `Artisan::output()` hanya memegang panggilan Artisan TERAKHIR — dan
        // migrasi per toko memanggil `migrate` di dalamnya — jadi keluarannya
        // dibaca lewat perintah yang diuji sendiri.
        $this->artisan('pos:siapkan')
            ->expectsOutputToContain("✓ {$toko->kode}")
            ->doesntExpectOutputToContain('Migrasi tidak berubah')
            ->assertSuccessful();

        $this->artisan('pos:siapkan')
            ->expectsOutputToContain('Migrasi tidak berubah sejak terbit terakhir')
            ->doesntExpectOutputToContain("✓ {$toko->kode}")
            ->assertSuccessful();
    }

    public function test_akun_pengelola_lahir_dari_env_tanpa_terminal(): void
    {
        $this->pasang('POS_PENGELOLA_SUREL', 'Pengelola@Contoh.id');
        $this->pasang('POS_PENGELOLA_SANDI', 'sandi-pengelola-panjang');

        $this->artisan('pos:siapkan')
            ->expectsOutputToContain('Akun pengelola siap')
            ->doesntExpectOutputToContain('sandi-pengelola-panjang')
            ->assertSuccessful();

        $pengelola = Pengelola::where('email', 'pengelola@contoh.id')->sole();
        $this->assertTrue(Hash::check('sandi-pengelola-panjang', $pengelola->password));
    }

    public function test_sandi_pengelola_pendek_menggagalkan_nyala_tanpa_membuat_akun(): void
    {
        $this->pasang('POS_PENGELOLA_SUREL', 'pengelola@contoh.id');
        $this->pasang('POS_PENGELOLA_SANDI', 'pendek');

        $this->artisan('pos:siapkan')->assertFailed();
        $this->assertSame(0, Pengelola::count());
    }

    private function pasang(string $nama, string $nilai): void
    {
        putenv("{$nama}={$nilai}");
        $_ENV[$nama] = $nilai;
        $_SERVER[$nama] = $nilai;
    }
}
