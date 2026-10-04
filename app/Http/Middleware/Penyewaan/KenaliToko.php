<?php

namespace App\Http\Middleware\Penyewaan;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\Pusat\DirektoriPengguna;
use App\Models\Pusat\Toko;
use App\Penyewaan\Penyewaan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengenali toko untuk rute web (mode banyak toko): dari sesi, dari cookie
 * "Ingat saya", atau — hanya di rute masuk dan lupa sandi — dari surel yang
 * diketik. Berjalan SESUDAH sesi dibuka dan SEBELUM `auth`, pembatas laju,
 * dan pengikatan model rute (urutannya dikunci di bootstrap/app.php), sebab
 * ketiganya membaca basis data toko.
 */
class KenaliToko
{
    /** Rute tamu yang tokonya hanya bisa dikenali dari surel di formulir. */
    private const RUTE_SUREL = ['password.email', 'password.store'];

    /** Kiriman formulir masuk — rutenya tidak bernama di routes/auth.php hulu. */
    private const AKSI_MASUK = AuthenticatedSessionController::class.'@store';

    public function __construct(private readonly Penyewaan $penyewaan) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->penyewaan->aktif() || $this->penyewaan->toko()) {
            return $next($request);
        }

        // Surel yang diketik MENDAHULUI cookie: perangkat yang dulu dipakai
        // toko A tetap harus bisa dipakai masuk ke toko B.
        $toko = $this->ruteSurel($request)
            ? $this->dariSurel($request)
            : $this->dariSesi($request);

        if ($toko) {
            $this->penyewaan->masuk($toko);
        } elseif ($request->hasSession() && $request->session()->has(Auth::guard('web')->getName())) {
            // Sesi yang mengaku sudah masuk tetapi tanpa toko (sisa mode satu
            // toko, atau tokonya dihapus) DIBUANG — bukan ditanyakan ke basis
            // data pusat yang tidak punya tabel pengguna.
            $request->session()->invalidate();
        }

        return $next($request);
    }

    private function dariSesi(Request $request): ?Toko
    {
        $id = $request->hasSession() ? $request->session()->get('toko_id') : null;
        $id ??= $request->cookie(config('penyewaan.cookie_toko'));

        if (! is_numeric($id)) {
            return null;
        }

        $toko = Toko::query()->find((int) $id);

        return $toko?->siap() ? $toko : null;
    }

    private function ruteSurel(Request $request): bool
    {
        $rute = $request->route();

        return $request->isMethod('post') && (
            in_array($rute?->getName(), self::RUTE_SUREL, true)
            || $rute?->getActionName() === self::AKSI_MASUK
        );
    }

    private function dariSurel(Request $request): ?Toko
    {
        $toko = DirektoriPengguna::tokoUntuk($request->input('email'));

        return $toko?->siap() ? $toko : null;
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
