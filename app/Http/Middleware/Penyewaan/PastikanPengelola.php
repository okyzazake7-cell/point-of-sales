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

        return $request->expectsJson()
            ? response()->json(['message' => 'Unauthenticated.'], 401)
            : redirect()->guest(route('pengelola.masuk'));
    }
}
