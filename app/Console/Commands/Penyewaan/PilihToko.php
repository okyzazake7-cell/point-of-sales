<?php

namespace App\Console\Commands\Penyewaan;

use App\Models\Pusat\Toko;
use Illuminate\Support\Collection;

/** Pilihan `--toko=*` bersama: nomor atau kode; kosong = semua yang siap. */
final class PilihToko
{
    /**
     * @param  array<int, string>  $pilihan
     * @return Collection<int, Toko>
     */
    public static function dari(array $pilihan): Collection
    {
        return Toko::query()
            ->where('status_basis_data', 'siap')
            ->when($pilihan !== [], fn ($q) => $q->where(fn ($q) => $q
                ->whereIn('id', array_filter($pilihan, 'is_numeric'))
                ->orWhereIn('kode', $pilihan)))
            ->orderBy('id')
            ->get();
    }
}
