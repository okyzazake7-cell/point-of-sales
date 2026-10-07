<?php

namespace App\Http\Controllers\Penyewaan;

use App\AkunAishii\KlienAishii;
use App\Http\Controllers\Controller;
use App\Http\Controllers\SetupController;
use App\Langganan\Harga;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Toko;
use App\Models\User;
use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\Penyewaan;
use App\Support\BotGuard;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * /daftar — toko baru Aishii POS (AS7). Satu layar; sesudahnya pemilik
 * langsung masuk dan mendarat di Langganan, sebab tanpa masa coba tokonya
 * lahir terkunci sampai tagihan pertama lunas.
 *
 * Bertahap (AU6, dokumen 28 di repo Aishii): kiriman formulir hanya
 * melahirkan baris toko; basis datanya disiapkan `/daftar/lanjut` lewat
 * permintaan-permintaan pendek yang digerakkan halaman kemajuan. Satu
 * permintaan yang mengerjakan semuanya (427 DDL) tidak pernah berujung di
 * TiDB: Cloudflare memutusnya di 100 detik, dan CPU Cloud Run ditahan.
 */
class DaftarController extends Controller
{
    /** Bekal pendaftaran yang sedang berjalan, di sesi pemiliknya. */
    private const PENDAFTARAN = 'penyewaan.pendaftaran';

    public function create(Request $request, KlienAishii $klien): Response
    {
        $identitas = $klien->aktif() ? MasukAishiiController::identitas($request) : null;

        return Inertia::render('Penyewaan/Daftar', [
            'jenisUsaha' => array_keys(SetupController::BUSINESS_TYPES),
            'hargaPerOutlet' => Harga::perOutlet(),
            'botGuard' => BotGuard::payload(),
            // AU1: hidup → toko lahir dari akun Aishii (tanpa surel dan sandi
            // POS); tanpa identitas, layar ini hanya menawarkan tombol masuknya.
            'akunAishii' => $klien->aktif() ? [
                'email' => $identitas['email'] ?? null,
                'nama' => $identitas['nama'] ?? null,
                'masuk' => route('aishii.masuk'),
            ] : null,
        ]);
    }

    public function store(Request $request, PendaftaranToko $pendaftaran, KlienAishii $klien): RedirectResponse
    {
        $label = [
            'nama_toko' => 'nama toko',
            'jenis_usaha' => 'jenis usaha',
            'nama' => 'nama Anda',
            'email' => 'surel',
            'password' => 'kata sandi',
        ];
        $aturan = [
            'nama_toko' => ['required', 'string', 'min:3', 'max:120'],
            'jenis_usaha' => ['required', 'string', 'in:'.implode(',', array_keys(SetupController::BUSINESS_TYPES))],
            'nama' => ['required', 'string', 'max:120'],
            'telepon' => ['nullable', 'string', 'max:30'],
        ];

        if ($klien->aktif()) {
            $identitas = MasukAishiiController::identitas($request);
            if (! $identitas) {
                return redirect()->route('daftar')->withErrors([
                    'aishii' => 'Masuk dengan akun Aishii dulu — toko Anda dibuat untuk akun itu.',
                ]);
            }
            // Surel dari akun Aishii yang terverifikasi, BUKAN dari formulir;
            // sandinya acak dan tidak pernah diketahui siapa pun (D2).
            $isian = [
                ...$request->validate($aturan, [], $label),
                'email' => $identitas['email'],
                'password' => Str::password(40),
                'aishii_sub' => $identitas['sub'],
            ];
        } else {
            $isian = $request->validate([
                ...$aturan,
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ], [], $label);
        }

        // Kunci yang SAMA dengan /daftar/lanjut: kiriman ulang tidak boleh
        // membuang basis data yang sedang dikerjakan jendela lain.
        try {
            $toko = self::kunci($isian['email'])->block(30, fn () => $pendaftaran->mulai($isian));
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'email' => 'Toko untuk surel ini sedang disiapkan di jendela lain. Tunggu sampai selesai di sana.',
            ]);
        }

        $request->session()->put(self::PENDAFTARAN, [
            'toko_id' => $toko->getKey(),
            // Sandi tidak pernah polos di tabel sesi: SetupService meng-hash
            // sendiri, jadi yang disimpan dienkripsi APP_KEY, bukan di-hash.
            'isian' => [...$isian, 'password' => Crypt::encryptString($isian['password'])],
            'tahap' => 'buat',
            'mulai' => microtime(true),
        ]);

        return redirect()->route('daftar.menyiapkan');
    }

    /** Halaman kemajuan — ia yang memanggil /daftar/lanjut berulang-ulang. */
    public function menyiapkan(Request $request, PendaftaranToko $pendaftaran): Response|RedirectResponse
    {
        $bekal = $request->session()->get(self::PENDAFTARAN);
        $toko = self::tokoBelumJadi($request, $bekal);
        if (! $toko instanceof Toko) {
            return redirect()->route($toko);
        }

        return Inertia::render('Penyewaan/Menyiapkan', [
            'namaToko' => $toko->nama,
            // Basis data yang sedang dibuat ulang jendela lain tidak terbaca
            // sesaat — halamannya tetap tampil, kemajuannya menyusul.
            'langkah' => $bekal['tahap'] === 'gagal' ? 0 : rescue(fn () => $pendaftaran->langkah($toko, $bekal['tahap']), 0, false),
            'dari' => $pendaftaran->dari(),
            'gagal' => $bekal['tahap'] === 'gagal',
            'detikBerjalan' => (int) (microtime(true) - $bekal['mulai']),
        ]);
    }

    /**
     * Satu permintaan pendek: langkah-langkah sampai anggarannya habis.
     * `selesai` = pemiliknya sudah dimasukkan; halaman tinggal pindah.
     */
    public function lanjut(Request $request, PendaftaranToko $pendaftaran, Penyewaan $penyewaan): JsonResponse
    {
        $bekal = $request->session()->get(self::PENDAFTARAN);
        $toko = self::tokoBelumJadi($request, $bekal);
        if (! $toko instanceof Toko) {
            return response()->json(['hilang' => true, 'menuju' => route($toko)], 409);
        }

        $kunci = self::kunci($toko->email_pemilik);
        if (! $kunci->get()) {
            return response()->json(['sibuk' => true, 'langkah' => null, 'dari' => $pendaftaran->dari()]);
        }

        try {
            if ($bekal['tahap'] === 'gagal') {
                return response()->json(['gagal' => true, 'langkah' => 0, 'dari' => $pendaftaran->dari()]);
            }

            $isian = [...$bekal['isian'], 'password' => Crypt::decryptString($bekal['isian']['password'])];
            $sampai = microtime(true) + (float) config('penyewaan.anggaran_langkah_detik');

            try {
                $hasil = $pendaftaran->lanjut($toko, $isian, $bekal['tahap'], $sampai);
            } catch (Throwable $e) {
                report($e);
                $toko->forceFill(['status_basis_data' => 'gagal'])->save();
                $request->session()->put(self::PENDAFTARAN.'.tahap', 'gagal');

                return response()->json(['gagal' => true, 'langkah' => 0, 'dari' => $pendaftaran->dari()]);
            }

            if ($hasil['tahap'] !== 'selesai') {
                $request->session()->put(self::PENDAFTARAN.'.tahap', $hasil['tahap']);

                return response()->json(['langkah' => $hasil['langkah'], 'dari' => $hasil['dari']]);
            }

            // Lamanya di basis data sungguhan — angka yang belum pernah ada
            // sebelum AU6, tanpa membuka data siapa pun.
            Log::info('Toko siap', [
                'toko_id' => $toko->getKey(),
                'detik' => (int) round(microtime(true) - $bekal['mulai']),
                'langkah' => $hasil['dari'],
            ]);

            $penyewaan->masuk($toko);
            Auth::login(User::query()->where('email', DirektoriPengguna::normalkan($isian['email']))->firstOrFail());
            $request->session()->forget(self::PENDAFTARAN);
            $request->session()->regenerate();
            $request->session()->put('security.session_started_at', now()->timestamp);
            MasukAishiiController::lupakanIdentitas($request);
            $request->session()->flash('success', "Toko {$toko->nama} siap. Bayar tagihan pertama untuk mulai berjualan.");

            return response()->json([
                'selesai' => true,
                'langkah' => $hasil['dari'],
                'dari' => $hasil['dari'],
                'menuju' => route('langganan.index'),
            ]);
        } finally {
            $kunci->release();
        }
    }

    /** "Ulangi dari awal": basis data toko yang gagal dibuang lalu dibuat lagi. */
    public function ulangi(Request $request, PendaftaranToko $pendaftaran): JsonResponse
    {
        $bekal = $request->session()->get(self::PENDAFTARAN);
        $toko = self::tokoBelumJadi($request, $bekal);
        if (! $toko instanceof Toko) {
            return response()->json(['hilang' => true, 'menuju' => route($toko)], 409);
        }

        try {
            self::kunci($toko->email_pemilik)->block(30, fn () => $pendaftaran->ulangi($toko));
        } catch (LockTimeoutException) {
            return response()->json(['sibuk' => true, 'langkah' => null, 'dari' => $pendaftaran->dari()]);
        }
        $request->session()->put(self::PENDAFTARAN.'.tahap', 'buat');

        return response()->json(['langkah' => 0, 'dari' => $pendaftaran->dari()]);
    }

    /**
     * Toko dari bekal sesi bila masih disiapkan; bila tidak, nama rute
     * tujuannya. Toko yang SUDAH jadi — diselesaikan jendela atau perangkat
     * lain — dimasuki lewat pintu masuk biasa, tidak disiapkan lagi.
     */
    private static function tokoBelumJadi(Request $request, mixed $bekal): Toko|string
    {
        $toko = is_array($bekal) ? Toko::query()->find($bekal['toko_id'] ?? null) : null;
        if ($toko && ! $toko->siap()) {
            return $toko;
        }

        $request->session()->forget(self::PENDAFTARAN);

        return $toko ? 'login' : 'daftar';
    }

    /** Satu kunci per surel pemilik di tembolok pusat — dua jendela tidak pernah bekerja bersamaan. */
    private static function kunci(string $email): Lock
    {
        return Cache::lock('pendaftaran-toko:'.sha1(DirektoriPengguna::normalkan($email)), 180);
    }
}
