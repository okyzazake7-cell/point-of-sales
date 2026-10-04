<?php

/*
|--------------------------------------------------------------------------
| Aishii POS banyak toko — satu basis data per toko
|--------------------------------------------------------------------------
|
| Rancangannya di repo Aishii: docs/rencana-saas/24-aishii-pos-langganan.md.
|
| Mati (bawaan): satu instalasi = satu toko, persis seperti fork hulunya —
| seluruh uji hulu dan pemasangan mandiri tidak berubah sedikit pun.
|
| Hidup (pos.aishiierp.com): basis data yang disebut DB_* menjadi PUSAT
| (daftar toko, direktori surel, tagihan, pengelola, sesi, tembolok,
| wilayah), dan tiap toko mendapat basis data sendiri berawalan
| `awalan_basis_data`. Pagarnya KONEKSI, bukan saringan di tiap kueri: kueri
| hulu yang lupa menyaring tetap hanya melihat satu toko.
|
*/

return [

    'aktif' => (bool) env('POS_MULTI_TOKO', false),

    // Nama dua koneksi yang dilahirkan PenyewaanServiceProvider dari koneksi
    // DB_CONNECTION. Bukan env: kode lain menyebut nama ini.
    'koneksi_pusat' => 'pusat',
    'koneksi_toko' => 'toko',

    // Pengguna MySQL server diberi hak HANYA atas `aishiipos\_%` — awalan ini
    // pagar yang membuatnya tidak bisa menyentuh basis data lain di server
    // yang sama, jadi mengubahnya berarti mengubah GRANT-nya juga.
    'awalan_basis_data' => env('POS_AWALAN_DB', 'aishiipos_'),

    // Hanya untuk SQLite (pengembangan dan uji): tempat berkas tiap toko.
    'direktori_sqlite' => env('POS_DIREKTORI_SQLITE', database_path('toko')),

    // Cookie terenkripsi yang mengingat toko untuk "Ingat saya": tanpa dia,
    // cookie pengingat Laravel tidak tahu harus mencari penggunanya di basis
    // data toko yang mana begitu sesinya habis.
    'cookie_toko' => 'pos_toko',

];
