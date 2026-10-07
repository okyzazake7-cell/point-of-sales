# Aishii POS di Google Cloud Run

Rujukan teknis untuk pilihan **E** (dokumen 25 di repo Aishii, diputuskan
pemilik 5 Okt 2026): Cloud Run di Tokyo (`asia-northeast1`), basis data TiDB
Cloud Starter di Tokyo (AWS `ap-northeast-1`), berkas di Cloudflare R2, dan
`pos.aishiierp.com` lewat Worker Cloudflare. Langkah yang dikerjakan PEMILIK
layanan — akun, rahasia, klik di konsol — bernomor di repo Aishii,
`docs/langkah-pemilik-aishii-pos.md`; berkas ini menerangkan apa yang
dibangun di repo ini dan kenapa.

## Jalannya satu permintaan

```
peramban ── pos.aishiierp.com ──▶ Worker Cloudflare (cloudflare/proksi.js)
                                     │  + X-Aishii-Proksi (rahasia)
                                     │  + X-Aishii-Klien (IP pengunjung)
                                     ▼
                     Cloud Run *.run.app (Dockerfile: Apache + PHP 8.4)
                                     │  TerimaProksi: tolak tanpa rahasia,
                                     │  pulihkan IP dan host APP_URL
                                     ▼
              TiDB Tokyo: aishiipos_pusat + aishiipos_<n> per toko (TLS)
              R2: berkas unggahan (POS_BERKAS=r2), dibaca dari domain publik
```

- **Kenapa Worker**: Cloud Run hanya melayani Host `*.run.app`. Pemetaan
  domain bawaannya masih pratinjau dan di Tokyo memutar lewat Amerika;
  penyeimbang beban Google berbayar; Firebase Hosting membuang semua kuki
  kecuali `__session`; di paket Cloudflare gratis hanya Worker yang bisa
  mengganti Host. Batasnya 100 ribu permintaan per hari untuk seluruh akun
  Cloudflare.
- **Kenapa sekota**: satu halaman memanggil basis data 84–159 kali (diukur
  5 Okt) — jarak server–basis data dibayar di tiap panggilan.
- **Yang datang langsung** ke `*.run.app` dijawab 404 oleh `TerimaProksi`,
  kecuali `/up` (pemeriksa kesehatan) dan `/_jadwal` (penjadwal).

## Tiap instans menyala (`docker/mulai.sh`)

1. `pos:siapkan` — migrasi pusat dan SELURUH toko hanya bila daftar berkas
   migrasinya berubah sejak terbit terakhir (sidiknya di tembolok pusat,
   dikunci supaya dua instans tidak memigrasi bersamaan); data wilayah bila
   kosong; akun pengelola bila `POS_PENGELOLA_SUREL` diisi — tanpa sandi
   bila "Masuk dengan akun Aishii" hidup (AU5: pengelola masuk lewat akun
   Aishii, barisnya hanya dilahirkan, tidak pernah ditimpa), atau dengan
   `POS_PENGELOLA_SANDI` bila akun Aishii mati.
2. Apache. Gagal di langkah mana pun = instans tidak pernah menerima
   permintaan, dan Cloud Run tetap melayani revisi lama.

**TANPA `config:cache` dan `route:cache`**, sama dengan hulu dan panduan
VPS: `config/scramble.php` menyimpan objek yang tidak bisa diserialkan
(`config:cache` gagal dengan "value at scramble.security_strategy.1.scheme
is non-serializable" — terukur 5 Okt), dan mode banyak toko menyetel
koneksi saat berjalan. Opcache (`docker/php.ini`) yang menanggung biaya
membaca konfigurasi.

Terukur di TiDB v8.5.3 lokal (5 Okt): `pos:siapkan` pertama 11,5 detik
(migrasi pusat + 83 ribu desa), berikutnya 0,3 detik; migrasi toko baru
sampai ke setiap toko dalam satu nyala. Image yang dibangun dari `Dockerfile`
ini menjawab `/up` ±14 detik sesudah dinyalakan di atas basis data pusat
yang kosong. Satu toko baru di `/daftar` ±21 detik langsung, ±23–24 detik
lewat Worker dan peramban (79 tabel; di MariaDB ±3,5 detik) — halaman
Daftar mengatakannya.

## Penjadwal

Tidak ada cron. Cloud Scheduler memanggil `POST https://<layanan>.run.app/_jadwal`
**tiap menit** dengan kepala `X-Aishii-Jadwal: <POS_RAHASIA_JADWAL>`;
rutenya menjalankan `schedule:run`. Langsung ke `run.app`, bukan lewat
Worker, supaya tidak memakan jatah permintaan Worker. Tiap menit sebab
pembayaran QRIS dijemput tiap menit, dan panggilan rutin membuat instansnya
jarang tidur.

- Perintah terjadwal berjalan sebagai subproses `php artisan …` di dalam
  permintaan itu. Di bawah mod_php `PHP_BINARY` kosong; Laravel menemukan
  biner CLI lewat `PHP_BINDIR` — terbukti 5 Okt lewat probe di kontainer:
  `langganan:periksa` berjalan sebagai `www-data`, 270 ms.
- `langganan:periksa` memakai `withoutOverlapping(5)`, bukan bawaan 24 jam:
  Cloud Run bisa menghentikan instans di tengah perintah (terbitan baru,
  penyusutan), dan kunci yang tertinggal menahan penjemputan sehari.
- Batas waktu permintaan layanan (300 detik) juga batas satu putaran
  penjadwal: tugas harian per toko (`toko:jalankan …`, pukul 01.00–02.00)
  yang kelak melewatinya terpotong. Belum terjadi — diukur ulang bila toko
  sudah puluhan.

## Log

Laravel menulis ke stderr **satu baris JSON per catatan** berikut `severity`
dan jejak tumpukannya (`LOG_STDERR_FORMATTER` di `Dockerfile`,
`formatter_with` di `config/logging.php`). Tanpa itu baris polos masuk Cloud
Logging tanpa tingkat — galat sungguhan tidak bisa disaring sebagai galat.
Di Logs Explorer: saring **Severity ≥ Warning**. Keluaran `pos:siapkan` saat
nyala (stdout) dan baris Apache tidak bertingkat; `AH00163 … resuming normal
operations` di tiap nyala itu normal. `ServerName localhost` di
`docker/apache-pos.conf` hanya membungkam `AH00558` di tiap nyala.

## Variabel lingkungan

Yang sama di setiap pemasangan sudah tertanam di `Dockerfile` (`APP_ENV`,
`LOG_CHANNEL=stderr` + formatter JSON, `POS_MULTI_TOKO`, `POS_BERKAS=r2`,
sesi dan tembolok `database`, `APP_TIMEZONE=Asia/Jakarta`,
`MYSQL_ATTR_SSL_CA`, …). Yang diisi di layanan Cloud Run:

| Variabel | Isi |
| --- | --- |
| `APP_KEY` | rahasia — `base64:` 32 bita acak |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` | host TiDB / `4000` / `aishiipos_pusat` |
| `DB_USERNAME` / `DB_PASSWORD` | pengguna TiDB (berawalan, mis. `xxxx.root`) / rahasia |
| `POS_R2_ENDPOINT` / `POS_R2_BUCKET` / `POS_R2_URL` | `https://<akun>.r2.cloudflarestorage.com` / nama bucket / domain publik bucket |
| `POS_R2_KUNCI_AKSES` / `POS_R2_KUNCI_RAHASIA` | token API R2 — rahasia |
| `POS_RAHASIA_PROKSI` | rahasia — sama dengan `RAHASIA_PROKSI` di Worker |
| `POS_RAHASIA_JADWAL` | rahasia — sama dengan kepala di Cloud Scheduler |
| `AISHII_SUPABASE_URL` / `AISHII_SUPABASE_ANON_KEY` / `AISHII_RAHASIA_POS` | buku tagihan bersama Aishii |
| `AISHII_OIDC_ID_KLIEN` / `AISHII_OIDC_RAHASIA_KLIEN` | "Masuk dengan akun Aishii" (AU1) — klien OAuth "Aishii POS" di Supabase Aishii; rahasianya RAHASIA. Kosong = mati. Diisi SESUDAH migrasi pusat `2026_10_07_000001` terbit |
| `MAIL_*` | SMTP Resend |
| `POS_PENGELOLA_SUREL` | surel akun Aishii pengelola layanan (AU5) — BUKAN rahasia, boleh menetap. Diisi SESUDAH `AISHII_OIDC_*` terisi. Variabel ini hanya MELAHIRKAN: mengosongkannya tidak mencabut siapa pun — yang mencabut, menghapus barisnya di tabel `pengelola` |
| `POS_PENGELOLA_SANDI` | hanya pintu darurat ketika akun Aishii dimatikan (rahasia klien OIDC dikosongkan) — SEMENTARA, dihapus lagi sesudah masuk |

Pengaturan layanan: CPU 1, memori 512 MiB, **konkurensi 10** (sama dengan
`MaxRequestWorkers` di `docker/mpm_prefork.conf`), instans minimum 0,
maksimum 3, tagihan per permintaan.

## Membuktikan di sesi agen

```bash
# MariaDB atau TiDB lokal. TiDB 479/514 (5 Okt): SEMUA yang gagal ada di
# tests/Feature/BanyakToko, yang kerangka ujinya khusus SQLite.
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=4000 DB_DATABASE=pos_tidb_uji \
  DB_USERNAME=root DB_PASSWORD= php artisan test --compact
```

TiDB lokal: `docker run -d --name tidb --network host mirror.gcr.io/pingcap/tidb:v8.5.3`
(Docker Hub menolak tarikan anonim dari sesi agen; cermin Google tidak).

**`Dockerfile` ini belum pernah dibangun UTUH di sesi agen** — build
sungguhan pertamanya terjadi di Cloud Build pada langkah P7 pemilik. Proksi
keluar sesi agen menolak repositori Debian (semua cermin) dan PECL, juga
lewat HTTPS, jadi `install-php-extensions` tidak bisa berjalan di sini. Yang
dibuktikan (5 Okt) adalah salinan di luar repo berbasis Ubuntu 24.04 + PHP
8.3 (apt Ubuntu diizinkan) dengan berkas `docker/` yang SAMA, tahap aset
Node 22 yang sama, dan vendor `--no-dev`: daftar toko lewat Worker →
kontainer → TiDB, logo ke S3 tiruan, penjadwal, dan log JSON. Yang diperiksa
terhadap image resmi tanpa membangunnya: tag `php:8.4-apache-bookworm` ada di
`mirror.gcr.io`; ekstensi bawaannya mencakup seluruh tuntutan paket terkunci
(`composer check-platform-reqs --no-dev`) kecuali `gd` dan `zip`, yang
dipasang; OPcache sudah termuat (penginstal cuma memperingatkan); `Listen 80`
ada di `ports.conf`; log Apache tertaut ke stdout/stderr.
