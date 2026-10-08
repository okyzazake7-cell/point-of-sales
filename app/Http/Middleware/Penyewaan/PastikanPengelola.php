<?php

namespace App\Http\Middleware\Penyewaan;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pintu /pengelola. Sengaja BUKAN `auth:pengelola`: middleware bawaan itu
 * menjadikan pengelola penjaga BAWAAN permintaannya, sehingga
 * `$request->user()` di seluruh lapisan hulu (HandleInertiaRequests,
 * layanan outlet) tiba-tiba memegang akun pusat yang tidak punya toko,
 * peran, maupun outlet. Pengelola dibaca lewat penjaganya sendiri saja.
 */
class PastikanPengelola
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('pengelola')->check()) {
            return $next($request);
        }

        // `route()`, BUKAN `guest()`: `url.intended` dipakai BERSAMA penjaga
        // toko. Pemilik yang juga pengelola membuka /pengelola, masuk sebagai
        // pengelola, lalu masuk ke tokonya — dan `intended()` di pintu toko
        // mengantarnya ke halaman Pengelola (P21, 7 Okt). Pintu ini cuma
        // punya satu halaman, jadi tidak ada tujuan yang perlu diingat.
        return $request->expectsJson()
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect()->route('pengelola.masuk');
    }
}
