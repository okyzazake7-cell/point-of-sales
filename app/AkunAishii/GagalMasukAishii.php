<?php

namespace App\AkunAishii;

use RuntimeException;

/**
 * Masuk lewat akun Aishii yang ditolak. `getMessage()` adalah kalimat untuk
 * orangnya; `alasan` untuk log — keduanya sengaja dipisah, sebab alasan
 * teknis ("aud tidak cocok") tidak menolong orang yang sedang berdiri di
 * kasir, dan kalimat layar tidak menolong orang yang membaca log.
 */
class GagalMasukAishii extends RuntimeException
{
    public function __construct(string $kalimat, public readonly string $alasan = '')
    {
        parent::__construct($kalimat);
    }
}
