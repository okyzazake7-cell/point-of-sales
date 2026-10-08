<?php

/*
|--------------------------------------------------------------------------
| "Masuk dengan akun Aishii" (AU1) — dokumen 27 di repo Aishii
|--------------------------------------------------------------------------
|
| Satu akun Aishii masuk ke produk Aishii mana pun; langganannya tetap per
| produk. Aishii POS adalah klien OIDC RAHASIA dari OAuth 2.1 Server
| Supabase milik Aishii: kode otorisasi + PKCE, rahasia klien hanya di
| server (variabel Cloud Run), token ID diverifikasi lewat JWKS — tidak
| pernah lewat rahasia bersama.
|
| MATI selama ID atau rahasia klien kosong (bawaan, juga di uji dan mode satu
| toko): halaman masuk, /daftar, dan konfirmasi sandi bekerja persis seperti
| sebelum AU1. Begitu HIDUP, pemilik yang akunnya tertaut hanya masuk lewat
| akun Aishii (keputusan D2), dan toko baru lahir dari akun Aishii.
|
*/

$supabase = rtrim((string) env('AISHII_SUPABASE_URL', ''), '/');

return [

    // Penerbit token ID — harus SAMA PERSIS dengan klaim `iss`.
    'penerbit' => rtrim((string) env('AISHII_OIDC_PENERBIT', $supabase !== '' ? $supabase.'/auth/v1' : ''), '/'),

    'id_klien' => (string) env('AISHII_OIDC_ID_KLIEN', ''),

    'rahasia_klien' => (string) env('AISHII_OIDC_RAHASIA_KLIEN', ''),

    // `email` untuk menautkan toko lama sekali jalan dan mengisi surel toko
    // baru; `profile` untuk nama pemilik di /daftar.
    'cakupan' => 'openid email profile',

    // Kunci publik JWKS disimpan sebentar; `kid` yang tidak dikenal memaksa
    // pengambilan ulang sekali (kunci baru sesudah rotasi).
    'umur_jwks' => 600,

    // Selisih jam yang dimaafkan saat membaca `exp` dan `iat`. Kesegaran
    // konfirmasi tindakan penting TIDAK dibaca dari token sama sekali —
    // `auth_time` adalah saat token terbit (H8); batas waktunya milik basis
    // data Aishii (`BuktiKonfirmasi`: sandi ≤ 5 menit dicatat, ≤ 6 dipakai).
    'kelonggaran_jam' => 60,

];
