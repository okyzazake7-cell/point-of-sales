<?php

namespace App\AkunAishii;

use App\Models\Pusat\DirektoriPengguna;
use App\Models\User;

/**
 * Akun POS yang tertaut ke akun Aishii (kolom `aishii_sub` direktori pusat).
 *
 * Keputusan D2 (dokumen 27 di repo Aishii): akun yang tertaut HANYA masuk
 * lewat akun Aishii — dua sandi untuk satu orang adalah dua pintu yang harus
 * dijaga. Berlaku hanya selama "Masuk dengan akun Aishii" hidup: mematikannya
 * (mengosongkan rahasia klien) tidak boleh mengunci siapa pun di luar.
 * Kasir yang dibuat pemilik toko tidak tertaut dan tetap bersandi (D1b).
 */
class AkunTertaut
{
    public const KALIMAT_PAKAI_AISHII = 'Akun ini masuk lewat tombol "Masuk dengan akun Aishii" — kata sandi toko tidak dipakai lagi.';

    public static function tertaut(?string $email): bool
    {
        if (! app(KlienAishii::class)->aktif()) {
            return false;
        }
        $email = DirektoriPengguna::normalkan($email);

        return $email !== '' && DirektoriPengguna::query()->whereKey($email)->whereNotNull('aishii_sub')->exists();
    }

    public static function pengguna(?User $pengguna): bool
    {
        return $pengguna !== null && static::tertaut($pengguna->email);
    }

    /**
     * Pintu ORANG BARU (AY1): mendaftar akun Aishii lebih dulu, lalu kembali
     * lewat `aishiierp.com/pos?buka=1`, yang membuka `/auth/aishii` di sini
     * dengan permintaan masuk yang baru lahir saat akunnya sudah aktif.
     * Tombol "Masuk dengan akun Aishii" tidak cukup untuk orang baru:
     * permintaan masuknya lahir SEBELUM akunnya ada, dan kedaluwarsa selama
     * surelnya dikonfirmasi (bekal sesi 600 detik).
     */
    public static function alamatDaftar(): string
    {
        return config('brand.parent.url').'/register?redirect='.rawurlencode('/pos?buka=1');
    }

    /**
     * Konfirmasi tindakan penting lewat akun Aishii: `/masuk-ulang` di
     * Aishii meminta sandinya lagi, mencatat bukti atas `kode` (AV14,
     * `BuktiKonfirmasi`), lalu mengantar ke sini untuk meminta otorisasi
     * berakhiran `/konfirmasi`.
     */
    public static function alamatKonfirmasi(string $kode): string
    {
        return config('brand.parent.url').'/masuk-ulang?lanjut='
            .rawurlencode(rtrim((string) config('app.url'), '/').'/auth/aishii/konfirmasi/mulai')
            .'&kode='.rawurlencode($kode);
    }
}
