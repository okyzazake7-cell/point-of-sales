<?php

namespace Tests\Feature\BanyakToko;

use App\AkunAishii\AkunTertaut;
use App\Http\Controllers\Penyewaan\PengelolaController;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Pengelola;
use App\Models\Pusat\Toko;
use App\Models\User;
use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\Penyewaan;
use Illuminate\Http\Client\Request as PermintaanHttp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\BanyakTokoTestCase;

/**
 * "Masuk dengan akun Aishii" (AU1, dokumen 27 di repo Aishii) melawan
 * penyedia OIDC TIRUAN: kunci ES256 dibangkitkan di sini, JWKS dan titik
 * token Supabase ditiru `Http::fake` — persis bentuk yang dijawab OAuth 2.1
 * Server Supabase (dokumen resminya, dibaca 7 Okt).
 *
 * Tiap penolakan dipasangkan dengan jalan yang lolos: penjaga yang menolak
 * segalanya sama tidak bergunanya dengan yang menerima segalanya.
 */
class MasukAishiiTest extends BanyakTokoTestCase
{
    private const PENERBIT = 'https://akun.uji/auth/v1';

    private const SUB = '0b0b0b0b-1111-4222-8333-444444444444';

    private const SUB_LAIN = '0c0c0c0c-1111-4222-8333-555555555555';

    private \OpenSSLAsymmetricKey $kunciPrivat;

    private string $tokenId = '';

    /** @var array<string, string> kueri permintaan otorisasi terakhir */
    private array $kueri = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'akun_aishii.penerbit' => self::PENERBIT,
            'akun_aishii.id_klien' => 'klien-pos-uji',
            'akun_aishii.rahasia_klien' => 'rahasia-uji',
        ]);

        $this->kunciPrivat = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        // OpenSSL memulangkan koordinat TANPA nol di depan (±1 dari 128 kunci
        // lebih pendek dari 32 bita); JWK wajib selalu 32 bita (RFC 7518
        // §6.2.1.2) — dan klien memang menolak yang lebih pendek.
        $ec = array_map(
            fn (string $titik) => str_pad($titik, 32, "\x00", STR_PAD_LEFT),
            array_intersect_key(openssl_pkey_get_details($this->kunciPrivat)['ec'], ['x' => 1, 'y' => 1]),
        );

        Http::fake([
            self::PENERBIT.'/.well-known/jwks.json' => Http::response(['keys' => [[
                'kty' => 'EC', 'crv' => 'P-256', 'alg' => 'ES256', 'use' => 'sig', 'kid' => 'kunci-uji',
                'x' => self::b64($ec['x']), 'y' => self::b64($ec['y']),
            ]]]),
            self::PENERBIT.'/oauth/token' => fn () => Http::response([
                'access_token' => 'akses-uji', 'token_type' => 'bearer', 'expires_in' => 3600,
                'refresh_token' => 'segar-uji', 'id_token' => $this->tokenId,
            ]),
        ]);
    }

    // ── Mati selama klien belum diisi ────────────────────────────────────

    public function test_everything_stays_off_until_the_client_secret_is_set(): void
    {
        config(['akun_aishii.rahasia_klien' => '']);
        [$toko] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->tautkan('a@contoh.id', self::SUB);

        $this->get('/auth/aishii')->assertNotFound();
        $this->get('/auth/aishii/kembali?code=x&state=y')->assertNotFound();
        $this->get('/login')->assertInertia(fn (AssertableInertia $p) => $p->where('masukAishii', null));
        $this->get('/daftar')->assertInertia(fn (AssertableInertia $p) => $p->where('akunAishii', null));
        // Kalimat "akunnya masing-masing" di sini dan di /pos Aishii tetap benar.
        $this->getJson('/api/harga')->assertJsonPath('akun_aishii', false);
        $this->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('akunAishii', false));

        // Mematikan fiturnya tidak mengunci siapa pun di luar (D2 ikut mati).
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->assertSame($toko->id, session('toko_id'));
    }

    // ── Permintaan otorisasi ─────────────────────────────────────────────

    public function test_the_button_sends_a_pkce_request_with_state_and_nonce(): void
    {
        $this->get('/login')->assertInertia(fn (AssertableInertia $p) => $p->where('masukAishii', route('aishii.masuk')));
        // Halaman /pos Aishii dan tanya jawab di sini kini berkata "satu akun".
        $this->getJson('/api/harga')->assertJsonPath('akun_aishii', true);
        $this->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('akunAishii', true));

        $jawaban = $this->get('/auth/aishii');
        $alamat = $jawaban->headers->get('Location');
        $this->assertStringStartsWith(self::PENERBIT.'/oauth/authorize?', $alamat);
        parse_str((string) parse_url($alamat, PHP_URL_QUERY), $q);
        $bekal = session('akun_aishii.bekal');

        $this->assertSame('code', $q['response_type']);
        $this->assertSame('klien-pos-uji', $q['client_id']);
        $this->assertSame(rtrim(config('app.url'), '/').'/auth/aishii/kembali', $q['redirect_uri']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(self::b64(hash('sha256', $bekal['pemverifikasi'], true)), $q['code_challenge']);
        $this->assertSame($bekal['state'], $q['state']);
        $this->assertSame($bekal['nonce'], $q['nonce']);
        $this->assertGreaterThanOrEqual(40, strlen($q['state']));
        // Rahasia klien TIDAK PERNAH ikut ke peramban.
        $this->assertStringNotContainsString('rahasia-uji', $alamat);
    }

    // ── Masuk ────────────────────────────────────────────────────────────

    public function test_a_linked_account_lands_in_its_own_store(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        [$b] = $this->buatTokoAishii('Toko Bakung', 'b@contoh.id', self::SUB);

        $this->masukLewatAishii(['email' => 'b@contoh.id'])->assertRedirect();

        $this->assertAuthenticated();
        $this->assertSame($b->id, session('toko_id'));
        $this->assertSame('b@contoh.id', Auth::user()->email);
        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('storeProfile.name', 'Toko Bakung'));

        // Penukaran kode: rahasia lewat Basic, pemverifikasi PKCE ikut.
        Http::assertSent(function (PermintaanHttp $r) {
            if ($r->url() !== self::PENERBIT.'/oauth/token') {
                return false;
            }

            return $r->header('Authorization')[0] === 'Basic '.base64_encode('klien-pos-uji:rahasia-uji')
                && $r['grant_type'] === 'authorization_code'
                && $r['code'] === 'kode-uji'
                && self::b64(hash('sha256', $r['code_verifier'], true)) === $this->kueri['code_challenge']
                && $r['redirect_uri'] === $this->kueri['redirect_uri'];
        });
    }

    public function test_an_old_account_is_linked_once_by_verified_email_then_by_sub(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $this->masukLewatAishii(['email' => 'a@contoh.id'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame(self::SUB, DirektoriPengguna::query()->find('a@contoh.id')->aishii_sub);

        // Sesudah tertaut, `sub` yang menentukan: surel akun Aishii yang
        // berganti tidak memutus jalan masuknya.
        $this->post('/logout');
        $this->masukLewatAishii(['email' => 'surel-baru@contoh.id'])->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($a->id, session('toko_id'));
    }

    public function test_an_unverified_email_never_links_an_old_account(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');

        $this->masukLewatAishii(['email' => 'a@contoh.id', 'email_verified' => false])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('aishii');

        $this->assertGuest();
        $this->assertNull(DirektoriPengguna::query()->find('a@contoh.id')->aishii_sub);
    }

    public function test_an_email_linked_to_another_aishii_account_is_refused(): void
    {
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB_LAIN);

        $this->masukLewatAishii(['email' => 'a@contoh.id'])->assertSessionHasErrors('aishii');

        $this->assertGuest();
        $this->assertSame(self::SUB_LAIN, DirektoriPengguna::query()->find('a@contoh.id')->aishii_sub);
    }

    /**
     * Satu pasangan per pemeriksaan: tiap token di bawah lolos semua
     * pemeriksaan KECUALI satu.
     */
    public function test_a_token_that_fails_any_single_check_is_refused(): void
    {
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);
        $kunciLain = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $kasus = [
            'tanda tangan kunci lain' => [[], [], $kunciLain],
            'alg HS256' => [[], ['alg' => 'HS256'], null],
            'alg none' => [[], ['alg' => 'none'], null],
            'iss lain' => [['iss' => 'https://jahat.uji/auth/v1'], [], null],
            'aud lain' => [['aud' => 'klien-lain'], [], null],
            'aud ganda tanpa azp' => [['aud' => ['klien-pos-uji', 'klien-lain']], [], null],
            'sudah kedaluwarsa' => [['exp' => time() - 3600], [], null],
            'nonce lain' => [['nonce' => 'nonce-curian'], [], null],
            'tanpa sub' => [['sub' => ''], [], null],
        ];

        foreach ($kasus as $nama => [$klaim, $kepala, $kunci]) {
            $this->masukLewatAishii($klaim, $kepala, $kunci)->assertSessionHasErrors('aishii');
            $this->assertGuest();
            $this->assertNull(session('toko_id'), $nama);
        }

        // Pasangannya: token yang sama tanpa cacat diterima.
        $this->masukLewatAishii()->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_an_unknown_key_id_rereads_the_jwks_once_then_refuses(): void
    {
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);

        $this->masukLewatAishii([], ['kid' => 'kunci-sesudah-rotasi'])->assertSessionHasErrors('aishii');

        $this->assertGuest();
        $jwks = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/jwks.json'))->count();
        $this->assertSame(2, $jwks);
    }

    public function test_a_wrong_state_never_exchanges_the_code(): void
    {
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);

        $this->masukLewatAishii([], [], null, ['state' => 'state-curian'])->assertSessionHasErrors('aishii');

        $this->assertGuest();
        Http::assertNotSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/oauth/token'));
    }

    public function test_the_same_answer_cannot_be_replayed(): void
    {
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);

        // Jawaban pertama membawa token cacat: ditolak, dan bekalnya habis.
        $this->masukLewatAishii(['nonce' => 'nonce-curian'])->assertSessionHasErrors('aishii');

        // Jawaban yang SAMA diputar ulang — kali ini tokennya sah. Tetap
        // ditolak, tanpa penukaran kode kedua. (Bukan sesudah keluar: keluar
        // mengosongkan sesi, dan uji itu lulus walau bekalnya bisa dipakai lagi.)
        $this->tokenId = $this->tokenSah();
        $this->get('/auth/aishii/kembali?'.http_build_query(['code' => 'kode-uji', 'state' => $this->kueri['state']]))
            ->assertSessionHasErrors('aishii');

        $this->assertGuest();
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/oauth/token'))->count());
    }

    public function test_cancelling_at_aishii_says_so_and_exchanges_nothing(): void
    {
        $this->get('/auth/aishii');
        $state = session('akun_aishii.bekal')['state'];

        $this->get('/auth/aishii/kembali?error=access_denied&state='.$state)
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['aishii' => 'Masuk dengan akun Aishii dibatalkan.']);

        Http::assertNotSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/oauth/token'));
    }

    // ── Toko baru lahir dari akun Aishii ─────────────────────────────────

    public function test_an_aishii_account_without_a_store_registers_one_without_a_password(): void
    {
        $this->masukLewatAishii(['email' => 'baru@contoh.id', 'name' => 'Bu Baru'])->assertRedirect(route('daftar'));
        $this->assertGuest();

        $this->get('/daftar')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('akunAishii.email', 'baru@contoh.id')
            ->where('akunAishii.nama', 'Bu Baru'));

        $this->post('/daftar', [
            'nama_toko' => 'Toko Cempaka',
            'jenis_usaha' => 'retail',
            'nama' => 'Bu Baru',
            // Surel dan sandi kiriman formulir DIABAIKAN: surel dari akun Aishii.
            'email' => 'penyusup@contoh.id',
            'password' => 'sandi-kiriman-1',
            'password_confirmation' => 'sandi-kiriman-1',
            ...$this->botGuardPayload(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('daftar.menyiapkan'));
        $this->selesaikanPendaftaran()->assertJsonPath('menuju', route('langganan.index'));

        $this->assertAuthenticated();
        $baris = DirektoriPengguna::query()->find('baru@contoh.id');
        $this->assertSame(self::SUB, $baris->aishii_sub);
        $this->assertNull(DirektoriPengguna::query()->find('penyusup@contoh.id'));
        $toko = Toko::query()->findOrFail($baris->toko_id);
        $sandi = $this->diToko($toko, fn () => User::query()->findOrFail($baris->user_id)->password);
        $this->assertFalse(Hash::check('sandi-kiriman-1', $sandi));
        $this->assertNull(session('akun_aishii.identitas'));

        // Masuk berikutnya langsung ke toko itu.
        $this->post('/logout');
        $this->masukLewatAishii(['email' => 'baru@contoh.id'])->assertRedirect();
        $this->assertSame($toko->id, session('toko_id'));
    }

    public function test_round_trips_to_aishii_do_not_use_up_the_registration_limit(): void
    {
        // Tiga kali bolak-balik (pemilik mencoba pintu pengelola, lalu pintu
        // toko) = enam permintaan; jatah /daftar lima per sepuluh menit.
        for ($i = 0; $i < 3; $i++) {
            $this->masukLewatAishii(['email' => 'baru@contoh.id', 'name' => 'Bu Baru'])->assertRedirect(route('daftar'));
        }

        $this->post('/daftar', [
            'nama_toko' => 'Toko Cempaka',
            'jenis_usaha' => 'retail',
            'nama' => 'Bu Baru',
            ...$this->botGuardPayload(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('daftar.menyiapkan'));
        $this->selesaikanPendaftaran()->assertJsonPath('menuju', route('langganan.index'));
        $this->assertAuthenticated();
    }

    public function test_registering_without_an_aishii_identity_is_refused_while_on(): void
    {
        $this->get('/daftar')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('akunAishii.email', null)
            ->where('akunAishii.masuk', route('aishii.masuk')));

        $this->post('/daftar', [
            'nama_toko' => 'Toko Cempaka',
            'jenis_usaha' => 'retail',
            'nama' => 'Bu Baru',
            'email' => 'baru@contoh.id',
            'password' => 'sandi-rahasia-1',
            'password_confirmation' => 'sandi-rahasia-1',
            ...$this->botGuardPayload(),
        ])->assertSessionHasErrors('aishii');

        $this->assertSame(0, Toko::query()->count());
    }

    // ── D2: sandi tidak lagi membuka akun yang tertaut ───────────────────

    public function test_password_login_is_refused_for_a_linked_account_but_not_for_its_cashier(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->diToko($a, function () {
            $kasir = User::query()->create(['name' => 'Kasir', 'email' => 'kasir@a.id', 'password' => 'sandi-kasir-1']);
            $kasir->assignRole('cashier');
        });
        $this->tautkan('a@contoh.id', self::SUB);

        // Sandi BENAR: diberi tahu jalannya.
        $this->masukSebagai('a@contoh.id')->assertSessionHasErrors(['email' => AkunTertaut::KALIMAT_PAKAI_AISHII]);
        $this->assertGuest();

        // Sandi salah: persis seperti surel yang tidak dikenal.
        $this->masukSebagai('a@contoh.id', 'sandi-salah')->assertSessionHasErrors('email');
        $pesanSalah = session('errors')->first('email');
        $this->masukSebagai('siapa@contoh.id')->assertSessionHasErrors('email');
        $this->assertSame(session('errors')->first('email'), $pesanSalah);
        $this->assertGuest();

        // Kasir tidak tertaut: tetap bersandi (D1b).
        $this->masukSebagai('kasir@a.id', 'sandi-kasir-1')->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_api_password_login_is_refused_for_a_linked_account(): void
    {
        [$a] = $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->diToko($a, function () {
            $kasir = User::query()->create(['name' => 'Kasir', 'email' => 'kasir@a.id', 'password' => 'sandi-kasir-1']);
            $kasir->assignRole('cashier');
        });
        $this->tautkan('a@contoh.id', self::SUB);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@contoh.id', 'password' => 'sandi-rahasia-1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Kredensial yang diberikan tidak cocok dengan data kami.');

        $this->postJson('/api/v1/auth/login', ['email' => 'kasir@a.id', 'password' => 'sandi-kasir-1'])->assertOk();
    }

    // ── Konfirmasi tindakan penting (`step_up`) lewat akun Aishii ────────

    public function test_a_linked_owner_confirms_sensitive_actions_with_a_fresh_aishii_sign_in(): void
    {
        // Akun LAMA yang sandinya diketahui, lalu tertaut lewat masuk pertama:
        // hanya dengan sandi yang benar penolakan sandi toko di bawah berarti.
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->masukLewatAishii()->assertSessionHasNoErrors();
        $this->assertSame(self::SUB, DirektoriPengguna::query()->find('a@contoh.id')->aishii_sub);

        $this->get('/confirm-password')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('konfirmasiAishii', AkunTertaut::alamatKonfirmasi()));
        $this->assertSame(
            config('brand.parent.url').'/masuk-ulang?lanjut='.rawurlencode(rtrim(config('app.url'), '/').'/auth/aishii/konfirmasi/mulai'),
            AkunTertaut::alamatKonfirmasi(),
        );
        // Sandi toko tidak bisa dipakai mengonfirmasi akun yang tertaut.
        $this->post('/confirm-password', ['password' => 'sandi-rahasia-1'])->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        // Sandi akun Aishii dimasukkan 10 menit lalu: basi.
        $this->konfirmasiLewatAishii(['auth_time' => time() - 600])->assertSessionHasErrors('aishii');
        $this->assertNull(session('auth.password_confirmed_at'));
        // Tanpa `auth_time` sama sekali: tidak bisa dibuktikan, maka basi.
        $this->konfirmasiLewatAishii(['auth_time' => null])->assertSessionHasErrors('aishii');
        // Akun Aishii lain.
        $this->konfirmasiLewatAishii(['sub' => self::SUB_LAIN])->assertSessionHasErrors('aishii');
        $this->assertNull(session('auth.password_confirmed_at'));

        // Baru dimasukkan: lolos, dan alamat baliknya alamat konfirmasi.
        $this->konfirmasiLewatAishii(['auth_time' => time() - 30])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(rtrim(config('app.url'), '/').'/auth/aishii/konfirmasi', $this->kueri['redirect_uri']);
        $this->assertEqualsWithDelta(time(), session('auth.password_confirmed_at'), 5);
    }

    public function test_a_password_account_keeps_confirming_with_its_password(): void
    {
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();

        $this->get('/confirm-password')->assertInertia(fn (AssertableInertia $p) => $p->where('konfirmasiAishii', null));
        $this->get('/auth/aishii/konfirmasi/mulai')->assertRedirect(route('password.confirm'));
        $this->post('/confirm-password', ['password' => 'sandi-rahasia-1'])->assertSessionHasNoErrors();
        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_the_link_follows_an_email_change_in_the_store(): void
    {
        [$a] = $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);

        $this->diToko($a, fn () => User::query()->where('email', 'a@contoh.id')->firstOrFail()->update(['email' => 'a-baru@contoh.id']));

        $this->assertNull(DirektoriPengguna::query()->find('a@contoh.id'));
        $this->assertSame(self::SUB, DirektoriPengguna::query()->find('a-baru@contoh.id')->aishii_sub);
        $this->masukLewatAishii(['email' => 'a@contoh.id'])->assertSessionHasNoErrors();
        $this->assertSame($a->id, session('toko_id'));
    }

    // ── Pintu pengelola layanan (AU5) ────────────────────────────────────

    public function test_the_service_admin_door_keeps_its_password_while_off(): void
    {
        config(['akun_aishii.rahasia_klien' => '']);
        $this->buatPengelola('p@contoh.id');

        $this->get('/pengelola/masuk')->assertInertia(fn (AssertableInertia $p) => $p->where('masukAishii', null));
        $this->get('/auth/aishii/pengelola')->assertNotFound();

        $this->masukPengelolaBersandi('p@contoh.id')->assertRedirect(route('pengelola.index'));
        $this->assertAuthenticated('pengelola');
    }

    public function test_the_service_admin_signs_in_with_aishii_and_never_with_a_password(): void
    {
        $this->buatPengelola('p@contoh.id');

        $this->get('/pengelola/masuk')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('masukAishii', route('aishii.pengelola')));

        // Sandi yang BENAR pun ditolak selama akun Aishii hidup: satu orang, satu pintu.
        $this->masukPengelolaBersandi('p@contoh.id')
            ->assertSessionHasErrors(['aishii' => PengelolaController::KALIMAT_PAKAI_AISHII]);
        $this->assertGuest('pengelola');

        // Masuk pertama: surel terverifikasi mengunci barisnya ke sub.
        $this->pengelolaLewatAishii(['email' => 'P@Contoh.id'])->assertRedirect(route('pengelola.index'));
        $this->assertAuthenticated('pengelola');
        $this->assertSame(self::SUB, Pengelola::query()->sole()->aishii_sub);
        // Akun pusat — bukan pengguna toko mana pun.
        $this->assertGuest('web');
        $this->assertNull(session('toko_id'));
        $this->get('/pengelola')->assertOk();

        // Sesudahnya sub yang menentukan: surel akun Aishii boleh berganti.
        $this->post('/pengelola/keluar')->assertRedirect(route('pengelola.masuk'));
        $this->assertGuest('pengelola');
        $this->pengelolaLewatAishii(['email' => 'surel-baru@contoh.id', 'email_verified' => false])
            ->assertRedirect(route('pengelola.index'));
        $this->assertAuthenticated('pengelola');
    }

    public function test_an_aishii_account_that_is_not_a_service_admin_is_refused(): void
    {
        $this->buatPengelola('p@contoh.id');
        // Pemilik toko yang tertaut: sah di pintu toko, bukan di pintu pengelola.
        $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);

        $this->pengelolaLewatAishii(['email' => 'a@contoh.id'])
            ->assertRedirect(route('pengelola.masuk'))
            ->assertSessionHasErrors(['aishii' => 'Akun Aishii ini bukan pengelola Aishii POS.']);

        $this->assertGuest('pengelola');
        $this->assertGuest('web');
        $this->assertNull(Pengelola::query()->sole()->aishii_sub);
        $this->get('/pengelola')->assertRedirect(route('pengelola.masuk'));
    }

    public function test_an_unverified_or_differently_linked_email_never_opens_the_service_admin_door(): void
    {
        $pengelola = $this->buatPengelola('p@contoh.id');

        $this->pengelolaLewatAishii(['email' => 'p@contoh.id', 'email_verified' => false])
            ->assertRedirect(route('pengelola.masuk'))
            ->assertSessionHasErrors('aishii');
        $this->assertGuest('pengelola');
        $this->assertNull($pengelola->fresh()->aishii_sub);

        $pengelola->forceFill(['aishii_sub' => self::SUB_LAIN])->save();
        $this->pengelolaLewatAishii(['email' => 'p@contoh.id'])
            ->assertSessionHasErrors(['aishii' => 'Surel p@contoh.id sudah tertaut ke akun Aishii lain sebagai pengelola.']);
        $this->assertGuest('pengelola');
        $this->assertSame(self::SUB_LAIN, $pengelola->fresh()->aishii_sub);
    }

    public function test_cancelling_at_aishii_returns_to_the_service_admin_door(): void
    {
        $this->get('/auth/aishii/pengelola');
        $state = session('akun_aishii.bekal')['state'];

        $this->get('/auth/aishii/kembali?error=access_denied&state='.$state)
            ->assertRedirect(route('pengelola.masuk'))
            ->assertSessionHasErrors(['aishii' => 'Masuk dengan akun Aishii dibatalkan.']);

        Http::assertNotSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/oauth/token'));
    }

    public function test_a_signed_in_store_owner_can_also_open_the_service_admin_door(): void
    {
        [$a] = $this->buatTokoAishii('Toko Anggrek', 'a@contoh.id', self::SUB);
        $this->buatPengelola('a@contoh.id');

        $this->masukLewatAishii(['email' => 'a@contoh.id'])->assertRedirect();
        $this->assertAuthenticated('web');

        $this->pengelolaLewatAishii(['email' => 'a@contoh.id'])->assertRedirect(route('pengelola.index'));
        $this->assertAuthenticated('pengelola');
        // Dua penjaga yang terpisah: sesi tokonya tetap utuh.
        $this->assertAuthenticated('web');
        $this->assertSame($a->id, session('toko_id'));
    }

    public function test_a_signed_in_store_user_is_not_signed_in_twice(): void
    {
        // `/kembali` kehilangan middleware `guest`-nya demi pintu pengelola;
        // penjagaan yang sama kini di controller.
        $this->buatToko('Toko Anggrek', 'a@contoh.id');
        $this->buatTokoAishii('Toko Bakung', 'b@contoh.id', self::SUB);

        // Tombol ditekan saat belum masuk, lalu masuk bersandi di tab lain —
        // dan jawaban akun Aishii tiba sesudahnya.
        $this->bukaOtorisasi('/auth/aishii', ['email' => 'b@contoh.id']);
        $this->masukSebagai('a@contoh.id')->assertSessionHasNoErrors();

        $this->get('/auth/aishii/kembali?'.http_build_query(['code' => 'kode-uji', 'state' => $this->kueri['state']]))
            ->assertRedirect(route('dashboard'));
        $this->assertSame('a@contoh.id', Auth::user()->email);
        Http::assertNotSent(fn (PermintaanHttp $r) => str_ends_with($r->url(), '/oauth/token'));
    }

    // ── Bantuan ──────────────────────────────────────────────────────────

    private function buatPengelola(string $email): Pengelola
    {
        return Pengelola::query()->create(['nama' => 'Pengelola', 'email' => $email, 'password' => 'sandi-pengelola-panjang']);
    }

    private function masukPengelolaBersandi(string $email): TestResponse
    {
        return $this->post('/pengelola/masuk', [
            'email' => $email,
            'password' => 'sandi-pengelola-panjang',
            ...$this->botGuardPayload(),
        ]);
    }

    private function pengelolaLewatAishii(array $klaim = []): TestResponse
    {
        $this->bukaOtorisasi('/auth/aishii/pengelola', $klaim);

        return $this->get('/auth/aishii/kembali?'.http_build_query(['code' => 'kode-uji', 'state' => $this->kueri['state']]));
    }

    /** @return array{0: Toko, 1: User} */
    private function buatTokoAishii(string $nama, string $email, string $sub): array
    {
        return app(PendaftaranToko::class)->daftarkan([
            'nama_toko' => $nama,
            'jenis_usaha' => 'retail',
            'nama' => 'Pemilik '.$nama,
            'email' => $email,
            'password' => 'sandi-acak-tak-dikenal',
            'aishii_sub' => $sub,
        ]);
    }

    private function tautkan(string $email, string $sub): void
    {
        DirektoriPengguna::query()->whereKey($email)->update(['aishii_sub' => $sub]);
    }

    /**
     * Tombol → Supabase tiruan → kembali. `kunci` lain = token yang
     * ditandatangani pihak lain; `kueri` menimpa jawaban yang kembali.
     */
    private function masukLewatAishii(array $klaim = [], array $kepala = [], ?\OpenSSLAsymmetricKey $kunci = null, array $kueri = []): TestResponse
    {
        app(Penyewaan::class)->keluar();
        $this->bukaOtorisasi('/auth/aishii', $klaim, $kepala, $kunci);

        return $this->get('/auth/aishii/kembali?'.http_build_query([
            'code' => 'kode-uji', 'state' => $this->kueri['state'], ...$kueri,
        ]));
    }

    private function konfirmasiLewatAishii(array $klaim = []): TestResponse
    {
        $this->bukaOtorisasi('/auth/aishii/konfirmasi/mulai', $klaim);

        return $this->get('/auth/aishii/konfirmasi?'.http_build_query(['code' => 'kode-uji', 'state' => $this->kueri['state']]));
    }

    private function bukaOtorisasi(string $jalan, array $klaim = [], array $kepala = [], ?\OpenSSLAsymmetricKey $kunci = null): void
    {
        $alamat = (string) $this->get($jalan)->headers->get('Location');
        parse_str((string) parse_url($alamat, PHP_URL_QUERY), $this->kueri);

        $this->tokenId = $this->tokenSah($klaim, $kepala, $kunci);
    }

    /** Token yang lolos semua pemeriksaan untuk permintaan terakhir — kecuali yang ditimpa. */
    private function tokenSah(array $klaim = [], array $kepala = [], ?\OpenSSLAsymmetricKey $kunci = null): string
    {
        $isi = array_filter([
            'iss' => self::PENERBIT,
            'sub' => self::SUB,
            'aud' => 'klien-pos-uji',
            'exp' => time() + 3600,
            'iat' => time(),
            'auth_time' => time(),
            'nonce' => $this->kueri['nonce'] ?? '',
            'email' => 'a@contoh.id',
            'email_verified' => true,
            'name' => 'Pemilik',
            ...$klaim,
        ], fn ($nilai) => $nilai !== null);

        return $this->tandaTangan($isi, $kepala, $kunci ?? $this->kunciPrivat);
    }

    private function tandaTangan(array $isi, array $kepala, \OpenSSLAsymmetricKey $kunci): string
    {
        $kepala = ['alg' => 'ES256', 'typ' => 'JWT', 'kid' => 'kunci-uji', ...$kepala];
        $data = self::b64(json_encode($kepala)).'.'.self::b64(json_encode($isi));
        openssl_sign($data, $der, $kunci, OPENSSL_ALGO_SHA256);

        return $data.'.'.self::b64(self::derKeMentah($der));
    }

    /** ECDSA-Sig-Value DER → r‖s 64 bita, bentuk tanda tangan JWS. */
    private static function derKeMentah(string $der): string
    {
        $o = 2;
        $r = substr($der, $o + 2, ord($der[$o + 1]));
        $o += 2 + ord($der[$o + 1]);
        $s = substr($der, $o + 2, ord($der[$o + 1]));
        $rapat = fn (string $b) => str_pad(ltrim($b, "\x00"), 32, "\x00", STR_PAD_LEFT);

        return $rapat($r).$rapat($s);
    }

    private static function b64(string $biner): string
    {
        return rtrim(strtr(base64_encode($biner), '+/', '-_'), '=');
    }
}
