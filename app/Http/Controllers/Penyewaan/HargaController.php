<?php

namespace App\Http\Controllers\Penyewaan;

use App\AkunAishii\KlienAishii;
use App\Http\Controllers\Controller;
use App\Langganan\Harga;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/harga — dibaca halaman /pos Aishii (AS11), supaya angka harga di
 * sana selalu angka yang benar-benar DITAGIH di sini. Pola Aishii: harga
 * dibaca dari daftar yang menagih, tidak diketik ulang di kalimat layar.
 */
class HargaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'harga_per_outlet' => Harga::perOutlet(),
            'mata_uang' => 'IDR',
            'per' => 'outlet berjualan per bulan',
            'masa_coba_hari' => 0,
            'daftar_url' => url('/daftar'),
            // AU1: halaman /pos Aishii memilih kalimat akunnya dari sini —
            // "satu akun Aishii" baru benar sesudah klien OIDC-nya diisi.
            'akun_aishii' => app(KlienAishii::class)->aktif(),
            // Lintas asal sudah dibuka HandleCors bawaan Laravel untuk api/*
            // (tanpa kredensial), jadi Aishii bisa membacanya dari peramban.
        ])->header('Cache-Control', 'public, max-age=900');
    }
}
