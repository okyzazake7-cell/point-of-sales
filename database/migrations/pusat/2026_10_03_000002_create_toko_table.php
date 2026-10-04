<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daftar toko dan direktori surel — dua hal yang harus diketahui SEBELUM
 * basis data toko mana pun dibuka.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toko', function (Blueprint $table) {
            $table->id();
            // Penanda di tautan publik (/t/{kode}/…): struk, portal, QR meja.
            // Sengaja bukan nomor urut — nomor urut membocorkan jumlah toko
            // dan mengundang menebak tautan toko tetangga.
            $table->string('kode', 40)->unique();
            $table->string('nama', 120);
            $table->string('email_pemilik');
            // menyiapkan → siap, atau gagal. Toko yang belum siap tidak
            // pernah dimasuki: basis datanya bisa jadi setengah termigrasi.
            $table->string('status_basis_data', 20)->default('menyiapkan');
            // Kosong = belum pernah membayar. Tanpa masa coba (keputusan
            // pemilik 3 Okt), jadi toko baru lahir terkunci.
            $table->timestamp('aktif_sampai')->nullable();
            // Outlet berjualan yang sudah dibayar.
            $table->unsignedSmallInteger('kursi_outlet')->default(1);
            $table->text('catatan')->nullable();
            $table->timestamps();
        });

        // Surel unik di SELURUH layanan: halaman masuk tidak perlu bertanya
        // "toko yang mana", dan lupa sandi tahu harus mencari di mana.
        Schema::create('direktori_pengguna', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->foreignId('toko_id')->constrained('toko')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
            $table->index(['toko_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direktori_pengguna');
        Schema::dropIfExists('toko');
    }
};
