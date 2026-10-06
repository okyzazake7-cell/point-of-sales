<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
        ],

        /*
         * Berkas unggahan: logo toko dan bank, avatar, gambar produk dan
         * kategori. Di Cloud Run disk lokal hilang tiap kali instans bangun
         * ulang, jadi di sana `POS_BERKAS=r2` mengarahkannya ke Cloudflare
         * R2 lewat API S3 (dokumen 25 di repo Aishii). Tanpa nilai itu
         * perilakunya persis hulu: disk lokal yang dibaca lewat /storage.
         */
        'public' => env('POS_BERKAS') === 'r2' ? [
            'driver' => 's3',
            'key' => env('POS_R2_KUNCI_AKSES'),
            'secret' => env('POS_R2_KUNCI_RAHASIA'),
            'region' => 'auto',
            'bucket' => env('POS_R2_BUCKET'),
            // https://<id-akun>.r2.cloudflarestorage.com
            'endpoint' => env('POS_R2_ENDPOINT'),
            'use_path_style_endpoint' => true,
            // Domain publik bucket-nya, mis. https://berkas-pos.aishiierp.com:
            // yang membuat berkasnya terbaca umum adalah domain itu, bukan ACL.
            'url' => env('POS_R2_URL'),
            // R2 tidak mengenal ACL per objek. Ia menerima `private` lalu
            // mengabaikannya, tetapi MENOLAK `public-read` (NotImplemented) —
            // `public` di sini membuat SETIAP unggahan gagal.
            'visibility' => 'private',
            // SDK AWS terbaru menambahkan checksum yang tidak semua layanan
            // S3-kompatibel terima; cukup saat diwajibkan.
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            // Gagal menyimpan harus BERBUNYI. Di disk lokal hulu `store()`
            // memulangkan false dan nilai itu tersimpan sebagai nama berkas.
            'throw' => true,
        ] : [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
