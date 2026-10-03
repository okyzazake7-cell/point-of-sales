<?php

namespace App\Langganan;

use Carbon\CarbonInterface;

/**
 * Hitungan harga langganan — fungsi murni, tanpa basis data, supaya tiap
 * angka di layar Langganan bisa dibuktikan uji satuan.
 */
final class Harga
{
    public static function perOutlet(): int
    {
        return (int) config('langganan.harga_per_outlet');
    }

    /** kursi × harga × bulan. */
    public static function perpanjang(int $kursi, int $bulan): int
    {
        return max(1, $kursi) * self::perOutlet() * max(1, $bulan);
    }

    /** Sisa hari masa aktif, dibulatkan ke atas (sisa 1 jam = 1 hari). */
    public static function sisaHari(?CarbonInterface $aktifSampai, CarbonInterface $sekarang): int
    {
        if (! $aktifSampai || $aktifSampai->lessThanOrEqualTo($sekarang)) {
            return 0;
        }

        return (int) ceil($sekarang->diffInSeconds($aktifSampai, true) / 86400);
    }

    /**
     * Menambah outlet di tengah masa aktif: ⌈tambahan × harga × sisa_hari ÷ 30⌉,
     * dibulatkan ke atas ke ribuan, minimal Rp 1.000.
     */
    public static function tambahOutlet(int $tambahan, int $sisaHari): int
    {
        $mentah = max(1, $tambahan) * self::perOutlet() * max(1, $sisaHari) / (int) config('langganan.hari_prorata');

        return max(1000, (int) (ceil($mentah / 1000) * 1000));
    }
}
