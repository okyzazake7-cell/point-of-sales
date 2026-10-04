# Menerbitkan Aishii POS di `pos.aishiierp.com`

> **DITUNDA — 4 Okt 2026.** Pemilik memilih **serverless**, bukan VPS
> (gelombang AT, `docs/permintaan-4okt.md` di repo Aishii). Langkah yang
> berlaku sekarang ada di `docs/langkah-pemilik-aishii-pos.md` (repo Aishii,
> bernomor P1–P13), rancangannya di
> `docs/rencana-saas/25-aishii-pos-serverless.md` (repo Aishii). Panduan VPS
> ini tetap di repo sebagai CADANGAN bila serverless ternyata tidak bisa
> dipakai — jangan diikuti tanpa keputusan pemilik yang baru.

Panduan AS10 (`docs/permintaan-3okt.md` di repo Aishii; rancangannya dokumen
24 di sana). Aishii POS berjalan di **server sendiri** — Cloudflare Workers
yang menyajikan Aishii tidak menjalankan PHP — dalam **mode banyak toko**:
satu basis data pusat (daftar toko, tagihan, sesi, wilayah) dan satu basis
data per toko yang lahir saat pemiliknya mendaftar di `/daftar`.

Semua langkah di bawah dijalankan **pemilik layanan**: server, DNS, rahasia
GitHub, dan basis data Aishii adalah milik pemilik, bukan milik repo ini.
Contoh perintahnya untuk Ubuntu 24.04; sesuaikan bila berbeda.

## Urutannya, dan siapa di mana

| # | Langkah | Di mana |
| --- | --- | --- |
| 1 | Rekaman DNS `pos` | Cloudflare, zona `aishiierp.com` |
| 2 | Paket server + pengguna `deploy` | VPS |
| 3 | Pengguna MySQL ber-hak `aishiipos\_%` | VPS |
| 4 | Folder aplikasi + `.env` | VPS |
| 5 | nginx + HTTPS | VPS |
| 6 | Penyiapan pertama: migrasi pusat, wilayah, akun pengelola | VPS |
| 7 | Penjadwal (cron) | VPS |
| 8 | Deploy otomatis tiap push ke `main` | GitHub fork ini |
| 9 | Buku tagihan bersama (kode unik QRIS) | Supabase Aishii + Pintu Pengelola + `.env` |
| 10 | Halaman `/pos` Aishii menunjuk ke sini | Cloudflare Workers Builds (Aishii) |
| 11 | Uji asap | di mana saja |

Langkah 9 boleh menyusul: sebelum ia selesai, tagihan langganan bernominal
BULAT dan dikonfirmasi manual oleh pengelola — aplikasi tidak pernah memilih
kode unik sendiri, sebab kode pilihan sendiri bisa kembar dengan keranjang
Aishii Bazar yang dibayar ke QRIS yang sama.

## 1. DNS

Cloudflare → zona `aishiierp.com` → DNS → **Add record**: tipe `A`, nama
`pos`, isi IP VPS, **Proxy status: DNS only** (awan abu-abu).

- Workers Aishii hanya memegang `aishiierp.com` sebagai domain kustom
  (`wrangler.jsonc` di repo Aishii) — tidak ada rute wildcard yang menelan
  `pos`.
- **Kenapa DNS only**: lewat proxy Cloudflare, alamat pengunjung yang
  dilihat Laravel adalah alamat Cloudflare, dan pembatas laju masuk/daftar
  menghitung semua orang sebagai satu. Bila kelak ingin proxy, pasang dulu
  `set_real_ip_from` rentang Cloudflare di nginx dan SSL/TLS **Full
  (strict)** — "Flexible" membuat putaran pengalihan tanpa ujung.

## 2. Server

```bash
sudo apt update
sudo apt install -y nginx mysql-server git unzip curl software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-sqlite3 php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-exif
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Pengguna yang memiliki folder aplikasi dan menjalankan deploy.
sudo adduser --disabled-password --gecos "" deploy
sudo usermod -aG www-data deploy

# Node 22 untuk build aset — lewat nvm milik deploy (deploy.yml memuatnya).
sudo -u deploy bash -c 'curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash \
  && . ~/.nvm/nvm.sh && nvm install 22'
```

PHP minimal 8.3 (`composer.json`); CI repo ini berjalan di 8.4.

## 3. MySQL

```sql
-- sudo mysql
CREATE USER 'aishiipos'@'localhost' IDENTIFIED BY 'SANDI-PANJANG-ACAK';
CREATE DATABASE aishiipos_pusat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `aishiipos\_%`.* TO 'aishiipos'@'localhost';
FLUSH PRIVILEGES;
```

- Tiap toko yang mendaftar mendapat basis data `aishiipos_<nomor toko>`,
  dibuat aplikasi saat itu juga. Satu grant berpola itu mencakup pusat DAN
  seluruh toko, dan tidak ada basis data lain di server.
- Garis miring terbalik di `aishiipos\_%` wajib: tanpa itu `_` berarti
  "satu huruf apa saja".
- Nama pusat sengaja berawalan sama; ia tidak bisa bentrok dengan toko,
  sebab nama toko memakai NOMOR, bukan kode tautannya.

## 4. Folder dan `.env`

```bash
sudo mkdir -p /var/www/pos.aishiierp.com && sudo chown deploy:deploy /var/www/pos.aishiierp.com
sudo -u deploy git clone https://github.com/okyzazake7-cell/point-of-sales.git /var/www/pos.aishiierp.com
cd /var/www/pos.aishiierp.com
sudo -u deploy cp .env.example .env
```

Bila repo ini privat, `git clone`/`git fetch` di server butuh *deploy key*:
buat kunci SSH untuk `deploy`, tambahkan publiknya di GitHub → Settings →
Deploy keys (baca saja), lalu clone lewat alamat `git@github.com:…`.

Isi `.env` yang WAJIB diubah dari `.env.example`:

| Kunci | Nilai | Kenapa |
| --- | --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | jejak galat tidak boleh tampil ke pengunjung |
| `APP_URL` | `https://pos.aishiierp.com` | tautan publik toko, tagihan, dan surel dibangun dari sini |
| `APP_TIMEZONE` | `Asia/Jakarta` | **setel SEBELUM toko pertama lahir.** Bawaannya UTC: "penjualan hari ini" terpotong pukul 07.00 WIB. Mengubahnya sesudah ada data menggeser jam yang sudah tersimpan |
| `SESSION_SECURE_COOKIE` | `true` | kuki sesi hanya lewat HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | `pos.aishiierp.com` | nilai contoh berisi `localhost` |
| `POS_MULTI_TOKO` | `true` | mode banyak toko; deploy MENOLAK jalan tanpanya |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `aishiipos_pusat` / `aishiipos` / sandi langkah 3 | `DB_*` adalah basis data PUSAT |
| `QUEUE_CONNECTION` | `sync` | tidak ada pekerjaan yang diantrekan, dan pekerja antrean tidak membawa konteks toko — yang ia tulis akan mendarat di pusat |
| `INERTIA_SSR_ENABLED` | `false` | `npm run build` tidak membangun bundel SSR; tanpa ini tidak ada yang berubah selain satu pemeriksaan sia-sia tiap permintaan |
| `MAIL_MAILER` dkk. | `smtp`, `smtp.resend.com`, `587`, `tls`, pengguna `resend`, sandi = kunci API Resend | surel lupa sandi; domain `aishiierp.com` sudah terverifikasi di Resend untuk Aishii |
| `MAIL_FROM_ADDRESS` | mis. `pos@aishiierp.com` | |
| `AISHII_SUPABASE_URL` / `AISHII_SUPABASE_ANON_KEY` / `AISHII_RAHASIA_POS` | lihat langkah 9 | kosong = konfirmasi manual |

`POS_HARGA_OUTLET` (25000), `POS_TAGIHAN_BERLAKU_JAM` (72), dan
`POS_WHATSAPP_BAYAR` sudah benar di `.env.example`. Harga cukup diubah di
sana — halaman `/pos` Aishii membacanya dari `GET /api/harga`.

## 5. nginx dan HTTPS

`/etc/nginx/sites-available/pos.aishiierp.com`:

```nginx
server {
    listen 80;
    server_name pos.aishiierp.com;
    root /var/www/pos.aishiierp.com/public;
    index index.php;
    client_max_body_size 20M;   # unggah gambar produk dan impor CSV

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/pos.aishiierp.com /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d pos.aishiierp.com
```

## 6. Penyiapan pertama

```bash
cd /var/www/pos.aishiierp.com
sudo -u deploy composer install --no-dev --optimize-autoloader
sudo -u deploy php8.4 artisan key:generate
sudo -u deploy bash -lc '. ~/.nvm/nvm.sh && cd /var/www/pos.aishiierp.com && npm ci && npm run build'
sudo -u deploy php8.4 artisan pusat:migrasi --force
sudo -u deploy php8.4 artisan pusat:wilayah
sudo -u deploy php8.4 artisan storage:link
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R g+w storage bootstrap/cache
sudo -u deploy php8.4 artisan pengelola:buat SUREL-PENGELOLA
```

- **`php artisan migrate` biasa DITOLAK di mode ini** — itu bukan
  kerusakan: ia akan menulis tabel toko ke basis data pusat. Pusat lewat
  `pusat:migrasi`, seluruh toko lewat `toko:migrasi`.
- **`pusat:wilayah`** mengisi provinsi sampai desa (83.762 desa; 5 detik di
  SQLite uji, 4,2 detik di MariaDB 10.11 — terukur 4 Okt) — formulir alamat
  pelanggan dan member membacanya. Perintah
  hulu `laravolt:indonesia:seed` ditolak pagar yang sama (AS16), jadi
  jangan dipakai. Aman diulang: begitu terisi, ia berhenti seketika.
- **`pengelola:buat`** menanyakan sandi (minimal 12 huruf). Akun ini masuk
  di `/pengelola/masuk` untuk menandai lunas tagihan yang dibayar manual;
  setiap tindakannya wajib beralasan dan tercatat.
- Tidak ada toko contoh dan tidak ada data demo: toko lahir dari `/daftar`.
  Jangan menjalankan `seed:demo` di server ini.

## 7. Penjadwal

```bash
sudo crontab -u deploy -e
# tambahkan satu baris:
* * * * * cd /var/www/pos.aishiierp.com && php8.4 artisan schedule:run >> /dev/null 2>&1
```

Yang dijalankannya: `langganan:periksa` tiap menit (menjemput status
pembayaran dari buku tagihan Aishii — tanpa ini tagihan yang sudah dibayar
baru aktif saat pemilik toko menekan "Periksa pembayaran"), dan tugas harian
per toko (`crm:*`, `reorder:generate`, `transactions:expire`) lewat
`toko:jalankan`.

## 8. Deploy otomatis

`.github/workflows/deploy.yml` menerbitkan tiap push ke `main` sesudah CI
hijau — **hanya bila variabel `POS_DEPLOY` bernilai `aktif`**. Sebelum itu
push ke `main` cukup menjalankan CI.

**Nyalakan Actions di fork ini lebih dulu.** GitHub mematikan workflow di
repo hasil fork sampai pemiliknya menyalakannya: tab **Actions** → "I
understand my workflows, go ahead and enable them". Terukur 4 Okt: fork ini
belum pernah menjalankan satu workflow pun — PR #1 tidak punya satu
pemeriksaan CI, dan tanpa langkah ini `POS_DEPLOY=aktif` tidak menerbitkan
apa pun, tanpa pesan galat.

GitHub → repo `okyzazake7-cell/point-of-sales` → Settings → Secrets and
variables → Actions:

| Jenis | Nama | Isi |
| --- | --- | --- |
| Secret | `VPS_HOST` | IP atau nama VPS |
| Secret | `VPS_USER` | `deploy` |
| Secret | `VPS_SSH_KEY` | kunci privat yang publiknya ada di `~deploy/.ssh/authorized_keys` |
| Variable | `POS_DEPLOY` | `aktif` — nyalakan SESUDAH langkah 1–7 |
| Variable (opsional) | `POS_DIR`, `POS_URL`, `POS_PHP`, `POS_USER`, `VPS_PORT` | bawaan: `/var/www/pos.aishiierp.com`, `https://pos.aishiierp.com`, `php8.4`, `deploy`, `22` |

Deploy memanggil tiga perintah ber-`sudo`; izinkan ketiganya saja
(`sudo visudo -f /etc/sudoers.d/aishiipos`):

```
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm, /usr/bin/chown -R deploy\:www-data storage bootstrap/cache, /usr/bin/chmod -R g+w storage bootstrap/cache
```

Urutan tiap deploy: tolak bila `.env` belum `POS_MULTI_TOKO=true` → kode
`main` terbaru → composer → `npm ci && npm run build` → `pusat:migrasi` →
`pusat:wilayah` → `toko:migrasi` (SELURUH toko) → bersihkan tembolok →
hak berkas → muat ulang PHP-FPM → `scripts/uji-asap-pos.sh`.

## 9. Buku tagihan bersama (kode unik QRIS)

Aishii POS dibayar ke QRIS GoPay "AISHII ERP" yang sama dengan Aishii
Bazar. Kode unik tagihannya dipilih di basis data Aishii supaya tidak
pernah kembar dengan keranjang Aishii yang sedang menunggu.

1. **Repo Aishii**: jalankan `supabase/migrations/20261153090000_tagihan_pos_bersama.sql`
   di SQL Editor Supabase, lalu kueri verifikasi read-only yang ditulis
   agen (protokol migrasi Aishii).
2. **Pintu Pengelola Aishii → Pembayaran otomatis → kartu "Rahasia Aishii
   POS"**: pilih alasan, tekan "Buat rahasia POS", salin rahasianya —
   **tampil sekali**. Kartu yang sama mencetak dua baris `.env` lainnya.
3. Di server: isi `AISHII_SUPABASE_URL`, `AISHII_SUPABASE_ANON_KEY` (kunci
   *anon* Aishii — kunci publik yang sama dengan yang dipakai peramban), dan
   `AISHII_RAHASIA_POS`, lalu `sudo -u deploy php8.4 artisan config:clear`.
4. Periksa: pemilik toko → Langganan → buat tagihan. Nominalnya kini
   berekor kode unik (mis. Rp 25.417), bukan bulat.

Rahasia POS hanya bisa MEMBUAT, MEMBACA, dan MEMBATALKAN tagihan POS — yang
menandai lunas tetap hanya notifikasi pembayaran. Server ini yang bocor tidak
bisa memberi dirinya langganan.

## 10. Halaman `/pos` Aishii

Cloudflare → Workers & Pages → `aishii` → Settings → Build → Variables:
`NUXT_PUBLIC_POS_URL` = `https://pos.aishiierp.com`, lalu bangun ulang
(alamat itu dibaca saat MEMBANGUN, bukan saat berjalan). Sesudahnya `/pos`
menggambar "Daftarkan toko", "Buka Aishii POS", dan harga yang dibaca dari
`/api/harga` server ini.

## 11. Uji asap

```bash
bash scripts/uji-asap-pos.sh https://pos.aishiierp.com
```

Tiap baris memeriksa sesuatu yang HANYA benar di server banyak toko yang
siap jualan (`/daftar` ada, `/api/harga` menjawab, API menuntut `X-Toko`,
kepala CORS untuk `/pos` Aishii) — dan menyebut alasannya bila meleset.
Deploy otomatis menjalankannya di ujung; terhadap instalasi satu toko ia
merah di empat baris.

## Yang sengaja TIDAK dilakukan

- **Tanpa pekerja antrean** (`queue:work`): tidak ada pekerjaan yang
  diantrekan, dan pekerja tidak membawa konteks toko.
- **Tanpa SSR** dan tanpa layanan WhatsApp/Midtrans/Xendit di penyiapan
  awal: semuanya fitur hulu yang opsional per toko, bukan syarat jualan.
- **Tanpa `config:cache`/`route:cache`**: deploy membersihkan, tidak
  menembolok — sama dengan hulu, dan mode banyak toko menyetel koneksi saat
  berjalan.
