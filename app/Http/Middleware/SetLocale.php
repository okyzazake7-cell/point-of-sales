<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $availableLocales = ['id', 'en'];
        $defaultLocale = 'id';

        $locale = $defaultLocale;

        if ($request->user() && $request->user()->locale) {
            $locale = $request->user()->locale;
        } elseif ($request->session()->has('locale')) {
            $locale = $request->session()->get('locale');
        } elseif ($request->cookie('locale')) {
            $locale = $request->cookie('locale');
        }

        // `Accept-Language` sengaja TIDAK dibaca. Banyak ponsel di Indonesia
        // berbahasa sistem Inggris, dan menebak dari sana menyuguhkan
        // antarmuka Inggris kepada pedagang yang tidak pernah memintanya —
        // keluarga Aishii berbahasa Indonesia sejak kunjungan pertama. Yang
        // menginginkan English memilihnya sendiri, dan pilihannya diingat
        // (pengguna, sesi, kuki) oleh cabang-cabang di atas.

        if (! in_array($locale, $availableLocales)) {
            $locale = $defaultLocale;
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
