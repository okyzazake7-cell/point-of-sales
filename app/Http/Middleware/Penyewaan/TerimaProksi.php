<?php

namespace App\Http\Middleware\Penyewaan;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permintaan yang datang lewat Worker penerus `pos.aishiierp.com`
 * (dokumen 25 di repo Aishii, `cloudflare/proksi.js` di repo ini).
 *
 * Cloud Run hanya mengenal alamat *.run.app, jadi Worker Cloudflare yang
 * memegang domain kita meneruskan tiap permintaan ke sana. Tiga hal yang
 * dibereskan di sini, sebelum middleware lain membaca permintaannya:
 *
 *   1. Yang datang LANGSUNG ke run.app ditolak (404). Tanpa itu siapa pun
 *      bisa melewati Cloudflare, dan kepala pengunjung yang dibawa Worker
 *      (poin 2) bisa dipalsukan untuk mengelabui pembatas laju masuk/daftar.
 *   2. Alamat IP pengunjung yang sebenarnya diambil dari kepala yang ditulis
 *      Worker — tanpanya semua orang terlihat sebagai satu alamat Cloudflare
 *      dan pembatas laju menghukum seluruh pengguna sekaligus.
 *   3. Host dan skema dikembalikan ke APP_URL. Laravel membangun tautan dan
 *      MEMERIKSA tautan bertanda tangan dari host permintaan; tanpa ini ia
 *      melihat *.run.app dan tautan verifikasi surel yang dikirimnya sendiri
 *      dinyatakan palsu.
 *
 * `/up` (pemeriksa kesehatan Cloud Run) dan `/_jadwal` (Cloud Scheduler,
 * berpagar rahasianya sendiri) memang datang langsung, jadi dilewatkan.
 * Rahasia kosong = tanpa Worker (pengembangan, VPS): middleware ini diam.
 */
class TerimaProksi
{
    public function handle(Request $request, Closure $next): Response
    {
        $rahasia = (string) config('penyewaan.rahasia_proksi');

        if ($rahasia === '' || $request->is('up', '_jadwal')) {
            return $next($request);
        }

        if (! hash_equals($rahasia, (string) $request->headers->get('X-Aishii-Proksi'))) {
            abort(404);
        }

        $klien = (string) $request->headers->get('X-Aishii-Klien');
        if (filter_var($klien, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $klien);
        }

        $asal = parse_url((string) config('app.url'));
        if (! empty($asal['host'])) {
            $host = $asal['host'].(isset($asal['port']) ? ':'.$asal['port'] : '');
            $request->headers->set('HOST', $host);
            $request->server->set('HTTP_HOST', $host);
            $request->server->set('SERVER_NAME', $asal['host']);
        }
        if (($asal['scheme'] ?? '') === 'https') {
            $request->server->set('HTTPS', 'on');
            $request->server->set('SERVER_PORT', $asal['port'] ?? 443);
        }

        // Rahasianya tidak ikut ke mana pun sesudah ini — log, Sentry, dump.
        $request->headers->remove('X-Aishii-Proksi');

        return $next($request);
    }
}
