<?php

use App\Http\Controllers\Penyewaan\DaftarController;
use App\Http\Controllers\Penyewaan\LanggananController;
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
