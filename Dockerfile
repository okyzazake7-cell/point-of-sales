# Aishii POS untuk Google Cloud Run (dokumen 25 di repo Aishii, pilihan E).
#
# Satu kontainer: Apache + PHP 8.4 (mod_php) yang mendengar $PORT. Disk
# kontainer hilang tiap instans bangun ulang, jadi tidak ada yang disimpan
# di sini: berkas unggahan di R2 (POS_BERKAS=r2), sesi dan tembolok di basis
# data pusat, log ke stderr. Image dasar ditarik lewat mirror.gcr.io — cermin
# Docker Hub milik Google — supaya Cloud Build tidak terkena batas tarik
# anonim Docker Hub.

# --- 1. Aset React/Inertia -------------------------------------------------
FROM mirror.gcr.io/library/node:22-bookworm-slim AS aset
WORKDIR /aplikasi
COPY package.json package-lock.json ./
# Puppeteer (untuk skrip audit ponsel di sesi pengembang) mengunduh Chrome
# ±170 MB saat dipasang — tidak berguna di image dan bisa menggagalkan build.
ENV PUPPETEER_SKIP_DOWNLOAD=1
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js postcss.config.js tailwind.config.js jsconfig.json ./
RUN npm run build

# --- 2. Paket PHP ----------------------------------------------------------
FROM mirror.gcr.io/library/composer:2 AS vendor
WORKDIR /aplikasi
COPY composer.json composer.lock ./
# Ekstensi diperiksa di image akhir, bukan di image composer. Tanpa
# --prefer-dist: unduhan arsip GitHub tanpa token dibatasi 60 per jam per
# alamat, dan composer yang boleh jatuh ke `git clone` tetap selesai.
RUN composer install --no-dev --no-interaction --no-progress \
        --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- 3. Image yang dijalankan ---------------------------------------------
FROM mirror.gcr.io/library/php:8.4-apache-bookworm

# Penginstal ekstensi dikunci versinya: build yang sama harus menghasilkan
# image yang sama. Modul yang sudah termuat di image dasar (OPcache, per
# 5 Okt) hanya diberi peringatan, bukan gagal. Image dasar berjalan TANPA
# php.ini — display_errors menyala — jadi php.ini produksi bawaannya dipasang;
# docker/php.ini di bawah menimpa sisanya.
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.34/install-php-extensions /usr/local/bin/
RUN chmod 0755 /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_mysql gd zip intl exif opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-aishii-pos.ini
COPY docker/apache-pos.conf /etc/apache2/sites-available/000-default.conf
COPY docker/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
    && a2enmod rewrite headers \
    && a2disconf other-vhosts-access-log

WORKDIR /var/www/html
COPY --from=vendor /aplikasi /var/www/html
COPY --from=aset /aplikasi/public/build /var/www/html/public/build
COPY docker/mulai.sh /usr/local/bin/mulai-pos
RUN chmod 0755 /usr/local/bin/mulai-pos \
    && rm -rf tests docker docs whatsapp-service node_modules cloudflare \
    && php artisan package:discover --no-ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Nilai yang sama di setiap pemasangan Cloud Run. Rahasia dan nilai milik
# pemilik (APP_KEY, DB_*, POS_R2_*, POS_RAHASIA_*, AISHII_*, surel) diisi di
# layanan Cloud Run, bukan di sini. Log: baris polos masuk Cloud Logging
# TANPA tingkat, jadi galat sungguhan tidak bisa disaring sebagai galat;
# formatter Google menulis satu baris JSON per catatan berikut `severity` dan
# jejak tumpukannya (config/logging.php).
ENV PORT=8080 \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_NAME="Aishii POS" \
    APP_URL=https://pos.aishiierp.com \
    APP_TIMEZONE=Asia/Jakarta \
    LOG_CHANNEL=stderr \
    LOG_STDERR_FORMATTER=Monolog\\Formatter\\GoogleCloudLoggingFormatter \
    DB_CONNECTION=mysql \
    POS_MULTI_TOKO=true \
    POS_AWALAN_DB=aishiipos_ \
    POS_BERKAS=r2 \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=database \
    SESSION_SECURE_COOKIE=true \
    CACHE_STORE=database \
    INERTIA_SSR_ENABLED=false \
    MYSQL_ATTR_SSL_CA=/etc/ssl/certs/ca-certificates.crt

EXPOSE 8080
CMD ["mulai-pos"]
