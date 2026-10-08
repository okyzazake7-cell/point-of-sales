<?php

namespace App\Http\Controllers\Penyewaan;

use App\AkunAishii\AkunTertaut;
use App\AkunAishii\GagalMasukAishii;
use App\AkunAishii\KlienAishii;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Controller;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Pengelola;
use App\Models\User;
use App\Penyewaan\Penyewaan;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * "Masuk dengan akun Aishii" (AU1, dokumen 27 §4 di repo Aishii).
 *
 *   /auth/aishii                  → titik otorisasi Supabase (masuk)
 *   /auth/aishii/pengelola        → titik otorisasi (pintu pengelola, AU5)
 *   /auth/aishii/kembali          ← kode → sub → toko → sesi Laravel biasa,
 *                                   atau sub → baris `pengelola` (AU5)
 *   /auth/aishii/konfirmasi/mulai → titik otorisasi (konfirmasi `step_up`)
 *   /auth/aishii/konfirmasi       ← kode → `auth.password_confirmed_at`
 *
 * Kedua alamat balik WAJIB didaftarkan persis begitu di klien OAuth Supabase
 * (langkah pemilik P17). Alamat balik berakhiran `/konfirmasi` dikenali
 * halaman persetujuan Aishii sebagai permintaan konfirmasi: yang sandinya
 * tidak dimasukkan dalam lima menit terakhir ditolak di sana — dan di sini
 * `auth_time`-nya diperiksa lagi, sebab pagar di peramban bukan pagar.
 */
class MasukAishiiController extends Controller
{
    private const BEKAL = 'akun_aishii.bekal';

    private const IDENTITAS = 'akun_aishii.identitas';

    /** Detik bekal otorisasi dan identitas /daftar bertahan di sesi. */
    private const UMUR_BEKAL = 600;

    private const UMUR_IDENTITAS = 1800;

    public function __construct(
        private readonly KlienAishii $klien,
        private readonly Penyewaan $penyewaan,
        private readonly AuditLogService $audit,
    ) {}

    public function mulai(Request $request): RedirectResponse
    {
        abort_unless($this->klien->aktif(), 404);

        return $this->keAishii($request, 'masuk', self::alamatBalik('kembali'));
    }

    /**
     * Pintu pengelola layanan (AU5, keputusan pemilik 7 Okt). Tanpa `guest`:
     * pemilik toko yang sedang masuk di tokonya boleh sekaligus pengelola —
     * keduanya penjaga yang terpisah.
     */
    public function pengelola(Request $request): RedirectResponse
    {
        abort_unless($this->klien->aktif(), 404);

        if (Auth::guard('pengelola')->check()) {
            return redirect()->route('pengelola.index');
        }

        return $this->keAishii($request, 'pengelola', self::alamatBalik('kembali'));
    }

    public function kembali(Request $request): RedirectResponse
    {
        abort_unless($this->klien->aktif(), 404);

        // Satu alamat balik untuk dua pintu — alamat yang didaftarkan di klien
        // OAuth (P17) tidak bertambah. Pintu mana yang menekan tombolnya
        // tercatat di bekal sesi, bukan di kueri yang bisa diketik siapa pun.
        if (($request->session()->get(self::BEKAL)['tujuan'] ?? null) === 'pengelola') {
            try {
                return $this->masukkanPengelola($request, $this->terima($request, 'pengelola'));
            } catch (GagalMasukAishii $e) {
                return $this->gagal($e, 'pengelola.masuk');
            }
        }

        // Pengganti middleware `guest` yang dulu berdiri di rute ini (ia kini
        // akan menghadang pemilik toko yang kembali dari pintu pengelola):
        // yang sudah masuk di tokonya tidak masuk untuk kedua kalinya.
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        try {
            return $this->masukkan($request, $this->terima($request, 'masuk'));
        } catch (GagalMasukAishii $e) {
            return $this->gagal($e, 'login');
        }
    }

    public function konfirmasiMulai(Request $request): RedirectResponse
    {
        abort_unless($this->klien->aktif(), 404);

        // Akun bersandi mengonfirmasi dengan sandinya sendiri, seperti biasa.
        if (! AkunTertaut::pengguna($request->user())) {
            return redirect()->route('password.confirm');
        }

        return $this->keAishii($request, 'konfirmasi', self::alamatBalik('konfirmasi'));
    }

    public function konfirmasi(Request $request): RedirectResponse
    {
        abort_unless($this->klien->aktif(), 404);
        $pengguna = $request->user();

        try {
            $klaim = $this->terima($request, 'konfirmasi');
            $baris = DirektoriPengguna::query()->find(DirektoriPengguna::normalkan($pengguna->email));
            if (! $baris?->aishii_sub || ! hash_equals((string) $baris->aishii_sub, (string) $klaim['sub'])) {
                throw new GagalMasukAishii(
                    'Konfirmasi harus memakai akun Aishii yang tertaut ke akun ini.',
                    'konfirmasi: sub berbeda dari tautan pengguna #'.$pengguna->getKey(),
                );
            }
            if (! $this->klien->masukMasihSegar($klaim)) {
                throw new GagalMasukAishii(
                    'Kata sandi akun Aishii belum dimasukkan lagi. Tekan "Konfirmasi dengan akun Aishii", lalu masukkan sandinya.',
                    'konfirmasi: auth_time '.json_encode($klaim['auth_time'] ?? null).' lebih tua dari batas',
                );
            }
        } catch (GagalMasukAishii $e) {
            return $this->gagal($e, 'password.confirm');
        }

        // Sama persis dengan ConfirmablePasswordController hulu — `step_up` dan
        // tutup-paksa shift kasir hanya membaca cap waktu ini.
        $request->session()->put('auth.password_confirmed_at', time());
        $tantangan = $request->session()->pull('security.step_up_context');

        $this->audit->log(
            event: 'auth.password_confirmed',
            module: 'auth',
            auditable: $pengguna,
            description: 'Konfirmasi ulang lewat akun Aishii berhasil.',
            meta: ['severity' => 'info', 'cara' => 'akun_aishii', 'challenge' => $tantangan],
        );
        $this->audit->log(
            event: 'security.privileged_action_confirmed',
            module: 'security',
            auditable: $pengguna,
            description: 'Aksi sensitif diotorisasi setelah konfirmasi lewat akun Aishii.',
            meta: ['severity' => 'high', 'cara' => 'akun_aishii', 'challenge' => $tantangan],
        );

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Identitas akun Aishii yang belum punya toko — dibaca /daftar.
     *
     * @return array{sub: string, email: string, nama: string}|null
     */
    public static function identitas(Request $request): ?array
    {
        $identitas = $request->session()->get(self::IDENTITAS);

        return is_array($identitas) && ($identitas['sampai'] ?? 0) >= time() ? $identitas : null;
    }

    public static function lupakanIdentitas(Request $request): void
    {
        $request->session()->forget(self::IDENTITAS);
    }

    /** Alamat balik yang didaftarkan — dari APP_URL, bukan dari host permintaan (Worker penerus). */
    private static function alamatBalik(string $akhiran): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/aishii/'.$akhiran;
    }

    private function keAishii(Request $request, string $tujuan, string $alamatBalik): RedirectResponse
    {
        ['alamat' => $alamat, 'bekal' => $bekal] = $this->klien->mulai($alamatBalik);
        $request->session()->put(self::BEKAL, [...$bekal, 'tujuan' => $tujuan, 'sampai' => time() + self::UMUR_BEKAL]);

        return redirect()->away($alamat);
    }

    /**
     * Jawaban Supabase → klaim token ID yang terverifikasi. Bekal di sesi
     * SEKALI PAKAI: jawaban yang sama tidak bisa diputar ulang.
     *
     * @return array<string, mixed>
     */
    private function terima(Request $request, string $tujuan): array
    {
        $bekal = $request->session()->pull(self::BEKAL);
        if (! is_array($bekal) || ($bekal['tujuan'] ?? null) !== $tujuan || ($bekal['sampai'] ?? 0) < time()) {
            throw new GagalMasukAishii(
                'Permintaan masuk ini sudah kedaluwarsa. Tekan tombolnya sekali lagi.',
                'bekal sesi tidak ada, basi, atau untuk tujuan lain',
            );
        }
        if (! hash_equals((string) $bekal['state'], (string) $request->query('state'))) {
            throw new GagalMasukAishii(KlienAishii::KALIMAT_ULANGI, 'state tidak cocok');
        }
        if ($request->filled('error')) {
            $batal = $request->query('error') === 'access_denied';
            throw new GagalMasukAishii(
                match (true) {
                    $batal && $tujuan === 'konfirmasi' => 'Konfirmasi dibatalkan — belum ada yang berubah.',
                    $batal => 'Masuk dengan akun Aishii dibatalkan.',
                    default => KlienAishii::KALIMAT_ULANGI,
                },
                'error dari penyedia: '.$request->query('error'),
            );
        }
        $kode = (string) $request->query('code');
        if ($kode === '') {
            throw new GagalMasukAishii(KlienAishii::KALIMAT_ULANGI, 'jawaban tanpa code');
        }

        return $this->klien->tukar($kode, $bekal);
    }

    /**
     * `sub` → toko → pengguna. Akun lama yang belum tertaut ditautkan SEKALI,
     * oleh pemiliknya sendiri, lewat surel yang TERVERIFIKASI di kedua sisi
     * (dokumen 27 §6) — sesudahnya `sub` yang menentukan, bukan surel.
     *
     * @param  array<string, mixed>  $klaim
     */
    private function masukkan(Request $request, array $klaim): RedirectResponse
    {
        $sub = (string) $klaim['sub'];
        $email = DirektoriPengguna::normalkan(is_string($klaim['email'] ?? null) ? $klaim['email'] : '');
        $terverifikasi = ($klaim['email_verified'] ?? false) === true;
        $tautanBaru = false;

        $baris = DirektoriPengguna::query()->where('aishii_sub', $sub)->first();

        if (! $baris && $email !== '' && ($calon = DirektoriPengguna::query()->find($email))) {
            if ($calon->aishii_sub !== null) {
                throw new GagalMasukAishii(
                    "Surel {$email} di Aishii POS sudah tertaut ke akun Aishii lain.",
                    'surel tertaut ke sub lain',
                );
            }
            if (! $terverifikasi) {
                throw self::surelBelumTerverifikasi();
            }
            $calon->forceFill(['aishii_sub' => $sub])->save();
            $baris = $calon;
            $tautanBaru = true;
        }

        if (! $baris) {
            // Belum punya toko: /daftar membuatnya untuk akun Aishii ini.
            if (! $terverifikasi || $email === '') {
                throw self::surelBelumTerverifikasi();
            }
            $request->session()->put(self::IDENTITAS, [
                'sub' => $sub,
                'email' => $email,
                'nama' => is_string($klaim['name'] ?? null) ? $klaim['name'] : '',
                'sampai' => time() + self::UMUR_IDENTITAS,
            ]);

            return redirect()->route('daftar');
        }

        $toko = $baris->toko;
        if (! $toko?->siap()) {
            throw new GagalMasukAishii(
                'Toko Anda belum bisa dibuka. Hubungi Aishii lewat WhatsApp.',
                'toko #'.$baris->toko_id.' belum siap',
            );
        }

        $this->penyewaan->masuk($toko);
        $pengguna = User::query()->find($baris->user_id);
        if (! $pengguna) {
            throw new GagalMasukAishii(KlienAishii::KALIMAT_ULANGI, 'direktori menunjuk pengguna yang tidak ada');
        }

        Auth::login($pengguna);
        $request->session()->regenerate();
        $request->session()->put('security.session_started_at', now()->timestamp);
        self::lupakanIdentitas($request);

        // H8 (dokumen 27): `auth_time` menunjuk saat sandi dimasukkan, atau
        // saat token terbit? Satu angka di log menjawabnya tanpa membuka data
        // siapa pun — angka besar sesudah lama tidak memasukkan sandi = yang
        // pertama, dan pemeriksaan konfirmasi di bawah benar-benar menjaga.
        Log::info('Masuk dengan akun Aishii', [
            'umur_auth_time_detik' => is_numeric($klaim['auth_time'] ?? null) ? time() - (int) $klaim['auth_time'] : null,
            // H10 (AV14): H8 terbukti salah (`auth_time` = cap terbit). Angka
            // besar di sini sesudah lama tidak memasukkan sandi = cap `amr`
            // token akses membawa saat sandi yang asli.
            'umur_amr_detik' => KlienAishii::umurAmr($klaim),
            'amr_token_akses' => $klaim[KlienAishii::AMR_TOKEN_AKSES] ?? null,
        ]);

        $this->audit->log(
            event: 'auth.login_succeeded',
            module: 'auth',
            auditable: $pengguna,
            description: $tautanBaru ? 'Masuk pertama lewat akun Aishii — akun tertaut.' : 'Login lewat akun Aishii berhasil.',
            meta: ['severity' => 'info', 'cara' => 'akun_aishii', 'tautan_baru' => $tautanBaru],
        );

        return redirect()->intended(AuthenticatedSessionController::halamanAwal($pengguna));
    }

    /**
     * `sub` → baris `pengelola` (AU5). SIAPA pengelola tetap daftar milik POS
     * — baris yang dilahirkan `POS_PENGELOLA_SUREL` — bukan pengelola Aishii
     * Bazar: produk tidak pernah membaca basis data Aishii (kontrak butir 5
     * dokumen 27). Masuk pertama mengunci baris itu ke `sub`-nya lewat surel
     * yang terverifikasi, persis seperti akun toko lama di `masukkan()`.
     *
     * @param  array<string, mixed>  $klaim
     */
    private function masukkanPengelola(Request $request, array $klaim): RedirectResponse
    {
        $sub = (string) $klaim['sub'];
        $email = DirektoriPengguna::normalkan(is_string($klaim['email'] ?? null) ? $klaim['email'] : '');
        $tautanBaru = false;

        $pengelola = Pengelola::query()->where('aishii_sub', $sub)->first();

        if (! $pengelola && $email !== '' && ($calon = Pengelola::query()->where('email', $email)->first())) {
            if ($calon->aishii_sub !== null) {
                throw new GagalMasukAishii(
                    "Surel {$email} sudah tertaut ke akun Aishii lain sebagai pengelola.",
                    'pengelola: surel tertaut ke sub lain',
                );
            }
            if (($klaim['email_verified'] ?? false) !== true) {
                throw self::surelBelumTerverifikasi();
            }
            $calon->forceFill(['aishii_sub' => $sub])->save();
            $pengelola = $calon;
            $tautanBaru = true;
        }

        if (! $pengelola) {
            throw new GagalMasukAishii(
                'Akun Aishii ini bukan pengelola Aishii POS.',
                'pengelola: sub dan surel tidak terdaftar',
            );
        }

        Auth::guard('pengelola')->login($pengelola);
        $request->session()->regenerate();

        // Pengelola biasanya orang PERTAMA yang masuk sesudah P17 — angka ini
        // menjawab H8 tanpa menunggu pemilik toko mana pun.
        Log::info('Pengelola masuk dengan akun Aishii', [
            'pengelola_id' => $pengelola->getKey(),
            'tautan_baru' => $tautanBaru,
            'umur_auth_time_detik' => is_numeric($klaim['auth_time'] ?? null) ? time() - (int) $klaim['auth_time'] : null,
            'umur_amr_detik' => KlienAishii::umurAmr($klaim),
            'amr_token_akses' => $klaim[KlienAishii::AMR_TOKEN_AKSES] ?? null,
        ]);

        return redirect()->route('pengelola.index');
    }

    private static function surelBelumTerverifikasi(): GagalMasukAishii
    {
        return new GagalMasukAishii(
            'Surel akun Aishii Anda belum terverifikasi. Buka surel konfirmasi dari Aishii dulu, lalu coba lagi.',
            'email_verified bukan true',
        );
    }

    private function gagal(GagalMasukAishii $e, string $rute): RedirectResponse
    {
        Log::warning('Masuk dengan akun Aishii ditolak', ['alasan' => $e->alasan]);

        return redirect()->route($rute)->withErrors(['aishii' => $e->getMessage()]);
    }
}
