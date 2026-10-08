<?php

use App\Http\Controllers\Penyewaan\DaftarController;
use App\Http\Controllers\Penyewaan\LanggananController;
use App\Http\Controllers\Penyewaan\MasukAishiiController;
use App\Http\Controllers\Penyewaan\PengelolaController;
use App\Http\Middleware\Penyewaan\PastikanPengelola;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rute mode banyak toko (POS_MULTI_TOKO=true) — dokumen 24 di repo Aishii
|--------------------------------------------------------------------------
|
| Dimuat routes/web.php HANYA dalam mode banyak toko: dalam mode satu toko
| alamat-alamat ini tidak ada sama sekali.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/daftar', [DaftarController::class, 'create'])->name('daftar');
    Route::post('/daftar', [DaftarController::class, 'store'])
        ->middleware(['bot.guard', 'throttle:5,10'])
        ->name('daftar.store');

    // Pendaftaran bertahap (AU6): halaman kemajuan memanggil /lanjut
    // berulang-ulang, tiap panggilan paling lama ±20 detik kerja. Pembatas
    // lajunya berawalan sendiri — tanpa awalan ia berbagi penghitung dengan
    // kiriman /daftar di atas (lihat grup auth/aishii).
    Route::get('/daftar/menyiapkan', [DaftarController::class, 'menyiapkan'])->name('daftar.menyiapkan');
    Route::post('/daftar/lanjut', [DaftarController::class, 'lanjut'])
        ->middleware('throttle:60,1,daftar-lanjut')
        ->name('daftar.lanjut');
    Route::post('/daftar/ulangi', [DaftarController::class, 'ulangi'])
        ->middleware('throttle:10,10,daftar-ulangi')
        ->name('daftar.ulangi');
});

// "Masuk dengan akun Aishii" (AU1, dokumen 27 di repo Aishii). Selama ID dan
// rahasia klien OIDC kosong, kelimanya menjawab 404. `/kembali` sengaja tanpa
// `guest`: ia juga alamat balik pintu pengelola (AU5), yang boleh ditempuh
// pemilik toko yang sedang masuk — penjagaannya di dalam controller.
//
// Awalan `aishii` pada pembatas lajunya WAJIB: `throttle:N,M` tanpa awalan
// berbagi SATU penghitung per alamat pengunjung dengan tiap `throttle` tanpa
// awalan lain — dan tiap bolak-balik ke akun Aishii (dua permintaan) ikut
// menghabiskan jatah lima kiriman /daftar di bawah (terukur 7 Okt: 429).
Route::prefix('auth/aishii')->name('aishii.')->middleware('throttle:20,1,aishii')->group(function () {
    Route::get('/', [MasukAishiiController::class, 'mulai'])->middleware('guest')->name('masuk');
    Route::get('/pengelola', [MasukAishiiController::class, 'pengelola'])->name('pengelola');
    Route::get('/kembali', [MasukAishiiController::class, 'kembali'])->name('kembali');
    Route::middleware('auth')->group(function () {
        Route::get('/konfirmasi/mulai', [MasukAishiiController::class, 'konfirmasiMulai'])->name('konfirmasi.mulai');
        Route::get('/konfirmasi', [MasukAishiiController::class, 'konfirmasi'])->name('konfirmasi');
    });
});

Route::prefix('dashboard/langganan')->middleware('auth')->name('langganan.')->group(function () {
    Route::get('/', [LanggananController::class, 'index'])->name('index');
    Route::post('/tagihan', [LanggananController::class, 'tagihan'])->middleware('throttle:10,1')->name('tagihan');
    Route::post('/periksa', [LanggananController::class, 'periksa'])->middleware('throttle:12,1')->name('periksa');
    Route::post('/tagihan/{tagihan}/batal', [LanggananController::class, 'batalkan'])->name('batalkan');
    Route::post('/outlet/{outlet}/berhenti-jual', [LanggananController::class, 'berhentiJual'])->name('berhenti-jual');
});

Route::prefix('pengelola')->name('pengelola.')->group(function () {
    Route::get('/masuk', [PengelolaController::class, 'masukForm'])->name('masuk');
    Route::post('/masuk', [PengelolaController::class, 'masuk'])
        ->middleware(['bot.guard', 'throttle:5,1'])
        ->name('masuk.store');

    Route::middleware(PastikanPengelola::class)->group(function () {
        Route::get('/', [PengelolaController::class, 'index'])->name('index');
        Route::post('/keluar', [PengelolaController::class, 'keluar'])->name('keluar');
        Route::post('/tagihan/{tagihan}/lunas', [PengelolaController::class, 'tandaiLunas'])->name('tandai-lunas');
    });
});
