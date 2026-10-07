<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Masuk dengan akun Aishii" (AU1, dokumen 27 di repo Aishii): direktori
 * pusat dikunci `sub` akun Aishii — uuid yang tidak pernah berganti, tidak
 * seperti surel. Surel tetap kunci utama direktori: halaman masuk kasir,
 * lupa sandi, dan undangan masih mencari lewat surel.
 *
 * Kosong = belum tertaut (kasir bersandi, D1b; atau akun lama yang
 * pemiliknya belum pernah masuk lewat akun Aishii). Unik: satu akun Aishii
 * tertaut ke satu akun POS — satu akun di banyak toko di luar AU1 (§6).
 *
 * Belum dijalankan: kode yang membaca kolom ini hanya berjalan selama ID dan
 * rahasia klien OIDC terisi, jadi isi rahasianya SESUDAH migrasi ini
 * (langkah pemilik P17). `pos:siapkan` menjalankannya sendiri saat terbit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direktori_pengguna', function (Blueprint $table) {
            $table->string('aishii_sub', 64)->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('direktori_pengguna', function (Blueprint $table) {
            $table->dropUnique(['aishii_sub']);
            $table->dropColumn('aishii_sub');
        });
    }
};
