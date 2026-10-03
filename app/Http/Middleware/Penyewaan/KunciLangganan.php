<?php

namespace App\Http\Middleware\Penyewaan;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Langganan\Langganan;
use App\Models\Pusat\Toko;
use App\Penyewaan\Penyewaan;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Langsung terkunci" (keputusan pemilik 3 Okt, dokumen 24 §6): toko yang
 * belum pernah membayar atau masa aktifnya lewat tidak bisa MENCATAT apa pun
 * — kasir, stok, pembelian, pengaturan — tetapi SEMUA yang hanya membaca
 * (laporan, daftar, rincian, ekspor) tetap terbuka.
 *
 * Satu pagar di depan seluruh rute, bukan suntingan di 44 modul hulu: yang
 * dibedakan cuma metode HTTP, ditambah daftar pengecualian yang sempit.
 */
class KunciLangganan
{
    /** Tulis yang tetap boleh: membayar, keluar, dan urusan akun sendiri. */
    private const RUTE_BOLEH = [
        'logout', 'langganan.*', 'password.update', 'password.email', 'password.store',
        'profile.update', 'language.switch', 'tours.complete', 'tours.reset',
        'outlet.switch', 'notifications.stock.read', 'notifications.stock.readAll',
        'pengelola.*', 'api.auth.login', 'api.auth.logout',
    ];

    /** Kiriman formulir hulu yang rutenya tidak bernama. */
    private const AKSI_BOLEH = [
        AuthenticatedSessionController::class.'@store',
        ConfirmablePasswordController::class.'@store',
    ];

    public const PESAN = 'Langganan Aishii POS toko ini sedang tidak aktif — kasir dan pencatatan berhenti, laporan tetap bisa dibaca. Perpanjang di menu Langganan.';

    public function __construct(
        private readonly Penyewaan $penyewaan,
        private readonly Langganan $langganan,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $toko = $this->penyewaan->toko();

        if (! $toko || $request->isMethodSafe() || ! $this->langganan->terkunci($toko) || $this->boleh($request)) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), ['transactions.sync-offline', 'api.pos.transactions.sync'], true)) {
            return $this->sinkronLuring($request, $next, $toko);
        }

        return $this->tolak($request);
    }

    private function boleh(Request $request): bool
    {
        $rute = $request->route();

        return Str::is(self::RUTE_BOLEH, (string) $rute?->getName())
            || in_array($rute?->getActionName(), self::AKSI_BOLEH, true);
    }

    private function tolak(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => self::PESAN, 'terkunci' => true], 423);
        }

        // Inertia: kembali ke halaman asal dengan pesan di `errors.langganan`
        // — spanduk Langganan di tata letak menampilkannya.
        return back()->withErrors(['langganan' => self::PESAN]);
    }

    /**
     * Penjualan luring yang TERCATAT sebelum masa aktif habis tetap diterima
     * walau baru terkirim sesudahnya: uangnya sudah diterima dari pembeli,
     * dan menolaknya membuat buku toko berbohong. Yang tercatat sesudahnya
     * DITAHAN — dijawab `held`, dan layar kasir menyimpannya di antrean
     * sampai langganannya diperpanjang. Tidak ada yang dibuang.
     */
    private function sinkronLuring(Request $request, Closure $next, Toko $toko): Response
    {
        $batas = $toko->aktif_sampai;
        $semua = array_values((array) $request->input('transactions', []));
        $diterima = [];
        $diterimaPada = [];

        foreach ($semua as $i => $isi) {
            $tercatat = $this->waktu($isi['recorded_at'] ?? null);
            if ($batas && $tercatat && $tercatat->lessThan($batas)) {
                $diterima[] = $isi;
                $diterimaPada[$i] = true;
            }
        }

        if ($diterima === []) {
            return response()->json([
                'message' => self::PESAN,
                'terkunci' => true,
                'data' => ['results' => array_map(fn ($isi) => $this->ditahan($isi), $semua)],
            ], 423);
        }

        $request->merge(['transactions' => $diterima]);
        $jawaban = $next($request);

        if (count($diterima) === count($semua) || ! $jawaban instanceof JsonResponse) {
            return $jawaban;
        }

        // Layar kasir mencocokkan hasil dengan antreannya menurut URUTAN,
        // bukan menurut client_uuid — hasil yang digeser satu baris membuat
        // penjualan yang ditahan dihapus dari antrean seolah sudah terkirim.
        $isi = $jawaban->getData(true);
        $hasilDiterima = array_values((array) ($isi['data']['results'] ?? []));
        $gabung = [];
        $j = 0;
        foreach ($semua as $i => $baris) {
            $gabung[] = isset($diterimaPada[$i]) ? ($hasilDiterima[$j++] ?? null) : $this->ditahan($baris);
        }
        $isi['data']['results'] = $gabung;
        $jawaban->setData($isi);

        return $jawaban;
    }

    /** @return array{client_uuid: mixed, status: string, reason: string} */
    private function ditahan(array $isi): array
    {
        return [
            'client_uuid' => $isi['client_uuid'] ?? null,
            'status' => 'held',
            'reason' => 'Langganan berakhir sebelum penjualan ini tercatat — ia disimpan dan terkirim sesudah langganan diperpanjang.',
        ];
    }

    private function waktu(mixed $nilai): ?Carbon
    {
        if (! is_string($nilai) || $nilai === '') {
            return null;
        }

        try {
            return Carbon::parse($nilai);
        } catch (Throwable) {
            return null;
        }
    }
}
