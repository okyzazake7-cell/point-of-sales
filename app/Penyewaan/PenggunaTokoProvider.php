<?php

namespace App\Penyewaan;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Penyedia pengguna mode banyak toko: TANPA toko, tidak ada siapa pun.
 *
 * Tabel `users` hanya ada di basis data toko. Tanpa penjaga ini, surel yang
 * tidak dikenal direktori membuat Auth::attempt bertanya ke basis data
 * PUSAT dan pecah menjadi 500 — padahal jawaban yang benar persis sama
 * dengan sandi salah, supaya halaman masuk tidak membocorkan surel mana yang
 * terdaftar.
 */
class PenggunaTokoProvider extends EloquentUserProvider
{
    private function adaToko(): bool
    {
        return app(Penyewaan::class)->toko() !== null;
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->adaToko() ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        return $this->adaToko() ? parent::retrieveByToken($identifier, $token) : null;
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?Authenticatable
    {
        return $this->adaToko() ? parent::retrieveByCredentials($credentials) : null;
    }
}
