<?php

namespace App\Http\Middleware\Penyewaan;

use App\Models\Pusat\Toko;
use App\Penyewaan\Penyewaan;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tautan publik toko (`/t/{toko}/…`): struk, portal pelanggan, menu QR meja.
 * Dibuka PELANGGAN toko, tanpa sesi toko — tokonya hanya bisa dibaca dari
 * alamat.
 */
class KenaliTokoDariJalur
{
    public function __construct(private readonly Penyewaan $penyewaan) {}

    public function handle(Request $request, Closure $next): Response
    {
        $kode = (string) $request->route('toko');
        $toko = Toko::query()->where('kode', $kode)->first();

        abort_unless($toko?->siap(), 404);

        $tokoSesi = $this->penyewaan->toko();
        $this->penyewaan->masuk($toko);

        // Pemilik toko A yang sedang masuk lalu membuka tautan publik toko B:
        // sesi A menyimpan NOMOR pengguna A, dan nomor yang sama di basis data
        // B adalah orang lain. Penjaga sesi diganti dengan yang tidak mengenal
        // siapa pun, KHUSUS permintaan ini — sesinya sendiri tidak disentuh.
        $penjagaSemula = Auth::getDefaultDriver();
        if (! $tokoSesi?->is($toko)) {
            Auth::shouldUse('tamu_publik');
        }

        // Pengendali hulu tidak tahu-menahu soal {toko}: parameternya dilepas
        // supaya tanda tangan metodenya tetap seperti di mode satu toko.
        $request->route()->forgetParameter('toko');

        try {
            return $next($request);
        } finally {
            // shouldUse menulis ke konfigurasi yang hidup sepanjang proses.
            Auth::shouldUse($penjagaSemula);
        }
    }
}
