<?php

/*
|--------------------------------------------------------------------------
| Langganan Aishii POS (mode banyak toko) — dokumen 24 di repo Aishii
|--------------------------------------------------------------------------
|
| Keputusan pemilik 3 Okt: Rp 25.000 per outlet yang BERJUALAN per bulan,
| tanpa masa coba, langsung terkunci saat habis (laporan tetap terbaca),
| dibayar lewat QRIS + kode unik yang SAMA dengan Aishii Bazar.
|
| Harga hidup di SINI saja. Halaman /pos Aishii membacanya lewat
| GET /api/harga, jadi mengubah harga tidak menuntut menyunting dua repo.
|
*/

return [

    'harga_per_outlet' => (int) env('POS_HARGA_OUTLET', 25000),

    // Pilihan lama berlangganan saat memperpanjang — tanpa potongan.
    'pilihan_bulan' => [1, 3, 6, 12],

    // Prorata menambah outlet di tengah masa aktif: harga ÷ 30 per hari,
    // dibulatkan ke atas ke ribuan. "÷ 30" bisa dijelaskan dalam satu
    // kalimat; hari kalender bulan berjalan (28–31) tidak.
    'hari_prorata' => 30,

    // Nominal unik yang terlalu lama menunggu dibebaskan untuk orang lain.
    'tagihan_berlaku_jam' => (int) env('POS_TAGIHAN_BERLAKU_JAM', 72),

    // Spanduk pengingat muncul sekian hari sebelum masa aktif habis.
    'pengingat_hari' => 7,

    // QRIS GoPay yang SAMA dengan Aishii Bazar (app/utils/kontak.ts di repo
    // Aishii). Statis: pembayar mengetik nominalnya sendiri.
    'qris' => [
        'gambar' => '/images/qris-aishii.png',
        'nama' => 'AISHII ERP, DIGITAL & KREATIF',
        'nmid' => 'ID1026578292405',
    ],

    // WhatsApp untuk bukti bayar bila konfirmasi otomatis belum tersedia.
    'whatsapp' => env('POS_WHATSAPP_BAYAR', '6283843932121'),

    // Buku tagihan bersama di basis data Aishii (migrasi AS9). Kosong =
    // konfirmasi manual: tagihan bernominal BULAT tanpa kode unik, bukti
    // lewat WhatsApp, pengelola menandai lunas. TIDAK PERNAH memilih kode
    // sendiri — kode yang tidak diperiksa terhadap keranjang Aishii Bazar
    // bisa kembar dengan nominal yang sedang ditunggu Aishii.
    'buku_tagihan' => [
        'url' => env('AISHII_SUPABASE_URL'),
        'kunci_anon' => env('AISHII_SUPABASE_ANON_KEY'),
        'rahasia' => env('AISHII_RAHASIA_POS'),
        'batas_waktu' => 8,
    ],

];
