<?php

namespace App\AkunAishii;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use OpenSSLAsymmetricKey;
use Throwable;

/**
 * Klien OIDC "Masuk dengan akun Aishii" (AU1, dokumen 27 §4 di repo Aishii).
 *
 * Ditulis sendiri, bukan Socialite + penyedia OIDC generik (H4): fork ini
 * menarik hulunya terus-menerus, dan tiap dependensi baru adalah satu hal
 * lagi yang bentrok di tiap tarikan — padahal yang dibutuhkan cuma satu
 * pengalihan, satu penukaran kode, dan satu tanda tangan ES256. Seluruhnya
 * diuji melawan penyedia tiruan (tests/Feature/BanyakToko/MasukAishiiTest.php).
 */
class KlienAishii
{
    public const KALIMAT_ULANGI = 'Masuk dengan akun Aishii belum berhasil. Coba sekali lagi.';

    public const KALIMAT_PUTUS = 'Server akun Aishii belum bisa dihubungi. Periksa sinyal, lalu coba lagi.';

    /**
     * Kunci ringkasan `amr` token akses di klaim yang dipulangkan `tukar()`
     * (H10, AV14). Bergaris bawah supaya tidak pernah bertabrakan dengan
     * klaim Supabase yang sungguhan.
     */
    public const AMR_TOKEN_AKSES = '_amr_token_akses';

    public function aktif(): bool
    {
        return (bool) config('penyewaan.aktif')
            && config('akun_aishii.penerbit') !== ''
            && config('akun_aishii.id_klien') !== ''
            && config('akun_aishii.rahasia_klien') !== '';
    }

    /**
     * Permintaan otorisasi baru: alamat titik otorisasi Supabase, dan bekal
     * yang wajib disimpan di sesi sampai orangnya kembali.
     *
     * @return array{alamat: string, bekal: array{state: string, nonce: string, pemverifikasi: string, alamat_balik: string}}
     */
    public function mulai(string $alamatBalik): array
    {
        $bekal = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            // RFC 7636: 43–128 karakter; huruf dan angka acak.
            'pemverifikasi' => Str::random(64),
            'alamat_balik' => $alamatBalik,
        ];

        $kueri = http_build_query([
            'response_type' => 'code',
            'client_id' => config('akun_aishii.id_klien'),
            'redirect_uri' => $alamatBalik,
            'scope' => config('akun_aishii.cakupan'),
            'state' => $bekal['state'],
            'nonce' => $bekal['nonce'],
            'code_challenge' => self::keB64url(hash('sha256', $bekal['pemverifikasi'], true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['alamat' => config('akun_aishii.penerbit').'/oauth/authorize?'.$kueri, 'bekal' => $bekal];
    }

    /**
     * Menukar kode otorisasi dengan token, lalu memverifikasi token ID-nya.
     * Token akses dan token penyegarnya TIDAK disimpan: sesudah masuk, POS
     * bekerja dengan sesinya sendiri seperti biasa.
     *
     * @param  array{nonce: string, pemverifikasi: string, alamat_balik: string}  $bekal
     * @return array<string, mixed> klaim token ID yang sudah terverifikasi
     */
    public function tukar(string $kode, array $bekal): array
    {
        try {
            $jawaban = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                // client_secret_basic — bawaan klien rahasia Supabase, dan
                // Supabase menolak cara lain dari yang didaftarkan.
                ->withBasicAuth(config('akun_aishii.id_klien'), config('akun_aishii.rahasia_klien'))
                ->post(config('akun_aishii.penerbit').'/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'code' => $kode,
                    'redirect_uri' => $bekal['alamat_balik'],
                    'code_verifier' => $bekal['pemverifikasi'],
                ]);
        } catch (ConnectionException $e) {
            throw new GagalMasukAishii(self::KALIMAT_PUTUS, 'token: '.$e->getMessage());
        }

        $tokenId = $jawaban->json('id_token');
        if (! $jawaban->successful() || ! is_string($tokenId)) {
            throw new GagalMasukAishii(self::KALIMAT_ULANGI, 'token: HTTP '.$jawaban->status().' '.Str::limit($jawaban->body(), 300));
        }

        $klaim = $this->verifikasi($tokenId, $bekal['nonce']);

        // H10 (AV14): `auth_time` token ID terbukti cap TERBIT (H8 salah, P18
        // butir 5), jadi pemeriksaan konfirmasi di atasnya tidak menjaga. Cap
        // `amr` token akses mungkin membawa saat sandi yang ASLI. Di sini ia
        // hanya DIUKUR untuk dicatat di log — tidak memutuskan apa pun, dan
        // tokennya sendiri tetap tidak disimpan.
        $klaim[self::AMR_TOKEN_AKSES] = $this->ringkasAmr($jawaban->json('access_token'), (string) $klaim['sub']);

        return $klaim;
    }

    /**
     * `amr` token akses → `[['metode' => …, 'umur_detik' => …], …]`, atau
     * `['galat' => sebab]` bila tokennya tidak ada, tidak sah, atau milik
     * akun lain. Hanya token yang tanda tangannya SAH yang dibaca: kelak
     * pagar konfirmasi berdiri di atas angka ini. TIDAK PERNAH melempar —
     * pengukuran tidak boleh menggagalkan masuk.
     *
     * @return array<int|string, mixed>
     */
    public function ringkasAmr(mixed $jwt, string $sub): array
    {
        try {
            if (! is_string($jwt) || substr_count($jwt, '.') !== 2) {
                return ['galat' => 'tidak ada'];
            }
            [$kepala64, $isi64, $tanda64] = explode('.', $jwt);
            $kepala = json_decode(self::dariB64url($kepala64), true);
            $klaim = json_decode(self::dariB64url($isi64), true);
            if (! is_array($kepala) || ! is_array($klaim) || ($kepala['alg'] ?? null) !== 'ES256') {
                return ['galat' => 'alg'];
            }
            $tanda = self::dariB64url($tanda64);
            if (strlen($tanda) !== 64
                || openssl_verify("{$kepala64}.{$isi64}", self::derEcdsa($tanda), $this->kunci((string) ($kepala['kid'] ?? '')), OPENSSL_ALGO_SHA256) !== 1) {
                return ['galat' => 'tanda tangan'];
            }
            if (($klaim['iss'] ?? null) !== config('akun_aishii.penerbit') || ($klaim['sub'] ?? null) !== $sub) {
                return ['galat' => 'iss/sub'];
            }

            $sekarang = time();

            return collect(is_array($klaim['amr'] ?? null) ? $klaim['amr'] : [])
                ->filter(fn ($a) => is_array($a) && is_numeric($a['timestamp'] ?? null))
                ->map(fn (array $a) => ['metode' => (string) ($a['method'] ?? '?'), 'umur_detik' => $sekarang - (int) $a['timestamp']])
                ->values()
                ->all();
        } catch (Throwable $e) {
            return ['galat' => class_basename($e)];
        }
    }

    /**
     * Umur autentikasi PALING BARU di ringkasan `amr`, atau null bila tidak
     * ada yang terbaca (H10, AV14).
     *
     * @param  array<string, mixed>  $klaim  klaim hasil `tukar()`
     */
    public static function umurAmr(array $klaim): ?int
    {
        $ringkas = $klaim[self::AMR_TOKEN_AKSES] ?? null;
        if (! is_array($ringkas) || isset($ringkas['galat']) || $ringkas === []) {
            return null;
        }

        return min(array_column($ringkas, 'umur_detik'));
    }

    /**
     * Token ID → klaimnya, HANYA bila tanda tangannya sah dan isinya untuk
     * permintaan ini.
     *
     * @return array<string, mixed>
     */
    public function verifikasi(string $jwt, string $nonce): array
    {
        $bagian = explode('.', $jwt);
        if (count($bagian) !== 3) {
            throw self::tolak('bentuk JWT');
        }
        [$kepala64, $isi64, $tanda64] = $bagian;
        $kepala = json_decode(self::dariB64url($kepala64), true);
        $klaim = json_decode(self::dariB64url($isi64), true);
        if (! is_array($kepala) || ! is_array($klaim)) {
            throw self::tolak('JSON JWT');
        }

        // Hanya ES256 — kunci proyek Aishii (jwks.json, terukur 7 Okt). `none`,
        // HS256, dan selainnya ditolak SEBELUM tanda tangannya dibaca:
        // memercayai `alg` yang ditulis token itu sendiri adalah lubang JWT
        // yang paling tua.
        if (($kepala['alg'] ?? null) !== 'ES256') {
            throw self::tolak('alg '.json_encode($kepala['alg'] ?? null));
        }

        $tanda = self::dariB64url($tanda64);
        $kunci = $this->kunci((string) ($kepala['kid'] ?? ''));
        if (strlen($tanda) !== 64
            || openssl_verify("{$kepala64}.{$isi64}", self::derEcdsa($tanda), $kunci, OPENSSL_ALGO_SHA256) !== 1) {
            throw self::tolak('tanda tangan');
        }

        $sekarang = time();
        $longgar = (int) config('akun_aishii.kelonggaran_jam');
        $idKlien = config('akun_aishii.id_klien');
        $aud = $klaim['aud'] ?? null;
        $audCocok = is_array($aud)
            ? in_array($idKlien, $aud, true) && (count($aud) === 1 || ($klaim['azp'] ?? null) === $idKlien)
            : $aud === $idKlien;

        $pemeriksaan = [
            'iss' => ($klaim['iss'] ?? null) === config('akun_aishii.penerbit'),
            'aud' => $audCocok,
            'exp' => is_numeric($klaim['exp'] ?? null) && $sekarang <= $klaim['exp'] + $longgar,
            'iat' => ! is_numeric($klaim['iat'] ?? null) || $sekarang >= $klaim['iat'] - $longgar,
            'nonce' => is_string($klaim['nonce'] ?? null) && hash_equals($nonce, $klaim['nonce']),
            'sub' => is_string($klaim['sub'] ?? null) && $klaim['sub'] !== '',
        ];
        foreach ($pemeriksaan as $nama => $lulus) {
            if (! $lulus) {
                throw self::tolak($nama);
            }
        }

        return $klaim;
    }

    /** Kunci publik untuk `kid` ini; kunci yang belum dikenal memaksa JWKS dibaca ulang SEKALI. */
    private function kunci(string $kid): OpenSSLAsymmetricKey
    {
        foreach ([false, true] as $bacaUlang) {
            foreach ($this->jwks($bacaUlang) as $jwk) {
                if ($kid !== '' && ($jwk['kid'] ?? null) === $kid) {
                    return self::kunciEc($jwk);
                }
            }
        }

        throw self::tolak('kid tidak dikenal: '.Str::limit($kid, 60));
    }

    /** @return list<array<string, mixed>> */
    private function jwks(bool $bacaUlang): array
    {
        $kunciTembolok = 'akun_aishii.jwks.'.md5(config('akun_aishii.penerbit'));
        if ($bacaUlang) {
            Cache::forget($kunciTembolok);
        }

        return Cache::remember($kunciTembolok, (int) config('akun_aishii.umur_jwks'), function () {
            try {
                $jawaban = Http::acceptJson()->timeout(10)->get(config('akun_aishii.penerbit').'/.well-known/jwks.json');
            } catch (ConnectionException $e) {
                throw new GagalMasukAishii(self::KALIMAT_PUTUS, 'jwks: '.$e->getMessage());
            }
            $kunci = $jawaban->json('keys');
            if (! $jawaban->successful() || ! is_array($kunci)) {
                throw new GagalMasukAishii(self::KALIMAT_ULANGI, 'jwks: HTTP '.$jawaban->status());
            }

            return array_values(array_filter($kunci, 'is_array'));
        });
    }

    /**
     * JWK EC P-256 → kunci OpenSSL. Disusun sebagai SubjectPublicKeyInfo DER
     * (awalan tetap P-256 + titik tak-terkompresi `04‖x‖y`), bukan lewat
     * `openssl_pkey_new` berkomponen — bentuk DER ini sama di versi PHP mana pun.
     *
     * @param  array<string, mixed>  $jwk
     */
    private static function kunciEc(array $jwk): OpenSSLAsymmetricKey
    {
        $x = self::dariB64url((string) ($jwk['x'] ?? ''));
        $y = self::dariB64url((string) ($jwk['y'] ?? ''));
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256' || strlen($x) !== 32 || strlen($y) !== 32) {
            throw self::tolak('kunci JWKS bukan EC P-256');
        }

        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$x.$y;
        $kunci = openssl_pkey_get_public(
            "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n"
        );
        if ($kunci === false) {
            throw self::tolak('kunci JWKS tidak terbaca');
        }

        return $kunci;
    }

    /** Tanda tangan JWS ES256 (r‖s, 64 bita) → ECDSA-Sig-Value DER yang dibaca OpenSSL. */
    private static function derEcdsa(string $mentah): string
    {
        $bilangan = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) > 0x7F) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $isi = $bilangan(substr($mentah, 0, 32)).$bilangan(substr($mentah, 32, 32));

        return "\x30".chr(strlen($isi)).$isi;
    }

    private static function tolak(string $alasan): GagalMasukAishii
    {
        return new GagalMasukAishii(self::KALIMAT_ULANGI, 'token ID: '.$alasan);
    }

    public static function keB64url(string $biner): string
    {
        return rtrim(strtr(base64_encode($biner), '+/', '-_'), '=');
    }

    private static function dariB64url(string $teks): string
    {
        return (string) base64_decode(strtr($teks, '-_', '+/'), true);
    }
}
