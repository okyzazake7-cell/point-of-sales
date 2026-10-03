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
 */
class PendaftaranToko
{
    public function __construct(
        private readonly Penyewaan $penyewaan,
        private readonly PenyediaBasisData $penyedia,
    ) {}

    /**
     * @param  array{nama_toko: string, jenis_usaha: string, nama: string, email: string, password: string, telepon?: string|null}  $isian
     * @return array{0: Toko, 1: User}
     */
    public function daftarkan(array $isian): array
    {
        $email = DirektoriPengguna::normalkan($isian['email']);

        if (DirektoriPengguna::query()->whereKey($email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Surel ini sudah terdaftar di Aishii POS. Masuk, atau pakai surel lain.',
            ]);
        }

        $toko = Toko::query()->create([
            'kode' => Toko::kodeBaru($isian['nama_toko']),
            'nama' => $isian['nama_toko'],
            'email_pemilik' => $email,
            'status_basis_data' => 'menyiapkan',
            'kursi_outlet' => 1,
        ]);

        $this->penyedia->siapkan($toko);

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

        return [$toko->refresh(), $pengguna];
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
