<?php

namespace App\AkunAishii;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bukti "sandi akun Aishii baru saja dimasukkan" untuk konfirmasi tindakan
 * penting (AV14, dokumen 29 di repo Aishii).
 *
 * Tidak satu pun token yang sampai ke sini membawa saat sandi dimasukkan:
 * `auth_time` token ID (H8) dan `amr` token akses (H10) sama-sama dicap saat
 * token TERBIT. Cap itu hanya hidup di token sesi peramban `aishiierp.com`,
 * jadi basis data Aishii yang mencatat buktinya: `/masuk-ulang` memanggil
 * `catat_bukti_konfirmasi(kode)` dengan token sesinya sendiri, dan server ini
 * memakai bukti itu SEKALI lewat `pos_pakai_bukti_konfirmasi` — pintu yang
 * sama dengan buku tagihan bersama (kunci anon + `AISHII_RAHASIA_POS`).
 *
 * KODE PENGIKAT lahir di sini dan hidup di sesi: bukti hanya bisa dipakai
 * sesi POS yang membuat kodenya. Kodenya bukan rahasia — tanpa token sesi
 * yang segar ia tidak bisa mencatat apa pun, tanpa rahasia POS ia tidak bisa
 * memakai apa pun.
 *
 * Galat apa pun (jaringan, rahasia ditolak, konfigurasi kosong, fungsi yang
 * hilang) → `tak_terjangkau`, dan pemanggilnya MENOLAK: penjaga yang tidak
 * bisa bertanya tidak boleh menjawab "boleh". Fungsi yang hilang (PGRST202)
 * dulu jatuh ke penjaga lama selama migrasi 20261156 belum dijalankan; sejak
 * migrasinya terverifikasi (P30, 8 Okt) cabang itu dicabut, sebab penjaga
 * lama — `auth_time` — selalu lulus (H8).
 */
class BuktiKonfirmasi
{
    private const KODE = 'akun_aishii.kode_konfirmasi';

    /** Detik kode pengikat bertahan di sesi; kesegaran sandi dijaga basis data. */
    private const UMUR_KODE = 1800;

    /** Kode pengikat permintaan konfirmasi sesi ini — dipakai ulang sampai terpakai atau basi. */
    public function kode(Request $request): string
    {
        $simpan = $request->session()->get(self::KODE);
        if (is_array($simpan) && is_string($simpan['kode'] ?? null) && ($simpan['sampai'] ?? 0) >= time()) {
            return $simpan['kode'];
        }

        $kode = Str::random(40);
        $request->session()->put(self::KODE, ['kode' => $kode, 'sampai' => time() + self::UMUR_KODE]);

        return $kode;
    }

    /**
     * Pakai bukti untuk akun Aishii `$sub` SEKALI.
     *
     * @return 'dipakai'|'basi'|'sudah_dipakai'|'tidak_ada'|'tanpa_kode'|'tak_terjangkau'
     */
    public function pakai(Request $request, string $sub): string
    {
        $simpan = $request->session()->get(self::KODE);
        if (! is_array($simpan) || ! is_string($simpan['kode'] ?? null) || ($simpan['sampai'] ?? 0) < time()) {
            return 'tanpa_kode';
        }

        $c = config('langganan.buku_tagihan');
        if (blank($c['url'] ?? null) || blank($c['kunci_anon'] ?? null) || blank($c['rahasia'] ?? null)) {
            Log::warning('Bukti konfirmasi Aishii tidak bisa diperiksa: AISHII_SUPABASE_URL, AISHII_SUPABASE_ANON_KEY, atau AISHII_RAHASIA_POS kosong.');

            return 'tak_terjangkau';
        }

        try {
            $jawaban = Http::timeout((int) $c['batas_waktu'])
                ->withHeaders(['apikey' => $c['kunci_anon']])
                ->withToken($c['kunci_anon'])
                ->acceptJson()
                ->post(rtrim($c['url'], '/').'/rest/v1/rpc/pos_pakai_bukti_konfirmasi', [
                    'p_rahasia' => $c['rahasia'],
                    'p_sub' => $sub,
                    'p_kode' => $simpan['kode'],
                ]);
        } catch (Throwable $e) {
            Log::warning('Bukti konfirmasi Aishii tak terjangkau.', ['galat' => $e->getMessage()]);

            return 'tak_terjangkau';
        }

        if (! $jawaban->successful()) {
            Log::warning('Bukti konfirmasi Aishii menolak panggilan.', ['status' => $jawaban->status(), 'kode' => $jawaban->json('code')]);

            return 'tak_terjangkau';
        }

        $status = $jawaban->json('status');
        if ($status === 'dipakai') {
            $request->session()->forget(self::KODE);
        }
        if (in_array($status, ['dipakai', 'basi', 'sudah_dipakai', 'tidak_ada'], true)) {
            return $status;
        }

        Log::warning('Bukti konfirmasi Aishii menjawab di luar dugaan — periksa AISHII_RAHASIA_POS.', ['status' => $status]);

        return 'tak_terjangkau';
    }
}
