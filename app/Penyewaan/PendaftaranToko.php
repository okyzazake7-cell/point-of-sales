<?php

namespace App\Penyewaan;

use App\Http\Controllers\SetupController;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Toko;
use App\Models\Setting;
use App\Models\User;
use App\Services\SetupService;
use Illuminate\Validation\ValidationException;

/**
 * Toko baru lewat /daftar (AS7): baris pusat → basis data toko → isi wizard
 * `/setup` hulu (SetupService, langkah yang SAMA persis) → direktori surel.
 *
 * Tanpa masa coba (keputusan pemilik 3 Okt): `aktif_sampai` dibiarkan
 * kosong, jadi toko lahir terkunci sampai tagihan pertamanya lunas.
 *
 * Dua jalan, langkah yang sama (AU6, dokumen 28 di repo Aishii):
 * `daftarkan()` sekaligus — uji dan baris perintah; `mulai()` + `lanjut()`
 * bertahap — /daftar di server, sebab satu pendaftaran = 427 DDL dan di TiDB
 * satu permintaan yang mengerjakan semuanya tidak pernah berujung.
 */
class PendaftaranToko
{
    /** Urutan tahap pendaftaran bertahap; `selesai` = pemiliknya boleh masuk. */
    public const TAHAP = ['buat', 'migrasi', 'tanam', 'setup', 'selesai'];

    public function __construct(
        private readonly Penyewaan $penyewaan,
        private readonly PenyediaBasisData $penyedia,
    ) {}

    /**
     * `aishii_sub` (AU1): toko yang lahir dari akun Aishii tertaut ke akun
     * itu sejak detik pertama — pemiliknya tidak pernah punya sandi POS (D2).
     *
     * @param  array{nama_toko: string, jenis_usaha: string, nama: string, email: string, password: string, telepon?: string|null, aishii_sub?: string|null}  $isian
     * @return array{0: Toko, 1: User}
     */
    public function daftarkan(array $isian): array
    {
        $this->pastikanBelumTerdaftar($isian);
        $toko = $this->lahirkan($isian);
        $this->penyedia->siapkan($toko);
        $pengguna = $this->setup($toko, $isian);

        return [$toko->refresh(), $pengguna];
    }

    /**
     * Langkah pertama jalan bertahap: baris toko `menyiapkan` saja — basis
     * datanya disiapkan `lanjut()`, sedikit demi sedikit.
     *
     * Satu orang, satu toko: toko BELUM JADI milik surel ini diulang dari
     * awal (basis datanya belum pernah dipakai siapa pun), tidak dikembari.
     * Yang sudah `siap` tidak pernah disentuh — `email_pemilik` tidak ikut
     * berganti saat surel pemiliknya diganti.
     *
     * @param  array{nama_toko: string, jenis_usaha: string, nama: string, email: string, password: string, telepon?: string|null, aishii_sub?: string|null}  $isian
     */
    public function mulai(array $isian): Toko
    {
        $this->pastikanBelumTerdaftar($isian);

        $toko = Toko::query()
            ->where('email_pemilik', DirektoriPengguna::normalkan($isian['email']))
            ->where('status_basis_data', '!=', 'siap')
            ->latest('id')
            ->first();

        if (! $toko) {
            return $this->lahirkan($isian);
        }

        $this->ulangi($toko);
        $toko->forceFill(['nama' => $isian['nama_toko']])->save();

        return $toko;
    }

    /**
     * Menjalankan tahap demi tahap sampai `$sampai` (microtime) lewat —
     * paling sedikit SATU langkah, jadi tiap panggilan pasti maju. Berkas
     * migrasi yang sedang berjalan selalu diselesaikan dulu.
     *
     * @return array{tahap: string, langkah: int, dari: int}
     */
    public function lanjut(Toko $toko, array $isian, string $tahap, float $sampai): array
    {
        do {
            $tahap = match ($tahap) {
                'buat' => $this->tahapBuat($toko),
                'migrasi' => $this->penyedia->migrasiSatuBerkas($toko) ? 'migrasi' : 'tanam',
                'tanam' => $this->tahapTanam($toko),
                'setup' => $this->tahapSetup($toko, $isian),
            };
        } while ($tahap !== 'selesai' && microtime(true) < $sampai);

        return ['tahap' => $tahap, 'langkah' => $this->langkah($toko, $tahap), 'dari' => $this->dari()];
    }

    /** Toko yang gagal atau tersendat disiapkan ulang dari basis data kosong. */
    public function ulangi(Toko $toko): void
    {
        if ($toko->siap()) {
            throw new \LogicException("Toko #{$toko->getKey()} sudah siap — basis datanya tidak boleh dibuang.");
        }

        $this->penyedia->hapus($toko);
        $toko->forceFill(['status_basis_data' => 'menyiapkan'])->save();
    }

    /** Penyebut "langkah X dari Y": buat + tiap berkas migrasi + tanam + setup. */
    public function dari(): int
    {
        return $this->penyedia->jumlahBerkasMigrasi() + 3;
    }

    public function langkah(Toko $toko, string $tahap): int
    {
        return match ($tahap) {
            'buat' => 0,
            'migrasi' => 1 + $this->penyedia->jumlahMigrasiBerjalan($toko),
            'tanam' => $this->dari() - 2,
            'setup' => $this->dari() - 1,
            'selesai' => $this->dari(),
        };
    }

    private function tahapBuat(Toko $toko): string
    {
        $this->penyedia->buat($toko);

        return 'migrasi';
    }

    private function tahapTanam(Toko $toko): string
    {
        $this->penyedia->tanam($toko);

        return 'setup';
    }

    private function tahapSetup(Toko $toko, array $isian): string
    {
        $this->setup($toko, $isian);

        return 'selesai';
    }

    /** @param  array{email: string, aishii_sub?: string|null}  $isian */
    private function pastikanBelumTerdaftar(array $isian): void
    {
        $sub = $isian['aishii_sub'] ?? null;

        if (DirektoriPengguna::query()->whereKey(DirektoriPengguna::normalkan($isian['email']))->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Surel ini sudah terdaftar di Aishii POS. Masuk, atau pakai surel lain.',
            ]);
        }

        // Satu akun Aishii, satu toko — satu akun di banyak toko di luar AU1.
        if ($sub !== null && DirektoriPengguna::query()->where('aishii_sub', $sub)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Akun Aishii ini sudah punya toko di Aishii POS. Masuk saja.',
            ]);
        }
    }

    /** @param  array{nama_toko: string, email: string}  $isian */
    private function lahirkan(array $isian): Toko
    {
        return Toko::query()->create([
            'kode' => Toko::kodeBaru($isian['nama_toko']),
            'nama' => $isian['nama_toko'],
            'email_pemilik' => DirektoriPengguna::normalkan($isian['email']),
            'status_basis_data' => 'menyiapkan',
            'kursi_outlet' => 1,
        ]);
    }

    /**
     * Wizard `/setup` hulu (satu transaksi) → toko `siap` → tautan akun
     * Aishii di direktori. `siap` SESUDAH pemiliknya ada: toko siap tanpa
     * pemilik adalah toko yang tidak bisa dimasuki siapa pun.
     */
    private function setup(Toko $toko, array $isian): User
    {
        $email = DirektoriPengguna::normalkan($isian['email']);
        $sub = $isian['aishii_sub'] ?? null;

        $pengguna = $this->penyewaan->denganToko($toko, fn () => app(SetupService::class)->run([
            'store_name' => $isian['nama_toko'],
            'store_address' => '',
            'store_phone' => $isian['telepon'] ?? '',
            'store_email' => $email,
            'business_type' => $isian['jenis_usaha'],
            'categories' => $this->kategoriAwal($isian['jenis_usaha']),
            'user_name' => $isian['nama'],
            'user_email' => $email,
            'password' => $isian['password'],
            // Gudang PUSAT yang ditanam DatabaseSeeder — persis jalur wizard.
            'warehouse_id' => Setting::get('setup_warehouse_id'),
            'warehouse_code' => 'PUSAT',
            'warehouse_name' => 'Gudang Pusat',
            // SATU outlet berjualan: kursi pertama yang dibayar. Outlet
            // berikutnya ditambah sesudah toko aktif, dan menambah kursi.
            'branches' => [[
                'outlet_code' => 'UTAMA',
                'outlet_name' => 'Toko Utama',
                'warehouse_code' => 'UTAMA',
                'warehouse_name' => 'Gudang Toko Utama',
                'address' => null,
                'phone' => $isian['telepon'] ?? null,
            ]],
        ]));

        $toko->forceFill(['status_basis_data' => 'siap'])->save();

        // Baris direktorinya ditulis SinkronDirektori sesudah transaksi toko
        // jadi; tautannya menyusul di baris yang sama.
        if ($sub !== null) {
            DirektoriPengguna::query()->whereKey($email)->update(['aishii_sub' => $sub]);
        }

        return $pengguna;
    }

    /**
     * Kategori awal dibaca dari kamus bahasa Indonesia yang SAMA dengan yang
     * ditampilkan wizard `/setup` — satu sumber, bukan daftar kedua.
     *
     * @return list<string>
     */
    public function kategoriAwal(string $jenis): array
    {
        $kamus = json_decode((string) file_get_contents(resource_path('js/i18n/locales/id.json')), true);
        $nama = $kamus['setup']['categoryOptions'][$jenis] ?? [];

        return array_values(array_map(
            fn (string $kunci) => $nama[$kunci] ?? $kunci,
            SetupController::BUSINESS_TYPES[$jenis] ?? ['general'],
        ));
    }
}
