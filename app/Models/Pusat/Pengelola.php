<?php

namespace App\Models\Pusat;

use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Pengelola layanan Aishii POS — akun PUSAT, bukan pengguna toko mana pun.
 * Penjaganya `pengelola` (config/auth.php), dengan kunci sesinya sendiri.
 */
class Pengelola extends Authenticatable
{
    protected $connection = 'pusat';

    protected $table = 'pengelola';

    protected $fillable = ['nama', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
