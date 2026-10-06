<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nomor telepon pelanggan disimpan sebagai TEKS, bukan angka (AT3, 5 Okt).
 *
 * Kolom hulu `customers.no_telp` berjenis bigint, padahal formulirnya
 * menerima teks. Diukur sebelum migrasi ini: "081234567890" tersimpan
 * 81234567890 di SQLite maupun MariaDB (nol depan hilang), dan
 * "0812-3456-7890" ditolak MariaDB strict sehingga kasir melihat "gagal".
 * Lebarnya 30 mengikuti validasi API hulu (`max:30`). Dijaga
 * `NomorTeleponPelangganTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('no_telp', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->bigInteger('no_telp')->change();
        });
    }
};
