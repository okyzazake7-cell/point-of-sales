<?php

namespace App\Penyewaan;

use App\Models\Pusat\DirektoriPengguna;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menjaga direktori surel PUSAT selaras dengan tabel `users` tiap toko.
 *
 * Dipasang sebagai peristiwa model, bukan suntingan di pengendali: pengguna
 * lahir dari empat pintu hulu (wizard, halaman Pengguna, Profil, API) dan
 * pintu kelima pasti datang — aturan yang harus diingat di lima tempat akan
 * terlupa di salah satunya.
 */
class SinkronDirektori
{
    public function __construct(private readonly Penyewaan $penyewaan) {}

    /**
     * Sebelum menyimpan: surel yang sudah dipakai di toko LAIN ditolak dengan
     * pesan di kolom surel — formulir hulu sudah tahu cara menampilkannya.
     */
    public function saving(User $user): void
    {
        $toko = $this->penyewaan->toko();
        if (! $toko || ! $user->isDirty('email')) {
            return;
        }

        $baris = DirektoriPengguna::query()->find(DirektoriPengguna::normalkan($user->email));

        if ($baris && (int) $baris->toko_id !== (int) $toko->getKey()) {
            throw ValidationException::withMessages([
                'email' => 'Surel ini sudah dipakai di toko Aishii POS lain. Pakai surel yang berbeda.',
            ]);
        }
    }

    /**
     * Sesudah tersimpan — dan sesudah transaksi tokonya benar-benar jadi.
     * Direktori yang ditulis lalu transaksinya batal akan menunjuk pengguna
     * yang tidak pernah ada, dan surelnya terkunci dari toko mana pun.
     */
    public function saved(User $user): void
    {
        $toko = $this->penyewaan->toko();
        if (! $toko) {
            return;
        }

        $emailBaru = DirektoriPengguna::normalkan($user->email);
        $emailLama = DirektoriPengguna::normalkan($user->getOriginal('email'));
        $idToko = $toko->getKey();
        $idPengguna = $user->getKey();

        DB::connection()->afterCommit(function () use ($emailBaru, $emailLama, $idToko, $idPengguna) {
            // Surel adalah kunci barisnya, jadi surel yang berganti = baris
            // baru. Tautan akun Aishii (AU1) ikut pindah: tanpa itu, pemilik
            // yang mengganti surelnya di Profil diam-diam tidak bisa masuk lagi.
            $tautan = null;
            if ($emailLama !== '' && $emailLama !== $emailBaru) {
                $lama = DirektoriPengguna::query()
                    ->whereKey($emailLama)
                    ->where('toko_id', $idToko)
                    ->where('user_id', $idPengguna)
                    ->first();
                $tautan = $lama?->aishii_sub;
                $lama?->delete();
            }

            DirektoriPengguna::query()->updateOrCreate(
                ['email' => $emailBaru],
                ['toko_id' => $idToko, 'user_id' => $idPengguna, ...($tautan ? ['aishii_sub' => $tautan] : [])],
            );
        });
    }

    public function deleted(User $user): void
    {
        $toko = $this->penyewaan->toko();
        if (! $toko) {
            return;
        }

        $email = DirektoriPengguna::normalkan($user->getOriginal('email') ?? $user->email);
        $idToko = $toko->getKey();

        DB::connection()->afterCommit(fn () => DirektoriPengguna::query()
            ->whereKey($email)
            ->where('toko_id', $idToko)
            ->delete());
    }
}
