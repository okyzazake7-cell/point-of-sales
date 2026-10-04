<?php

namespace Tests\Feature\BanyakToko;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\BanyakTokoTestCase;

/**
 * Data wilayah di mode banyak toko (AS16, docs/permintaan-3okt.md di repo
 * Aishii). `laravolt:indonesia:seed` ditolak pagar migrasi mode ini — di CLI
 * sungguhan ia berhenti dengan "Mode banyak toko: tanpa --database …"
 * (terukur 4 Okt; pagar `CommandStarting` tidak menyala di PHPUnit, jadi
 * penolakannya tidak diulang di sini). Tanpa jalan lain, formulir alamat
 * pelanggan dan member di SEMUA toko tidak punya satu provinsi pun.
 */
class WilayahPusatTest extends BanyakTokoTestCase
{
    public function test_store_address_form_lists_provinces_once_the_central_copy_is_filled(): void
    {
        $this->buatToko('Toko Wilayah', 'wilayah@contoh.id');
        $this->masukSebagai('wilayah@contoh.id')->assertSessionHasNoErrors();

        // Arah pertama: gejalanya. Tanpa arah ini, uji yang lulus karena
        // wilayahnya kebetulan sudah terisi dari tempat lain tidak
        // membuktikan apa pun tentang perintahnya.
        $this->get('/dashboard/customers/create')->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman
                ->component('Dashboard/Customers/Create')
                ->has('provinces', 0));

        $this->assertSame(0, Artisan::call('pusat:wilayah'));

        $this->get('/dashboard/customers/create')->assertOk()
            ->assertInertia(fn (Assert $halaman) => $halaman->has('provinces', 38));

        // Satu salinan, di PUSAT — sampai tingkat desa, yang terakhir diisi.
        $this->assertSame(83762, DB::connection('pusat')->table('indonesia_villages')->count());
    }

    public function test_running_it_on_every_deploy_is_a_no_op_once_filled(): void
    {
        Artisan::call('pusat:wilayah');
        $sebelum = DB::connection('pusat')->table('indonesia_villages')->count();

        $this->assertSame(0, Artisan::call('pusat:wilayah'));
        $this->assertStringContainsString('sudah terisi', Artisan::output());
        $this->assertSame($sebelum, DB::connection('pusat')->table('indonesia_villages')->count());
    }
}
