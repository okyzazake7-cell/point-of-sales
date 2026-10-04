<?php

namespace App\Providers;

use App\Http\Middleware\Penyewaan\BatasiLajuPerToko;
use App\Langganan\Harga;
use App\Langganan\Langganan;
use App\Models\Outlet;
use App\Models\User;
use App\Penyewaan\PenggunaTokoProvider;
use App\Penyewaan\Penyewaan;
use App\Penyewaan\SinkronDirektori;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Mode banyak toko (dokumen 24 di repo Aishii). Dalam mode satu toko
 * penyedia ini hanya mendaftarkan layanan `Penyewaan` yang tidak melakukan
 * apa pun — seluruh perilaku hulu tetap.
 */
class PenyewaanServiceProvider extends ServiceProvider
{
    /** Perintah yang tanpa --database akan menulis migrasi TOKO ke basis data PUSAT. */
    private const PERINTAH_BERBAHAYA = [
        'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset',
        'migrate:rollback', 'db:seed', 'db:wipe',
    ];

    private const PESAN_MIGRASI = 'Mode banyak toko: tanpa --database, perintah ini menulis ke basis data PUSAT. '
        .'Pakai `php artisan pusat:migrasi` untuk pusat dan `php artisan toko:migrasi` untuk seluruh toko.';

    public function register(): void
    {
        $this->app->singleton(Penyewaan::class);

        if (! config('penyewaan.aktif')) {
            return;
        }

        $this->app->make(Penyewaan::class)->pasangKoneksi();

        config([
            'auth.providers.users.driver' => 'pengguna_toko',
            // Penjaga yang tidak mengenal siapa pun: dipakai tautan publik toko
            // B yang dibuka orang yang sedang masuk ke toko A (KenaliTokoDariJalur).
            'auth.guards.tamu_publik' => ['driver' => 'session', 'provider' => 'users'],
            // Toko baru lahir hanya lewat /daftar, yang membuat basis datanya.
            'security.auth.public_registration' => false,
        ]);

        $this->app->bind(ThrottleRequests::class, BatasiLajuPerToko::class);
    }

    public function boot(): void
    {
        if (! config('penyewaan.aktif')) {
            return;
        }

        Auth::provider('pengguna_toko', fn ($app, array $config) => new PenggunaTokoProvider($app['hash'], $config['model']));

        // Toko yang sedang dibuka, untuk layar: antrean luring dan tembolok
        // service worker dipisah per toko (AS12, resources/js/app.jsx).
        Inertia::share('toko', fn () => $this->app->make(Penyewaan::class)->toko()?->only(['id', 'kode', 'nama']));
        Inertia::share('banyakToko', true);

        // Status langganan untuk spanduk tata letak dan layar kasir (dokumen
        // 24 §6) — ikut tiap halaman toko, termasuk sesudah toko terkunci.
        Inertia::share('langganan', function () {
            $toko = $this->app->make(Penyewaan::class)->toko();

            return $toko && Auth::guard('web')->check()
                ? $this->app->make(Langganan::class)->ringkasan($toko)
                : null;
        });

        // Kursi outlet (dokumen 24 §5): menyalakan penjualan di outlet ke-N+1
        // ditolak dengan kalimat yang menyebut biayanya. Dipasang di model,
        // bukan di pengendali Outlet hulu: outlet juga lahir dari wizard,
        // impor, dan API.
        Outlet::saving(fn (Outlet $outlet) => $this->periksaKursiOutlet($outlet));

        $sinkron = $this->app->make(SinkronDirektori::class);
        User::saving(fn (User $user) => $sinkron->saving($user));
        User::saved(fn (User $user) => $sinkron->saved($user));
        User::deleted(fn (User $user) => $sinkron->deleted($user));

        Event::listen(Login::class, function (Login $event) {
            $toko = $this->app->make(Penyewaan::class)->toko();
            if ($event->guard !== 'web' || ! $toko) {
                return;
            }

            session()->put('toko_id', $toko->getKey());
            if ($event->remember) {
                Cookie::queue(Cookie::forever(config('penyewaan.cookie_toko'), (string) $toko->getKey()));
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->guard === 'web') {
                Cookie::queue(Cookie::forget(config('penyewaan.cookie_toko')));
            }
        });

        // Lapis kedua, di tingkat migrator: menangkap juga migrasi yang
        // dipanggil dari kode (Artisan::call), yang tidak melewati
        // CommandStarting. Migrasi tanpa --database memakai koneksi bawaan,
        // dan koneksi bawaan di luar toko adalah PUSAT.
        Event::listen(MigrationsStarted::class, function () {
            if ($this->app->make('migrator')->getConnection() === null
                && $this->app->make(Penyewaan::class)->toko() === null) {
                throw new RuntimeException(self::PESAN_MIGRASI);
            }
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (! in_array($event->command, self::PERINTAH_BERBAHAYA, true)) {
                return;
            }
            // Di dalam toko (toko:jalankan) koneksi bawaan memang basis data
            // toko itu — `db:seed` milik seed:demo, misalnya, mengenai toko yang benar.
            if ($event->input->hasParameterOption('--database') || $this->app->make(Penyewaan::class)->toko()) {
                return;
            }

            throw new RuntimeException("`{$event->command}`: ".self::PESAN_MIGRASI);
        });
    }

    private function periksaKursiOutlet(Outlet $outlet): void
    {
        $toko = $this->app->make(Penyewaan::class)->toko();
        if (! $toko) {
            return;
        }

        $akanBerjualan = $outlet->is_active && $outlet->is_sales_enabled;
        $sudahBerjualan = $outlet->exists && $outlet->getOriginal('is_active') && $outlet->getOriginal('is_sales_enabled');
        if (! $akanBerjualan || $sudahBerjualan) {
            return;
        }

        $lain = Outlet::query()
            ->where('is_active', true)
            ->where('is_sales_enabled', true)
            ->when($outlet->exists, fn ($q) => $q->whereKeyNot($outlet->getKey()))
            ->count();

        if ($lain + 1 <= (int) $toko->kursi_outlet) {
            return;
        }

        $langganan = $this->app->make(Langganan::class);
        $kalimat = $langganan->terkunci($toko)
            ? 'Langganan sedang tidak aktif. Perpanjang di menu Langganan sambil memilih jumlah outlet yang berjualan.'
            : sprintf(
                'Paket Anda untuk %d outlet berjualan. Tambah 1 outlet di menu Langganan — Rp %s untuk sisa %d hari masa aktif.',
                (int) $toko->kursi_outlet,
                number_format(Harga::tambahOutlet(1, Harga::sisaHari($toko->aktif_sampai, now())), 0, ',', '.'),
                Harga::sisaHari($toko->aktif_sampai, now()),
            );

        throw ValidationException::withMessages(['is_sales_enabled' => $kalimat]);
    }
}
