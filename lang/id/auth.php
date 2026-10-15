<?php

/*
 * Pesan masuk akun dalam bahasa Indonesia. Tanpa berkas ini Laravel jatuh
 * ke bawaan Inggris-nya: "These credentials do not match our records." di
 * bawah formulir yang seluruhnya berbahasa Indonesia (diuji di
 * pos.aishiierp.com, 15 Okt).
 */
return [

    'failed' => 'Surel atau kata sandi salah.',
    // Saat "Masuk dengan akun Aishii" menyala. Kalimatnya SAMA untuk setiap
    // kegagalan — tidak membocorkan surel mana yang tertaut ke akun Aishii —
    // tetapi menunjukkan jalan kepada pemilik toko yang mengetik sandi akun
    // Aishii-nya di formulir kasir.
    'failed_aishii' => 'Surel atau kata sandi salah. Pemilik toko yang mendaftar dengan akun Aishii: tekan tombol "Masuk dengan akun Aishii" di atas.',
    'password' => 'Kata sandi salah.',
    'throttle' => 'Terlalu banyak percobaan masuk. Coba lagi dalam :seconds detik.',

];
