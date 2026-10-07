<?php

namespace Tests\Feature\BanyakToko;

use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Toko;
use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\PenyediaBasisData;
use App\Penyewaan\Penyewaan;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\BanyakTokoTestCase;

/**
 * Pendaftaran toko bertahap (AU6, dokumen 28 di repo Aishii). Satu
 * pendaftaran = 427 DDL; di TiDB satu permintaan yang mengerjakan semuanya
 * tidak pernah berujung (Cloudflare memutus di 100 detik, CPU Cloud Run
 * ditahan sesudahnya). Kini halaman kemajuan menggerakkannya lewat
 * permintaan-permintaan pendek.
 *
 * Anggaran 0 detik = tiap panggilan tepat SATU langkah — bentuk terburuk
 * yang membuktikan tiap langkah memang berdiri sendiri.
 */
class DaftarBertahapTest extends BanyakTokoTestCase
{
    private const SUREL = 'cempaka@contoh.id';

    protected function setUp(): void
    {
        parent::setUp();

        config(['penyewaan.anggaran_langkah_detik' => 0]);
        // Anggaran 0 = ±105 panggilan dalam sedetik; di server anggarannya
        // 20 detik, jadi panggilannya hanya beberapa per menit dan pembatas
        // 60/menit tidak pernah tersentuh.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_kiriman_formulir_hanya_melahirkan_baris_toko(): void
    {
        $this->kirimDaftar()->assertSessionHasNoErrors()->assertRedirect(route('daftar.menyiapkan'));

        $toko = Toko::query()->sole();
        $this->assertSame('menyiapkan', $toko->status_basis_data);
        // Tidak satu perintah basis data toko pun di permintaan formulir.
        $this->assertFileDoesNotExist(app(Penyewaan::class)->lokasiBasisData($toko));
        $this->assertGuest();
        $this->assertNull(DirektoriPengguna::query()->find(self::SUREL));

        // Sandi tidak pernah polos di tabel sesi.
        $tersimpan = session('penyewaan.pendaftaran.isian.password');
        $this->assertNotSame('sandi-rahasia-1', $tersimpan);
        $this->assertSame('sandi-rahasia-1', Crypt::decryptString($tersimpan));

        $this->get('/daftar/menyiapkan')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Penyewaan/Menyiapkan')
            ->where('namaToko', 'Toko Cempaka')
            ->where('langkah', 0)
            ->where('dari', $this->dari())
            ->where('gagal', false));
    }

    public function test_langkah_pendek_maju_sampai_toko_siap_lalu_pemiliknya_masuk(): void
    {
        Log::spy();
        $this->kirimDaftar();

        $deret = [];
        do {
            $jawaban = $this->postJson('/daftar/lanjut')->assertOk();
            $deret[] = $jawaban->json('langkah');
            $this->assertSame($this->dari(), $jawaban->json('dari'));
            $this->assertLessThan(500, count($deret), 'pendaftaran tidak pernah selesai');
        } while (! $jawaban->json('selesai'));

        // Satu langkah per panggilan: buat, tiap berkas migrasi, peralihan ke
        // tanam, tanam, setup.
        $this->assertSame($this->dari() + 1, count($deret));
        $this->assertSame(1, $deret[0]);
        $this->assertSame($deret, array_values(collect($deret)->sort()->all()), 'kemajuan tidak boleh mundur');
        // Tiap langkah terbaca satu per satu — termasuk tiap berkas migrasi.
        $this->assertSame(range(1, $this->dari()), array_values(array_unique($deret)));
        $this->assertSame($this->dari(), end($deret));
        $jawaban->assertJsonPath('menuju', route('langganan.index'));

        $toko = Toko::query()->sole();
        $this->assertSame('siap', $toko->status_basis_data);
        $this->assertAuthenticated();
        $this->assertSame($toko->id, session('toko_id'));
        $this->assertNull(session('penyewaan.pendaftaran'));
        $this->get('/dashboard/langganan')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('toko.nama', 'Toko Cempaka'));

        Log::shouldHaveReceived('info')->with('Toko siap', Mockery::on(
            fn (array $konteks) => $konteks['toko_id'] === $toko->id && $konteks['langkah'] === $this->dari(),
        ));
    }

    public function test_kiriman_ulang_mengulang_toko_yang_sama_tanpa_toko_kedua(): void
    {
        $this->kirimDaftar();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/daftar/lanjut')->assertOk();
        }
        $toko = Toko::query()->sole();
        $this->assertSame(4, app(PenyediaBasisData::class)->jumlahMigrasiBerjalan($toko));

        // Halaman tertutup; orangnya kembali dan mengirim formulir lagi.
        $this->flushSession();
        $this->kirimDaftar(['nama_toko' => 'Toko Cempaka Baru'])->assertRedirect(route('daftar.menyiapkan'));

        $this->assertSame(1, Toko::query()->count());
        $this->assertSame('Toko Cempaka Baru', $toko->fresh()->nama);
        // Basis data setengah jadi dibuang — mulai lagi dari kosong.
        $this->assertFileDoesNotExist(app(Penyewaan::class)->lokasiBasisData($toko));

        $this->selesaikanPendaftaran()->assertJsonPath('selesai', true);
        $this->assertSame(1, Toko::query()->count());
        $this->assertSame('siap', $toko->fresh()->status_basis_data);
    }

    public function test_toko_yang_sudah_siap_tidak_pernah_diulang(): void
    {
        [$toko] = $this->buatToko('Toko Anggrek', self::SUREL);
        $basisData = app(Penyewaan::class)->lokasiBasisData($toko);

        $this->kirimDaftar()->assertSessionHasErrors('email');

        $this->assertSame(1, Toko::query()->count());
        $this->assertSame('siap', $toko->fresh()->status_basis_data);
        $this->assertFileExists($basisData);

        $this->expectException(\LogicException::class);
        app(PendaftaranToko::class)->ulangi($toko->fresh());
    }

    public function test_dua_jendela_tidak_pernah_bekerja_bersamaan(): void
    {
        $this->kirimDaftar();
        $toko = Toko::query()->sole();

        $kunci = Cache::lock('pendaftaran-toko:'.sha1(self::SUREL), 180);
        $this->assertTrue($kunci->get());

        $this->postJson('/daftar/lanjut')->assertOk()
            ->assertJsonPath('sibuk', true)
            ->assertJsonPath('langkah', null);
        $this->assertFileDoesNotExist(app(Penyewaan::class)->lokasiBasisData($toko));

        $kunci->release();
        $this->postJson('/daftar/lanjut')->assertOk()->assertJsonPath('langkah', 1);
    }

    public function test_langkah_yang_gagal_berhenti_lalu_bisa_diulang_dari_awal(): void
    {
        $this->app->instance(PenyediaBasisData::class, new class(app(Penyewaan::class)) extends PenyediaBasisData
        {
            public int $panggilan = 0;

            public function migrasiSatuBerkas(Toko $toko): bool
            {
                if (++$this->panggilan === 3) {
                    throw new RuntimeException('instans mati di tengah migrasi');
                }

                return parent::migrasiSatuBerkas($toko);
            }
        });

        $this->kirimDaftar();
        $gagal = $this->selesaikanPendaftaran();
        $gagal->assertJsonPath('gagal', true);

        $toko = Toko::query()->sole();
        $this->assertSame('gagal', $toko->status_basis_data);
        // Yang gagal tetap gagal sampai diulang — tidak diam-diam melanjutkan.
        $this->postJson('/daftar/lanjut')->assertJsonPath('gagal', true);
        $this->get('/daftar/menyiapkan')->assertInertia(fn (AssertableInertia $p) => $p->where('gagal', true));

        $this->postJson('/daftar/ulangi')->assertOk()->assertJsonPath('langkah', 0);
        $this->assertSame('menyiapkan', $toko->fresh()->status_basis_data);
        $this->assertFileDoesNotExist(app(Penyewaan::class)->lokasiBasisData($toko));

        $this->selesaikanPendaftaran()->assertJsonPath('selesai', true);
        $this->assertSame('siap', $toko->fresh()->status_basis_data);
        $this->assertAuthenticated();
    }

    public function test_tanam_yang_terputus_tidak_meninggalkan_separuh(): void
    {
        $this->kirimDaftar();
        $toko = Toko::query()->sole();
        // Basis datanya lahir di panggilan pertama; migrasi diselesaikan dulu,
        // lalu penanaman diputus di izin kelima.
        $this->postJson('/daftar/lanjut')->assertOk()->assertJsonPath('langkah', 1);
        $penyedia = app(PenyediaBasisData::class);
        while ($penyedia->jumlahMigrasiBerjalan($toko) < $penyedia->jumlahBerkasMigrasi()) {
            $this->postJson('/daftar/lanjut')->assertOk();
        }
        $n = 0;
        Permission::created(function () use (&$n) {
            if (++$n === 5) {
                throw new RuntimeException('putus di tengah tanam');
            }
        });

        $this->selesaikanPendaftaran()->assertJsonPath('gagal', true);

        $this->assertSame(0, app(Penyewaan::class)->denganToko(
            $toko,
            fn () => DB::connection(app(Penyewaan::class)->koneksiToko())->table('permissions')->count(),
        ));
    }

    public function test_toko_yang_diselesaikan_jendela_lain_dimasuki_lewat_pintu_masuk(): void
    {
        $this->kirimDaftar();
        $toko = Toko::query()->sole();
        // Perangkat lain menuntaskannya lebih dulu.
        $toko->forceFill(['status_basis_data' => 'siap'])->save();

        $this->postJson('/daftar/lanjut')->assertStatus(409)->assertJsonPath('menuju', route('login'));
        $this->assertNull(session('penyewaan.pendaftaran'));
        $this->get('/daftar/menyiapkan')->assertRedirect(route('daftar'));
    }

    public function test_kemajuan_yang_tak_terbaca_tidak_menjatuhkan_halaman(): void
    {
        $this->kirimDaftar();
        $this->postJson('/daftar/lanjut')->assertOk()->assertJsonPath('langkah', 1);
        // Jendela lain sedang membuang dan membuat ulang basis datanya.
        unlink(app(Penyewaan::class)->lokasiBasisData(Toko::query()->sole()));

        $this->get('/daftar/menyiapkan')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('langkah', 0)->where('gagal', false));
    }

    public function test_tanpa_bekal_pendaftaran_kembali_ke_formulir(): void
    {
        $this->get('/daftar/menyiapkan')->assertRedirect(route('daftar'));
        $this->postJson('/daftar/lanjut')->assertStatus(409)->assertJsonPath('menuju', route('daftar'));
        $this->postJson('/daftar/ulangi')->assertStatus(409);
    }

    private function kirimDaftar(array $timpa = []): TestResponse
    {
        return $this->post('/daftar', [
            'nama_toko' => 'Toko Cempaka',
            'jenis_usaha' => 'retail',
            'nama' => 'Bu Cempaka',
            'email' => self::SUREL,
            'password' => 'sandi-rahasia-1',
            'password_confirmation' => 'sandi-rahasia-1',
            ...$this->botGuardPayload(),
            ...$timpa,
        ]);
    }

    private function dari(): int
    {
        return app(PendaftaranToko::class)->dari();
    }
}
