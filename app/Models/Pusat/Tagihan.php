<?php

namespace App\Models\Pusat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tagihan langganan Aishii POS. `nomor` juga `ref` di buku tagihan bersama
 * Aishii (tabel `tagihan_pos`), tempat kode unik dipilih dan pembayaran
 * dicocokkan.
 */
class Tagihan extends Model
{
    protected $connection = 'pusat';

    protected $table = 'tagihan';

    protected $fillable = [
        'toko_id', 'nomor', 'jenis', 'kursi', 'bulan', 'hari_prorata',
        'nominal_dasar', 'kode_unik', 'total_bayar', 'pencocok', 'status',
        'berlaku_sampai', 'dibayar_pada', 'dibayar_lewat', 'pengelola_id',
        'alasan', 'periode_sampai',
    ];

    protected function casts(): array
    {
        return [
            'kursi' => 'integer',
            'bulan' => 'integer',
            'hari_prorata' => 'integer',
            'nominal_dasar' => 'integer',
            'kode_unik' => 'integer',
            'total_bayar' => 'integer',
            'berlaku_sampai' => 'datetime',
            'dibayar_pada' => 'datetime',
            'periode_sampai' => 'datetime',
        ];
    }

    public function toko(): BelongsTo
    {
        return $this->belongsTo(Toko::class);
    }

    public function scopeMenunggu(Builder $query): Builder
    {
        return $query->where('status', 'menunggu');
    }

    public function masihBerlaku(): bool
    {
        return $this->status === 'menunggu' && $this->berlaku_sampai->isFuture();
    }
}
