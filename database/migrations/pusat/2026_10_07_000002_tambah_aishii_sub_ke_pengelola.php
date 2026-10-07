<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengelola Aishii POS masuk dengan akun Aishii (AU5, keputusan pemilik
 * 7 Okt — membalik D4 dokumen 27 di repo Aishii): satu akun, nol sandi POS.
 *
 * SIAPA yang boleh menjadi pengelola tetap daftar milik POS (baris di tabel
 * ini, dari `POS_PENGELOLA_SUREL`), BUKAN `admin_platform` Aishii Bazar:
 * produk tidak pernah membaca basis data Aishii. Kolom ini mengunci baris
 * itu ke `sub` akun Aishii pada masuk pertama (surel terverifikasi), supaya
 * sesudahnya surel yang berganti di salah satu sisi tidak memutus jalannya.
 *
 * Belum dijalankan: kode yang membaca kolom ini hanya berjalan selama klien
 * OIDC terisi (P17). `pos:siapkan` menjalankannya sendiri saat terbit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengelola', function (Blueprint $table) {
            $table->string('aishii_sub', 64)->nullable()->unique()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('pengelola', function (Blueprint $table) {
            $table->dropUnique(['aishii_sub']);
            $table->dropColumn('aishii_sub');
        });
    }
};
