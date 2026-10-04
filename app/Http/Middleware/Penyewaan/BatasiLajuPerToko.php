<?php

namespace App\Http\Middleware\Penyewaan;

use App\Penyewaan\Penyewaan;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` yang tidak mencampur toko. Tanda tangan bawaan Laravel
 * untuk pengguna yang masuk adalah NOMOR penggunanya — dan nomor 1 ada di
 * setiap toko, jadi kasir toko A bisa menghabiskan jatah kasir toko B.
 */
class BatasiLajuPerToko extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $tanda = parent::resolveRequestSignature($request);
        $toko = app(Penyewaan::class)->toko();

        return $toko ? sha1('toko:'.$toko->getKey().'|'.$tanda) : $tanda;
    }
}
