<?php

/*
|--------------------------------------------------------------------------
| Merek — satu-satunya tempat identitas aplikasi ini ditulis
|--------------------------------------------------------------------------
|
| Aplikasi ini fork `aryadwiputra/point-of-sales` (lisensi MIT) yang
| dijalankan sebagai Aishii POS, anggota keluarga Aishii. Blade dan PHP
| membaca dari sini; React membaca kembarannya di
| `resources/js/Utils/brand.js` — `tests/Feature/BrandTest.php` memerahkan
| keduanya begitu namanya menyimpang.
|
| Nama ditulis di sini, BUKAN dibaca dari APP_NAME: `.env` yang disalin dari
| contoh lama berisi `APP_NAME=Laravel`, dan merek yang bisa berubah menjadi
| "Laravel" karena satu berkas lingkungan yang basi bukan merek.
|
*/

return [
    'name' => 'Aishii POS',

    'tagline' => 'Kasir toko dari keluarga Aishii',

    'description' => 'Aishii POS — kasir toko untuk UMKM Indonesia: barcode, stok per gudang '
        .'dan cabang, pemasok dan retur, piutang, member dan poin, pesanan meja lewat QR, '
        .'dan tetap mencatat saat internet putus.',

    // Ungu logo Aishii (`--color-aishii-600`), sama dengan `theme-color` Aishii.
    'theme_color' => '#752e8e',

    // Penerbit keluarga Aishii — nama yang sama dengan kaki halaman Aishii.
    'entity' => 'Aishii ERP',

    // Saudara tuanya: halaman depan Aishii memuat menu yang mengantar ke sini,
    // dan halaman publik aplikasi ini menunjuk balik ke sana.
    'parent' => [
        'name' => 'Aishii',
        'url' => rtrim((string) env('AISHII_URL', 'https://aishiierp.com'), '/'),
    ],

    // Kode sumber fork ini (publik).
    'source_url' => 'https://github.com/okyzazake7-cell/point-of-sales',

    // Pembuat asli — kewajiban lisensi MIT, dan kejujuran.
    'upstream' => [
        'name' => 'Point of Sales',
        'author' => 'Arya Dwi Putra',
        'url' => 'https://github.com/aryadwiputra/point-of-sales',
        'license' => 'MIT',
    ],
];
