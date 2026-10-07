<?php

namespace App\Http\Middleware\Penyewaan;

use App\AkunAishii\AkunTertaut;
use App\Http\Responses\ApiResponse;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Toko;
use App\Penyewaan\Penyewaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * API `/api/v1` dalam mode banyak toko: toko dibaca dari kepala `X-Toko`
 * (kode toko), atau dari surel saat login API. Webhook gerbang bayar milik
 * toko memakai jalur `/api/t/{toko}/webhooks/…` (KenaliTokoDariJalur).
 *
 * Pendaftaran lewat API ditutup: toko baru lahir hanya lewat /daftar, yang
 * membuat basis datanya. Pendaftar API tanpa toko tidak punya tempat.
 */
class KenaliTokoApi
{
    public function __construct(private readonly Penyewaan $penyewaan) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->penyewaan->aktif() || $this->penyewaan->toko()) {
            return $next($request);
        }

        // Webhook gerbang bayar berjalur /api/t/{toko}/… dikenali dari jalurnya;
        // harga (dibaca halaman /pos Aishii) milik layanan, bukan toko.
        if ($request->route()?->hasParameter('toko') || $request->route()?->getName() === 'api.harga') {
            return $next($request);
        }

        $nama = $request->route()?->getName();

        if ($nama === 'api.auth.register') {
            return response()->json([
                'message' => 'Pendaftaran Aishii POS hanya lewat halaman /daftar.',
            ], Response::HTTP_FORBIDDEN);
        }

        $kode = trim((string) $request->header('X-Toko'));
        $toko = $kode !== ''
            ? Toko::query()->where('kode', $kode)->first()
            : ($nama === 'api.auth.login' ? DirektoriPengguna::tokoUntuk($request->input('email')) : null);

        if ($toko?->siap()) {
            $this->penyewaan->masuk($toko);

            // D2 (AU1): akun yang tertaut ke akun Aishii tidak mendapat token
            // API lewat sandi. Kalimatnya sama dengan sandi salah di
            // AuthController hulu — surel tidak boleh bisa ditebak.
            if ($nama === 'api.auth.login' && AkunTertaut::tertaut($request->input('email'))) {
                throw ValidationException::withMessages([
                    'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
                ]);
            }

            $jawaban = $next($request);

            // Klien API harus tahu kode tokonya untuk kepala X-Toko berikutnya;
            // disisipkan di sini supaya pengendali login hulu tidak disunting.
            if ($nama === 'api.auth.login' && $jawaban instanceof JsonResponse && $jawaban->isSuccessful()) {
                $isi = $jawaban->getData(true);
                $jawaban->setData(is_array($isi) ? $isi + ['toko' => $toko->kode] : $isi);
            }

            return $jawaban;
        }

        // Login dengan surel yang tidak dikenal dijawab PERSIS seperti sandi
        // salah oleh AuthController hulu — kalimat, status, dan hitungan
        // pembatas lajunya — supaya API tidak bisa dipakai menebak surel mana
        // yang terdaftar. Tanpa toko, kueri penggunanya sendiri akan pecah.
        if ($nama === 'api.auth.login') {
            $kunci = 'api-login:'.$request->ip();
            if (RateLimiter::tooManyAttempts($kunci, 5)) {
                return ApiResponse::error('Terlalu banyak percobaan login. Coba lagi nanti.', 429);
            }
            RateLimiter::hit($kunci, 60);

            throw ValidationException::withMessages([
                'email' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
            ]);
        }

        return response()->json([
            'message' => 'Kepala X-Toko wajib berisi kode toko Aishii POS.',
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Keluar dari toko sesudah jawaban terkirim. PHP-FPM membuang proses
     * keadaan tiap permintaan, tetapi pekerja yang berumur panjang (Octane,
     * antrean) tidak — dan toko yang tertinggal di sana adalah toko yang
     * salah bagi permintaan berikutnya.
     */
    public function terminate(Request $request, Response $response): void
    {
        $this->penyewaan->keluar();
    }
}
