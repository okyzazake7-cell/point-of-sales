<?php

namespace App\Providers;

use App\Http\Middleware\Penyewaan\BatasiLajuPerToko;
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
            if ($event->input->hasParameterOption('--database')) {
                return;
            }

            throw new RuntimeException("`{$event->command}`: ".self::PESAN_MIGRASI);
        });
    }
}
