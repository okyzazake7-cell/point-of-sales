<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tagihan langganan (Rp per outlet per bulan) dan pengelola layanan.
 *
 * Pengelola adalah akun PUSAT — bukan pengguna toko mana pun — dan yang
 * dilihatnya hanya hitungan dan tanggal langganan, bukan data dagang toko
 * (pola dokumen 02 Aishii).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengelola', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('toko_id')->constrained('toko')->cascadeOnDelete();
            // Nomor yang juga menjadi `ref` di buku tagihan bersama Aishii.
            $table->string('nomor', 40)->unique();
            // perpanjang | tambah_outlet
            $table->string('jenis', 20);
            $table->unsignedSmallInteger('kursi');
            $table->unsignedTinyInteger('bulan')->default(0);
            $table->unsignedSmallInteger('hari_prorata')->nullable();
            $table->unsignedInteger('nominal_dasar');
            // Kosong = jalur manual: nominal bulat + bukti lewat WhatsApp.
            $table->unsignedSmallInteger('kode_unik')->nullable();
            $table->unsignedInteger('total_bayar');
            // aishii (buku tagihan bersama) | manual
            $table->string('pencocok', 10);
            // menunggu | lunas | batal | kedaluwarsa
            $table->string('status', 15)->default('menunggu');
            $table->timestamp('berlaku_sampai');
            $table->timestamp('dibayar_pada')->nullable();
            // otomatis | manual
            $table->string('dibayar_lewat', 10)->nullable();
            $table->foreignId('pengelola_id')->nullable()->constrained('pengelola')->nullOnDelete();
            $table->text('alasan')->nullable();
            // Masa aktif toko SESUDAH tagihan ini diterapkan — jejak yang
            // bisa dibandingkan bila ada yang bertanya "kenapa sampai tanggal itu".
            $table->timestamp('periode_sampai')->nullable();
            $table->timestamps();
            $table->index(['status', 'pencocok']);
            $table->index(['toko_id', 'status']);
        });

        Schema::create('log_pengelola', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pengelola_id')->nullable()->constrained('pengelola')->nullOnDelete();
            $table->string('tindakan', 80);
            $table->foreignId('toko_id')->nullable()->constrained('toko')->nullOnDelete();
            $table->foreignId('tagihan_id')->nullable()->constrained('tagihan')->nullOnDelete();
            $table->text('alasan');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_pengelola');
        Schema::dropIfExists('tagihan');
        Schema::dropIfExists('pengelola');
    }
};
