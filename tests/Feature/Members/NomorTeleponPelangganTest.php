<?php

namespace Tests\Feature\Members;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Nomor telepon pelanggan disimpan PERSIS seperti diketik (AT3, 5 Okt).
 *
 * Kolom hulu `customers.no_telp` berjenis bigint, padahal formulirnya
 * menerima teks: nomor berawalan 0 kehilangan nol depannya (SQLite maupun
 * MySQL), dan nomor bertanda hubung ditolak MySQL strict — kasir yang
 * menambah pelanggan dari layar transaksi cuma melihat "gagal". Hulu tidak
 * pernah melihatnya karena CI-nya hanya SQLite dan datanya angka polos.
 */
class NomorTeleponPelangganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'customers-create', 'guard_name' => 'web']);
    }

    public function test_nomor_berawalan_nol_tersimpan_utuh(): void
    {
        $this->tambah('081234567890')->assertOk()->assertJsonPath('success', true);

        $this->assertSame('081234567890', Customer::query()->sole()->no_telp);
    }

    public function test_nomor_bertanda_hubung_dan_plus_tersimpan_utuh(): void
    {
        $this->tambah('0812-3456-7890')->assertOk()->assertJsonPath('success', true);
        $this->tambah('+62 812 3456 7891')->assertOk()->assertJsonPath('success', true);

        $this->assertSame(
            ['0812-3456-7890', '+62 812 3456 7891'],
            Customer::query()->orderBy('id')->pluck('no_telp')->all(),
        );
    }

    public function test_nomor_terlalu_panjang_ditolak_validasi_bukan_galat_server(): void
    {
        $this->tambah(str_repeat('1', 31))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('no_telp');

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_kode_wilayah_terlalu_panjang_ditolak_validasi_bukan_galat_server(): void
    {
        // Kode wilayah laravolt paling panjang 10 huruf (desa); kolomnya
        // varchar(10). Yang lebih panjang dulu lolos validasi lalu pecah di
        // MySQL strict.
        $this->tambah('081200000001', ['village_id' => '11.01.01.1001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('village_id');

        $this->assertSame(0, Customer::query()->count());
    }

    private function tambah(string $nomor, array $lain = [])
    {
        $user = User::factory()->create();
        $user->givePermissionTo('customers-create');

        return $this->actingAs($user)->postJson(route('customers.storeAjax'), [
            'name' => 'Pelanggan '.$nomor,
            'no_telp' => $nomor,
            'address' => 'Jl. Uji',
            ...$lain,
        ]);
    }
}
