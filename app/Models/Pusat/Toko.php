<?php

namespace App\Models\Pusat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Satu toko Aishii POS — baris di basis data PUSAT, dengan basis data
 * dagangnya sendiri (`namaBasisData()`).
 */
class Toko extends Model
{
    protected $connection = 'pusat';

    protected $table = 'toko';

    protected $fillable = [
        'kode',
        'nama',
        'email_pemilik',
        'status_basis_data',
        'aktif_sampai',
        'kursi_outlet',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'aktif_sampai' => 'datetime',
            'kursi_outlet' => 'integer',
        ];
    }

    public function namaBasisData(): string
    {
        // Nomor, bukan kode: nama basis data tidak boleh berubah walau kode
        // tautannya kelak diganti, dan nomor tidak pernah memuat huruf yang
        // harus di-escape di DDL.
        return config('penyewaan.awalan_basis_data').$this->getKey();
    }

    public function siap(): bool
    {
        return $this->status_basis_data === 'siap';
    }

    public static function kodeBaru(string $nama): string
    {
        $dasar = Str::limit(Str::slug($nama), 24, '') ?: 'toko';

        do {
            $kode = $dasar.'-'.Str::lower(Str::random(5));
        } while (static::query()->where('kode', $kode)->exists());

        return $kode;
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }
}
