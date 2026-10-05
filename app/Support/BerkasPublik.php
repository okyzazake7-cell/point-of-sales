<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Satu-satunya tempat yang mengubah jalur berkas unggahan menjadi alamat dan
 * isi (dokumen 25 di repo Aishii).
 *
 * Hulu mengetik `asset('storage/…')` di enam tempat, sehingga alamat gambar
 * terikat pada disk lokal server — padahal di Cloud Run disk itu hilang tiap
 * instans bangun ulang dan berkasnya tinggal di R2. Semuanya kini lewat disk
 * `public`, yang `config/filesystems.php` arahkan ke lokal atau ke R2.
 */
class BerkasPublik
{
    /** Alamat yang bisa dibuka peramban, atau null bila tidak ada berkas. */
    public static function url(?string $jalur): ?string
    {
        if (! $jalur) {
            return null;
        }

        // Nilai yang sudah berupa alamat dibiarkan: data hulu lama dan data
        // contoh menyimpan URL penuh atau `/storage/...`.
        if (str_starts_with($jalur, 'http://') || str_starts_with($jalur, 'https://') || str_starts_with($jalur, '/storage/')) {
            return $jalur;
        }

        return Storage::disk('public')->url(ltrim($jalur, '/'));
    }

    /**
     * Isi berkas sebagai data URI — untuk PDF, sebab dompdf yang mengambil
     * gambar dari jaringan sendiri adalah pintu SSRF, dan di R2 tidak ada
     * berkas lokal untuk dibaca. Gagal membaca berakhir null: dokumen tanpa
     * logo lebih baik daripada dokumen yang tidak jadi.
     */
    public static function dataUri(?string $jalur): ?string
    {
        if (! $jalur || str_starts_with($jalur, 'http://') || str_starts_with($jalur, 'https://')) {
            return null;
        }

        $relatif = ltrim(preg_replace('#^/storage/#', '', $jalur), '/');

        try {
            $isi = Storage::disk('public')->get($relatif);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($isi) || $isi === '') {
            return null;
        }

        $jenis = (new \finfo(FILEINFO_MIME_TYPE))->buffer($isi) ?: 'application/octet-stream';
        if (! str_starts_with($jenis, 'image/')) {
            return null;
        }

        return 'data:'.$jenis.';base64,'.base64_encode($isi);
    }
}
