<?php

namespace App\Langganan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Buku tagihan bersama di basis data Aishii (AS9, dokumen 24 §7).
 *
 * QRIS Aishii POS SAMA dengan Aishii Bazar, dan notifikasi GoPay hanya
 * membawa nominal. Karena itu nominal unik dipilih SATU pihak yang melihat
 * keranjang Aishii dan tagihan POS sekaligus — fungsi `pos_*` di Supabase
 * Aishii — dan pencocokan pembayarannya juga di sana. Server ini hanya
 * MEMBUAT, MEMBACA, dan MEMBATALKAN tagihannya sendiri; rahasia yang
 * dipegangnya tidak bisa menandai apa pun lunas.
 *
 * Tiap panggilan yang gagal (fungsi belum ada karena migrasinya belum
 * dijalankan pemilik, rahasia salah, jaringan) memulangkan null, dan
 * pemanggilnya jatuh ke konfirmasi manual — bukan ke kode pilihan sendiri.
 */
class BukuTagihanAishii
{
    public function tersedia(): bool
    {
        $c = config('langganan.buku_tagihan');

        return filled($c['url'] ?? null) && filled($c['kunci_anon'] ?? null) && filled($c['rahasia'] ?? null);
    }

    /**
     * @return array{kode_unik: int, total_bayar: int, status: string}|null
     */
    public function buat(string $ref, int $nominal, int $berlakuJam): ?array
    {
        $hasil = $this->panggil('pos_buat_tagihan', [
            'p_ref' => $ref,
            'p_nominal' => $nominal,
            'p_berlaku_jam' => $berlakuJam,
        ]);

        if (($hasil['status'] ?? null) !== 'ok' || ! isset($hasil['kode_unik'], $hasil['total_bayar'])) {
            return null;
        }

        return [
            'kode_unik' => (int) $hasil['kode_unik'],
            'total_bayar' => (int) round((float) $hasil['total_bayar']),
            'status' => (string) ($hasil['status_tagihan'] ?? 'terbuka'),
        ];
    }

    /**
     * @param  list<string>  $refs
     * @return array<string, array{status: string, dibayar_pada: ?string}>|null
     */
    public function status(array $refs): ?array
    {
        if ($refs === []) {
            return [];
        }

        $hasil = $this->panggil('pos_status_tagihan', ['p_refs' => array_values($refs)]);
        if (($hasil['status'] ?? null) !== 'ok' || ! is_array($hasil['tagihan'] ?? null)) {
            return null;
        }

        $per = [];
        foreach ($hasil['tagihan'] as $baris) {
            $per[(string) $baris['ref']] = [
                'status' => (string) $baris['status'],
                'dibayar_pada' => $baris['dibayar_pada'] ?? null,
            ];
        }

        return $per;
    }

    /**
     * @return string|null status hasil (`pos_batalkan_tagihan`, migrasi Aishii
     *                     20261153): 'batal', 'sudah_dibayar', 'tidak_ada', 'kedaluwarsa' —
     *                     null bila tak terjangkau.
     */
    public function batalkan(string $ref): ?string
    {
        $hasil = $this->panggil('pos_batalkan_tagihan', ['p_ref' => $ref]);

        return isset($hasil['status']) ? (string) $hasil['status'] : null;
    }

    /** @return array<string, mixed>|null */
    private function panggil(string $fungsi, array $isi): ?array
    {
        if (! $this->tersedia()) {
            return null;
        }

        $c = config('langganan.buku_tagihan');

        try {
            $jawaban = Http::timeout((int) $c['batas_waktu'])
                ->withHeaders(['apikey' => $c['kunci_anon']])
                ->withToken($c['kunci_anon'])
                ->acceptJson()
                ->post(rtrim($c['url'], '/')."/rest/v1/rpc/{$fungsi}", ['p_rahasia' => $c['rahasia']] + $isi);
        } catch (Throwable $e) {
            Log::warning('Buku tagihan Aishii tak terjangkau.', ['fungsi' => $fungsi, 'galat' => $e->getMessage()]);

            return null;
        }

        if (! $jawaban->successful()) {
            // PGRST202 = fungsinya belum ada: migrasi AS9 belum dijalankan
            // pemilik. Bukan galat yang perlu membangunkan siapa pun.
            Log::info('Buku tagihan Aishii menolak panggilan.', [
                'fungsi' => $fungsi,
                'status' => $jawaban->status(),
                'kode' => $jawaban->json('code'),
            ]);

            return null;
        }

        $isiJawaban = $jawaban->json();
        if (is_array($isiJawaban) && ($isiJawaban['status'] ?? null) === 'rahasia_salah') {
            Log::warning('Rahasia POS ditolak buku tagihan Aishii — periksa AISHII_RAHASIA_POS.');
        }

        return is_array($isiJawaban) ? $isiJawaban : null;
    }
}
