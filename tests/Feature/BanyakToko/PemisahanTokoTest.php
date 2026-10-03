<?php

namespace Tests\Feature\BanyakToko;

use App\Http\Middleware\Penyewaan\BatasiLajuPerToko;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Setting;
use App\Models\User;
use App\Penyewaan\Penyewaan;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\BanyakTokoTestCase;

/**
 * Pemisahan antar-toko dalam mode banyak toko (AS6, dokumen 24 §3).
 *
 * Tiap uji di sini menjaga SATU keadaan bersama yang tidak dipagari koneksi
 * basis data. Kedua toko sengaja punya pengguna bernomor sama (1): justru
 * nomor yang berulang itulah yang membuat tiap kebocoran tampak seperti
 * data yang sah.
 */
class PemisahanTokoTest extends BanyakTokoTestCase
{
    public function test_each_store_is_born_with_its_own_database_directory_and_no_trial(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');

        $this->assertNotSame($a->namaBasisData(), $b->namaBasisData());
        $this->assertSame($a->id, DirektoriPengguna::query()->find('a@contoh.id')->toko_id);
        $this->assertSame($b->id, DirektoriPengguna::query()->find('b@contoh.id')->toko_id);
        // Tanpa masa coba: belum pernah membayar = belum pernah aktif.
        $this->assertNull($a->aktif_sampai);

        $this->diToko($a, fn () => Setting::set('penanda_uji', 'milik A'));

        $this->assertSame('milik A', $this->diToko($a, fn () => Setting::get('penanda_uji')));
        $this->assertNull($this->diToko($b, fn () => Setting::get('penanda_uji')));
        $this->assertSame(
            [1, 1],
            [
                $this->diToko($a, fn () => User::query()->where('email', 'a@contoh.id')->value('id')),
                $this->diToko($b, fn () => User::query()->where('email', 'b@contoh.id')->value('id')),
            ],
        );
        // Isi wizard /setup hulu ikut berjalan: satu outlet berjualan + PUSAT.
        $this->assertSame(
            ['PUSAT' => 0, 'UTAMA' => 1],
            $this->diToko($a, fn () => DB::table('outlets')->orderBy('code')->pluck('is_sales_enabled', 'code')
                ->map(fn ($v) => (int) $v)->all()),
        );
    }

    public function test_login_finds_the_store_by_email_and_the_dashboard_reads_that_store(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');

        $this->masukSebagai('b@contoh.id')->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($b->id, session('toko_id'));
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('storeProfile.name', 'Toko Bakung'));

        $this->post('/logout');
        app(Penyewaan::class)->keluar();

        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('storeProfile.name', 'Toko Anggrek'));
    }

    public function test_unknown_email_fails_exactly_like_a_wrong_password(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $tidakDikenal = $this->masukSebagai('siapa@contoh.id')->assertSessionHasErrors('email');
        $pesanTidakDikenal = session('errors')->first('email');
        app(Penyewaan::class)->keluar();

        $this->masukSebagai('a@contoh.id', 'sandi-salah')->assertSessionHasErrors('email');

        $this->assertSame($pesanTidakDikenal, session('errors')->first('email'));
        $this->assertGuest();
        $tidakDikenal->assertRedirect();
    }

    public function test_permission_cache_never_crosses_stores(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');

        // Di A, peran kasir boleh membaca laporan — dan temboloknya dipanaskan.
        $this->diToko($a, function () {
            Role::findByName('cashier')->givePermissionTo('reports-access');
            $kasir = User::query()->create(['name' => 'Kasir A', 'email' => 'kasir@a.id', 'password' => 'x-sandi-123']);
            $kasir->assignRole('cashier');
            $this->assertTrue($kasir->fresh()->can('reports-access'));
        });

        // Di B, peran bernama sama TIDAK punya izin itu. Tembolok A yang
        // terbawa akan menjawab "boleh".
        $this->diToko($b, function () {
            $kasir = User::query()->create(['name' => 'Kasir B', 'email' => 'kasir@b.id', 'password' => 'x-sandi-123']);
            $kasir->assignRole('cashier');
            $this->assertFalse($kasir->fresh()->can('reports-access'));
        });
    }

    public function test_public_link_reads_the_store_from_its_path_and_never_the_signed_in_user(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');
        $this->diToko($b, fn () => DB::table('transactions')->insert([
            'cashier_id' => 1, 'invoice' => 'TRX-B-1', 'access_token' => 'token-b',
            'cash' => 10000, 'change' => 0, 'discount' => 0, 'grand_total' => 10000,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        // Pemilik A sedang masuk (pengguna nomor 1 di A) membuka struk toko B.
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();

        $this->get("/t/{$b->kode}/portal/transactions/TRX-B-1?token=token-b")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('transaction.invoice', 'TRX-B-1')
                // Nomor 1 di B adalah pemilik B — bukan orang yang sedang masuk.
                ->where('auth.user', null));

        $this->get("/t/{$a->kode}/portal/transactions/TRX-B-1?token=token-b")->assertNotFound();
        $this->get('/t/toko-tidak-ada/portal/transactions/TRX-B-1?token=token-b')->assertNotFound();

        // Sesi A tetap utuh sesudahnya.
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('storeProfile.name', 'Toko Anggrek'));
    }

    public function test_route_helper_builds_public_links_with_the_store_path(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $this->assertStringEndsWith(
            "/t/{$a->kode}/share/transactions/TRX-1",
            $this->diToko($a, fn () => route('transactions.public', ['invoice' => 'TRX-1'])),
        );
        $this->assertSame(
            "/t/{$a->kode}/dine/meja-1",
            $this->diToko($a, fn () => route('dine.menu', 'meja-1', false)),
        );
        $this->assertStringContainsString(
            "/api/t/{$a->kode}/webhooks/midtrans",
            $this->diToko($a, fn () => route('webhooks.midtrans')),
        );
    }

    public function test_api_needs_the_store_header_and_api_login_returns_the_store_code(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');

        $masuk = $this->postJson('/api/v1/auth/login', ['email' => 'a@contoh.id', 'password' => 'sandi-rahasia-1'])
            ->assertOk()
            ->assertJsonPath('toko', $a->kode);
        $token = $masuk->json('data.token') ?? $masuk->json('token');
        $this->assertNotEmpty($token);
        app(Penyewaan::class)->keluar();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(400);
        app(Penyewaan::class)->keluar();
        $this->withToken($token)->withHeader('X-Toko', $a->kode)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonFragment(['email' => 'a@contoh.id']);
        app(Penyewaan::class)->keluar();
        // Token A dibawa ke toko B: tidak ada di sana.
        $this->withToken($token)->withHeader('X-Toko', $b->kode)->getJson('/api/v1/auth/me')->assertUnauthorized();
        app(Penyewaan::class)->keluar();

        $this->postJson('/api/v1/auth/register', ['name' => 'X', 'email' => 'x@x.id', 'password' => 'xxxxxxxx'])
            ->assertForbidden();
    }

    public function test_api_login_with_unknown_email_answers_like_a_wrong_password(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $salah = $this->postJson('/api/v1/auth/login', ['email' => 'a@contoh.id', 'password' => 'salah'])
            ->assertStatus(422);
        app(Penyewaan::class)->keluar();
        $asing = $this->postJson('/api/v1/auth/login', ['email' => 'asing@contoh.id', 'password' => 'salah'])
            ->assertStatus(422);

        $this->assertSame($salah->json('errors.email'), $asing->json('errors.email'));
    }

    public function test_scheduler_runs_every_command_once_per_store(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');
        // Didaftarkan ke aplikasi artisan yang SUDAH berjalan (Artisan::command
        // hanya menempel pada aplikasi artisan yang dibuat sesudahnya).
        $this->app->make(Kernel::class)->registerCommand(new class extends Command
        {
            protected $signature = 'uji:tandai-toko';

            public function handle(): int
            {
                Setting::set('ditandai', app(Penyewaan::class)->toko()->kode);

                return self::SUCCESS;
            }
        });

        $kode = Artisan::call('toko:jalankan', ['perintah' => 'uji:tandai-toko']);
        $this->assertSame(0, $kode, Artisan::output());

        $this->assertSame($a->kode, $this->diToko($a, fn () => Setting::get('ditandai')));
        $this->assertSame($b->kode, $this->diToko($b, fn () => Setting::get('ditandai')));

        $perintah = collect(Schedule::events())->map(fn ($e) => $e->command)->implode("\n");
        foreach (['crm:sync-segments', 'crm:generate-reminders', 'reorder:generate', 'transactions:expire'] as $nama) {
            $this->assertStringContainsString("toko:jalankan {$nama}", $perintah);
        }
    }

    public function test_plain_migrate_is_refused_because_it_would_write_into_the_central_database(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pusat:migrasi');

        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_an_email_used_by_another_store_is_refused_and_the_directory_follows_changes(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');

        $this->diToko($b, function () {
            try {
                User::query()->create(['name' => 'Penyusup', 'email' => 'A@Contoh.id', 'password' => 'x-sandi-123']);
                $this->fail('Surel milik toko lain diterima.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('email', $e->errors());
            }

            User::query()->where('email', 'b@contoh.id')->first()->update(['email' => 'b2@contoh.id']);
        });

        $this->assertNull(DirektoriPengguna::query()->find('b@contoh.id'));
        $this->assertSame($b->id, DirektoriPengguna::query()->find('b2@contoh.id')?->toko_id);
    }

    public function test_a_signed_in_session_without_a_store_is_discarded_instead_of_crashing(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $kunci = Auth::guard('web')->getName();

        $this->withSession([$kunci => 1])->get('/dashboard')->assertRedirect('/login');
        // Dibuang, bukan sekadar tidak dipercaya: sisa itu tidak boleh ikut
        // ke permintaan berikutnya, toko apa pun yang dimasuki kemudian.
        $this->assertFalse(session()->has($kunci));
    }

    public function test_rate_limit_signature_differs_per_store_for_the_same_user_number(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');
        $tanda = function () {
            $permintaan = Request::create('/dashboard');
            $permintaan->setUserResolver(fn () => User::query()->find(1));
            $pembatas = app(BatasiLajuPerToko::class);

            return (fn () => $this->resolveRequestSignature($permintaan))->call($pembatas);
        };

        $this->assertNotSame($this->diToko($a, $tanda), $this->diToko($b, $tanda));
    }
}
