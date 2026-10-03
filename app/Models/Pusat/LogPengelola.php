<?php

namespace App\Models\Pusat;

use Illuminate\Database\Eloquent\Model;

/** Jejak tindakan pengelola — tiap "tandai lunas" menyebut siapa dan kenapa. */
class LogPengelola extends Model
{
    protected $connection = 'pusat';

    protected $table = 'log_pengelola';

    protected $fillable = ['pengelola_id', 'tindakan', 'toko_id', 'tagihan_id', 'alasan'];
}
