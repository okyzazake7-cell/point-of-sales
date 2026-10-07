<?php

namespace App\Models\Pusat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Surel → toko. Surel unik di SELURUH layanan, jadi halaman masuk dan lupa
 * sandi tahu basis data mana yang harus dibuka tanpa bertanya.
 */
class DirektoriPengguna extends Model
{
    protected $connection = 'pusat';

    protected $table = 'direktori_pengguna';

    protected $primaryKey = 'email';

    public $incrementing = false;

    protected $keyType = 'string';

    // `aishii_sub`: akun Aishii yang tertaut (AU1) — kosong untuk kasir
    // bersandi dan akun yang belum pernah masuk lewat akun Aishii.
    protected $fillable = ['email', 'toko_id', 'user_id', 'aishii_sub'];

    public static function normalkan(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function tokoUntuk(?string $email): ?Toko
    {
        $email = static::normalkan($email);
        if ($email === '') {
            return null;
        }

        return static::query()->find($email)?->toko;
    }

    public function toko(): BelongsTo
    {
        return $this->belongsTo(Toko::class);
    }
}
