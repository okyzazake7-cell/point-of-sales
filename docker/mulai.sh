#!/bin/sh
# Dijalankan tiap instans Cloud Run menyala (dokumen 25 di repo Aishii).
# Gagal di sini = instans tidak pernah menerima permintaan, dan Cloud Run
# tetap melayani revisi lama — terbit yang rusak tidak menggantikan yang jalan.
set -e
cd /var/www/html

# TANPA config:cache/route:cache, sama dengan hulu dan panduan VPS:
# config/scramble.php menyimpan objek yang tidak bisa diserialkan
# (config:cache gagal — terukur 5 Okt), dan mode banyak toko menyetel
# koneksi saat berjalan. Opcache yang menanggung biaya membaca konfigurasi.

# Migrasi bila ada yang baru, wilayah bila kosong, pengelola dari env.
php artisan pos:siapkan --no-ansi

chown -R www-data:www-data storage bootstrap/cache
exec apache2-foreground
