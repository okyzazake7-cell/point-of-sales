<?php

namespace Tests\Feature\BanyakToko;

use App\Langganan\Harga;
use App\Langganan\Langganan;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Pusat\LogPengelola;
use App\Models\Pusat\Pengelola;
use App\Models\Pusat\Tagihan;
use App\Models\Pusat\Toko;
use App\Models\User;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\BanyakTokoTestCase;

/**
 * Langganan Aishii POS (AS7–AS9, dokumen 24 §4–§7): Rp per outlet per bulan,
 * tanpa masa coba, langsung terkunci (baca tetap boleh), tagihan QRIS yang
 * kode uniknya dipilih buku tagihan bersama Aishii — atau manual bila buku
 * itu belum ada.
 */
class LanggananTest extends BanyakTokoTestCase
{
    private const BUKU = 'https://aishii-uji.supabase.co';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-03 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @var array<string, callable(PermintaanHttp): mixed> jawaban buku tagihan palsu, bisa diganti di tengah uji */
    private array $jawabanBuku = [];

    private function pasangBukuTagihan(array $jawaban): void
    {
        config(['langganan.buku_tagihan' => [
            'url' => self::BUKU, 'kunci_anon' => 'anon-uji', 'rahasia' => 'rahasia-pos-uji', 'batas_waktu' => 2,
        ]]);
        // Http::fake yang dipanggil dua kali MENUMPUK, dan yang pertama
        // menang — jadi satu penjawab yang membaca daftar yang bisa diganti.
        $sudahTerpasang = $this->jawabanBuku !== [];
        $this->jawabanBuku = array_merge($this->jawabanBuku, $jawaban);
        if (! $sudahTerpasang) {
            Http::fake(function (PermintaanHttp $r) {
                $fungsi = basename(parse_url($r->url(), PHP_URL_PATH));
                $isi = $this->jawabanBuku[$fungsi] ?? Http::response(['message' => 'tidak dikenal'], 404);

                return is_callable($isi) ? $isi($r) : $isi;
            });
        }
    }

    private function aktifkan(Toko $toko, string $sampai, int $kursi = 1): Toko
    {
        $toko->forceFill(['aktif_sampai' => Carbon::parse($sampai), 'kursi_outlet' => $kursi])->save();

        return $toko->refresh();
    }

    public function test_price_math_per_outlet_and_daily_proration(): void
    {
        $this->assertSame(25000, Harga::perOutlet());
        $this->assertSame(150000, Harga::perpanjang(2, 3));
        $this->assertSame(25000, Harga::tambahOutlet(1, 30));
        // 25.000 × 10 ÷ 30 = 8.333 → dibulatkan ke atas ke ribuan.
        $this->assertSame(9000, Harga::tambahOutlet(1, 10));
        $this->assertSame(1000, Harga::tambahOutlet(1, 1));
        $this->assertSame(38000, Harga::tambahOutlet(3, 15));
        $sekarang = Carbon::parse('2026-10-03 10:00:00');
        $this->assertSame(1, Harga::sisaHari($sekarang->copy()->addHour(), $sekarang));
        $this->assertSame(0, Harga::sisaHari($sekarang->copy()->subMinute(), $sekarang));
        $this->assertSame(0, Harga::sisaHari(null, $sekarang));
    }

    public function test_signup_page_creates_the_store_logs_in_and_lands_locked_on_subscription(): void
    {
        $this->get('/daftar')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Penyewaan/Daftar')->where('hargaPerOutlet', 25000));

        $this->post('/daftar', [
            'nama_toko' => 'Toko Cempaka',
            'jenis_usaha' => 'grocery',
            'nama' => 'Bu Cempaka',
            'email' => 'cempaka@contoh.id',
            'password' => 'sandi-rahasia-1',
            'password_confirmation' => 'sandi-rahasia-1',
            ...$this->botGuardPayload(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('daftar.menyiapkan'));
        $this->selesaikanPendaftaran()->assertJsonPath('menuju', route('langganan.index'));

        $toko = Toko::query()->where('email_pemilik', 'cempaka@contoh.id')->firstOrFail();
        $this->assertSame($toko->id, session('toko_id'));
        $this->assertNull($toko->aktif_sampai);

        $this->get('/dashboard/langganan')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard/Langganan/Index')
                ->where('ringkasan.terkunci', true)
                ->where('ringkasan.pernah_aktif', false)
                ->where('ringkasan.kursi_terpakai', 1)
                ->where('tagihan', null));
    }

    public function test_locked_store_can_read_everything_but_write_nothing(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();

        // Baca: tetap terbuka.
        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard/products')->assertOk();
        $this->get('/dashboard/categories')->assertOk();

        // Tulis: ditolak — Inertia kembali dengan pesan, JSON 423.
        $this->from('/dashboard/categories')->post('/dashboard/categories', ['name' => 'Baru'])
            ->assertRedirect('/dashboard/categories')
            ->assertSessionHasErrors('langganan');
        $this->postJson('/dashboard/categories', ['name' => 'Baru'])->assertStatus(423)->assertJsonPath('terkunci', true);
        $this->assertSame(0, $this->diToko(Toko::query()->first(), fn () => Category::query()->where('name', 'Baru')->count()));

        // Yang tetap boleh: membayar dan urusan akun sendiri.
        $this->post('/dashboard/langganan/tagihan', ['jenis' => 'perpanjang', 'bulan' => 1, 'kursi' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Tagihan::query()->count());
        $this->post('/language/switch', ['locale' => 'id'])->assertSessionHasNoErrors();
    }

    public function test_without_the_shared_invoice_book_the_invoice_is_round_and_manual(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $tagihan = app(Langganan::class)->buatPerpanjang($toko, 3, 1);

        $this->assertSame('manual', $tagihan->pencocok);
        $this->assertNull($tagihan->kode_unik);
        $this->assertSame(75000, $tagihan->total_bayar);
        $this->assertTrue($tagihan->berlaku_sampai->equalTo(now()->addHours(72)));
    }

    public function test_invoice_book_picks_the_unique_code_and_payment_unlocks_automatically(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->pasangBukuTagihan([
            'pos_buat_tagihan' => Http::response(['status' => 'ok', 'kode_unik' => 417, 'total_bayar' => 25417, 'status_tagihan' => 'terbuka']),
            'pos_status_tagihan' => Http::response(['status' => 'ok', 'tagihan' => []]),
        ]);

        $tagihan = app(Langganan::class)->buatPerpanjang($toko, 1, 1);
        $this->assertSame(['aishii', 417, 25417, 25000], [$tagihan->pencocok, $tagihan->kode_unik, $tagihan->total_bayar, $tagihan->nominal_dasar]);
        Http::assertSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/rpc/pos_buat_tagihan')
            && $r['p_rahasia'] === 'rahasia-pos-uji'
            && $r['p_ref'] === $tagihan->nomor
            && $r['p_nominal'] === 25000
            && $r->hasHeader('apikey', 'anon-uji'));

        // Belum dibayar: tetap terkunci.
        Artisan::call('langganan:periksa');
        $this->assertNull($toko->refresh()->aktif_sampai);

        // Nomor tagihan sungguhan disisipkan ke jawaban palsu kedua.
        $this->pasangBukuTagihan([
            'pos_status_tagihan' => Http::response(['status' => 'ok', 'tagihan' => [
                ['ref' => $tagihan->nomor, 'status' => 'dibayar', 'dibayar_pada' => '2026-10-03T10:05:00+00:00'],
            ]]),
        ]);
        Artisan::call('langganan:periksa');

        $toko->refresh();
        $this->assertSame('2026-11-03 10:00:00', $toko->aktif_sampai->format('Y-m-d H:i:s'));
        $this->assertSame('lunas', $tagihan->refresh()->status);
        $this->assertSame('otomatis', $tagihan->dibayar_lewat);
        $this->assertFalse(app(Langganan::class)->terkunci($toko));
    }

    public function test_early_renewal_stacks_on_the_end_date_and_late_renewal_starts_today(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $langganan = app(Langganan::class);

        $this->aktifkan($toko, '2026-10-13 10:00:00');
        $langganan->terapkanLunas($langganan->buatPerpanjang($toko, 1, 1), 'manual');
        $this->assertSame('2026-11-13', $toko->refresh()->aktif_sampai->toDateString());

        $this->aktifkan($toko, '2026-09-01 10:00:00');
        $langganan->terapkanLunas($langganan->buatPerpanjang($toko, 1, 1), 'manual');
        $this->assertSame('2026-11-03', $toko->refresh()->aktif_sampai->toDateString());
    }

    public function test_paying_twice_for_the_same_invoice_changes_nothing_the_second_time(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $langganan = app(Langganan::class);
        $tagihan = $langganan->buatPerpanjang($toko, 1, 1);

        $langganan->terapkanLunas($tagihan, 'otomatis');
        $sekali = $toko->refresh()->aktif_sampai->toIso8601String();
        $langganan->terapkanLunas($tagihan, 'manual');

        $this->assertSame($sekali, $toko->refresh()->aktif_sampai->toIso8601String());
    }

    public function test_a_second_selling_outlet_needs_a_seat_priced_by_remaining_days(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $toko = $this->aktifkan($toko, '2026-10-18 10:00:00');
        $langganan = app(Langganan::class);

        try {
            $this->diToko($toko, fn () => Outlet::query()->create(['code' => 'CAB2', 'name' => 'Cabang 2', 'is_active' => true, 'is_sales_enabled' => true]));
            $this->fail('Outlet berjualan ke-2 diterima tanpa kursi.');
        } catch (ValidationException $e) {
            // 25.000 × 15 hari ÷ 30 = 12.500 → Rp 13.000.
            $this->assertStringContainsString('Rp 13.000', $e->errors()['is_sales_enabled'][0]);
        }

        // Gudang yang tidak berjualan tetap gratis.
        $this->diToko($toko, fn () => Outlet::query()->create(['code' => 'GDG2', 'name' => 'Gudang 2', 'is_active' => true, 'is_sales_enabled' => false]));

        $tagihan = $langganan->buatTambahOutlet($toko, 1);
        $this->assertSame([13000, 15], [$tagihan->nominal_dasar, $tagihan->hari_prorata]);
        $langganan->terapkanLunas($tagihan, 'manual');

        $toko->refresh();
        $this->assertSame(2, $toko->kursi_outlet);
        $this->assertSame('2026-10-18', $toko->aktif_sampai->toDateString());
        $this->diToko($toko, fn () => Outlet::query()->create(['code' => 'CAB2', 'name' => 'Cabang 2', 'is_active' => true, 'is_sales_enabled' => true]));
        $this->assertSame(2, $langganan->kursiTerpakai($toko));
    }

    public function test_renewal_cannot_pay_for_fewer_outlets_than_are_selling(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $toko = $this->aktifkan($toko, '2026-10-18 10:00:00', 2);
        $this->diToko($toko, fn () => Outlet::query()->create(['code' => 'CAB2', 'name' => 'Cabang 2', 'is_active' => true, 'is_sales_enabled' => true]));

        $this->expectException(ValidationException::class);
        app(Langganan::class)->buatPerpanjang($toko, 1, 1);
    }

    public function test_cancelling_an_invoice_book_invoice_waits_for_the_book_to_agree(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->pasangBukuTagihan([
            'pos_buat_tagihan' => Http::response(['status' => 'ok', 'kode_unik' => 9, 'total_bayar' => 25009]),
            'pos_batalkan_tagihan' => Http::response(['message' => 'galat'], 503),
        ]);
        $langganan = app(Langganan::class);
        $tagihan = $langganan->buatPerpanjang($toko, 1, 1);

        try {
            $langganan->batalkan($tagihan);
            $this->fail('Tagihan dibatalkan padahal buku tagihan tidak menjawab.');
        } catch (ValidationException) {
            $this->assertSame('menunggu', $tagihan->refresh()->status);
        }

        // Ternyata sudah dibayar di sana: yang terjadi justru pelunasan.
        $this->pasangBukuTagihan(['pos_batalkan_tagihan' => Http::response(['status' => 'sudah_dibayar'])]);
        $langganan->batalkan($tagihan);
        $this->assertSame('lunas', $tagihan->refresh()->status);
        $this->assertNotNull($toko->refresh()->aktif_sampai);
    }

    public function test_offline_sales_recorded_before_expiry_pass_and_later_ones_are_held_in_order(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $toko = $this->aktifkan($toko, '2026-10-02 10:00:00');
        $produk = $this->diToko($toko, function () {
            $kategori = Category::query()->create(['name' => 'Uji', 'image' => 'x.jpg', 'description' => '-']);

            return Product::query()->create([
                'title' => 'Teh', 'sku' => 'SKU-1', 'buy_price' => 1000, 'sell_price' => 2000, 'stock' => 10,
                'image' => 'x.jpg', 'barcode' => 'BC-1', 'description' => '-', 'tax_rate' => 0, 'category_id' => $kategori->id,
            ]);
        });
        $token = $this->diToko($toko, fn () => User::query()->where('email', 'a@contoh.id')->first()->createToken('uji', ['*'])->plainTextToken);
        $baris = fn (string $uuid, string $tercatat) => [
            'client_uuid' => $uuid, 'items' => [['product_id' => $produk->id, 'qty' => 1]],
            'payment_method' => 'cash', 'cash' => 2000, 'recorded_at' => $tercatat,
        ];
        $sebelum = '11111111-1111-4111-8111-111111111111';
        $sesudah = '22222222-2222-4222-8222-222222222222';

        $jawaban = $this->withToken($token)->withHeader('X-Toko', $toko->kode)->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$baris($sesudah, '2026-10-02T12:00:00+00:00'), $baris($sebelum, '2026-10-01T09:00:00+00:00')],
        ])->assertOk();

        // Urutan hasil = urutan kiriman: layar kasir mencocokkan menurut indeks.
        $this->assertSame($sesudah, $jawaban->json('data.results.0.client_uuid'));
        $this->assertSame('held', $jawaban->json('data.results.0.status'));
        $this->assertSame($sebelum, $jawaban->json('data.results.1.client_uuid'));
        $this->assertNotSame('held', $jawaban->json('data.results.1.status'));

        $semuaSesudah = $this->withToken($token)->withHeader('X-Toko', $toko->kode)->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [$baris($sesudah, '2026-10-02T12:00:00+00:00')],
        ])->assertStatus(423);
        $this->assertSame('held', $semuaSesudah->json('data.results.0.status'));
    }

    public function test_customers_cannot_order_from_a_locked_store_but_can_still_read_receipts(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatToko('Toko Bakung', 'b@contoh.id');
        $this->aktifkan($b, '2026-12-01 10:00:00');

        // Toko terkunci: kunci menjawab lebih dulu daripada pencarian datanya.
        $this->from('/')->post("/t/{$a->kode}/portal/receivables/1/pay", ['token' => 'x'])
            ->assertRedirect('/')->assertSessionHasErrors('langganan');
        // Toko aktif: permintaan yang sama sampai ke pengendalinya (tidak ada piutangnya).
        $this->post("/t/{$b->kode}/portal/receivables/1/pay", ['token' => 'x'])->assertNotFound();
    }

    public function test_stopping_an_outlet_from_selling_is_allowed_while_locked(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();
        $utama = $this->diToko($toko, fn () => Outlet::query()->where('code', 'UTAMA')->firstOrFail());

        $this->post("/dashboard/langganan/outlet/{$utama->id}/berhenti-jual")->assertSessionHasNoErrors();

        $this->assertFalse($this->diToko($toko, fn () => (bool) Outlet::query()->find($utama->id)->is_sales_enabled));
    }

    public function test_service_admin_marks_paid_with_a_reason_and_it_is_logged(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $tagihan = app(Langganan::class)->buatPerpanjang($toko, 1, 1);
        Pengelola::query()->create(['nama' => 'Pengelola', 'email' => 'kelola@aishii.id', 'password' => 'sandi-pengelola-panjang']);

        $this->get('/pengelola')->assertRedirect('/pengelola/masuk');
        $this->post('/pengelola/masuk', ['email' => 'kelola@aishii.id', 'password' => 'sandi-pengelola-panjang', ...$this->botGuardPayload()])
            ->assertRedirect('/pengelola');
        $this->get('/pengelola')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Pengelola/Index')->has('toko', 1)->has('tagihan', 1));

        $this->post("/pengelola/tagihan/{$tagihan->id}/lunas", ['alasan' => ''])->assertSessionHasErrors('alasan');
        $this->post("/pengelola/tagihan/{$tagihan->id}/lunas", ['alasan' => 'Transfer Rp 25.000 terlihat di GoPay 10.05'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['lunas', 'manual'], [$tagihan->refresh()->status, $tagihan->dibayar_lewat]);
        $this->assertNotNull($toko->refresh()->aktif_sampai);
        $this->assertSame(1, LogPengelola::query()->where('tagihan_id', $tagihan->id)->count());
    }

    public function test_store_owner_session_is_not_a_service_admin_session(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();

        $this->get('/pengelola')->assertRedirect('/pengelola/masuk');
    }

    public function test_public_price_endpoint_is_readable_by_aishii_without_a_store(): void
    {
        $this->getJson('/api/harga')->assertOk()
            ->assertJsonPath('harga_per_outlet', 25000)
            ->assertJsonPath('masa_coba_hari', 0)
            ->assertHeader('Cache-Control');
        $lintas = $this->withHeader('Origin', 'https://aishiierp.com')->getJson('/api/harga');
        $this->assertContains($lintas->headers->get('Access-Control-Allow-Origin'), ['*', 'https://aishiierp.com']);
    }

    public function test_payment_poller_lock_releases_itself_within_five_minutes(): void
    {
        // Cloud Run bisa menghentikan instans di tengah `langganan:periksa`
        // (terbitan baru, penyusutan). Kunci withoutOverlapping bawaan
        // bertahan 24 jam — selama itu pembayaran QRIS tidak dijemput.
        $periksa = collect(Schedule::events())
            ->first(fn ($acara) => str_contains($acara->command, 'langganan:periksa'));

        $this->assertNotNull($periksa);
        $this->assertTrue($periksa->withoutOverlapping);
        $this->assertSame(5, $periksa->expiresAt);
    }
}
