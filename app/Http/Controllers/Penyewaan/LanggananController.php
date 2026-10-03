<?php

namespace App\Http\Controllers\Penyewaan;

use App\Http\Controllers\Controller;
use App\Langganan\BukuTagihanAishii;
use App\Langganan\Harga;
use App\Langganan\Langganan;
use App\Models\Outlet;
use App\Models\Pusat\Tagihan;
use App\Penyewaan\Penyewaan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman Langganan toko (AS8/AS9). Semua yang masuk boleh MELIHAT status
 * langganannya — kasir perlu tahu kenapa kasirnya berhenti — tetapi hanya
 * pemilik (super-admin) yang membuat dan membatalkan tagihan.
 */
class LanggananController extends Controller
{
    public function __construct(
        private readonly Penyewaan $penyewaan,
        private readonly Langganan $langganan,
        private readonly BukuTagihanAishii $buku,
    ) {}

    public function index(): Response
    {
        $toko = $this->penyewaan->toko();
        $terbuka = $this->langganan->tagihanTerbuka($toko);

        return Inertia::render('Dashboard/Langganan/Index', [
            'ringkasan' => $this->langganan->ringkasan($toko),
            'tagihan' => $terbuka ? $this->bentuk($terbuka) : null,
            'riwayat' => Tagihan::query()
                ->where('toko_id', $toko->getKey())
                ->whereIn('status', ['lunas', 'batal', 'kedaluwarsa'])
                ->latest('id')
                ->limit(12)
                ->get()
                ->map(fn (Tagihan $t) => $this->bentuk($t)),
            'outletBerjualan' => Outlet::query()
                ->where('is_active', true)
                ->where('is_sales_enabled', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'pilihanBulan' => config('langganan.pilihan_bulan'),
            'hargaPerOutlet' => Harga::perOutlet(),
            'hariProrata' => (int) config('langganan.hari_prorata'),
            'qris' => config('langganan.qris'),
            'whatsapp' => config('langganan.whatsapp'),
            'konfirmasiOtomatis' => $this->buku->tersedia(),
            'bolehMengatur' => (bool) request()->user()?->isSuperAdmin(),
            // Pesan sekali-tampil dari tindakan sebelumnya (periksa, berhenti
            // jual) — Inertia hulu tidak membagikan flash secara umum.
            'pesan' => session('success')
                ? ['jenis' => 'success', 'teks' => session('success')]
                : (session('info') ? ['jenis' => 'info', 'teks' => session('info')] : null),
        ]);
    }

    public function tagihan(Request $request): RedirectResponse
    {
        $this->hanyaPemilik($request);
        $isian = $request->validate([
            'jenis' => ['required', 'in:perpanjang,tambah_outlet'],
            'bulan' => ['nullable', 'integer'],
            'kursi' => ['required', 'integer', 'min:1', 'max:200'],
        ]);

        $toko = $this->penyewaan->toko();
        $isian['jenis'] === 'perpanjang'
            ? $this->langganan->buatPerpanjang($toko, (int) $isian['bulan'], (int) $isian['kursi'])
            : $this->langganan->buatTambahOutlet($toko, (int) $isian['kursi']);

        return back();
    }

    public function periksa(): RedirectResponse
    {
        $lunas = $this->langganan->periksaPembayaran($this->penyewaan->toko());

        return back()->with($lunas > 0 ? 'success' : 'info', $lunas > 0
            ? 'Pembayaran diterima — langganan sudah aktif.'
            : 'Pembayaran belum terbaca. Biasanya masuk dalam satu–dua menit sesudah transfer.');
    }

    public function batalkan(Request $request, Tagihan $tagihan): RedirectResponse
    {
        $this->hanyaPemilik($request);
        abort_unless((int) $tagihan->toko_id === (int) $this->penyewaan->toko()->getKey(), 404);

        $this->langganan->batalkan($tagihan);

        return back();
    }

    /**
     * Satu-satunya tulis ke data toko yang lolos kunci (dokumen 24 §5):
     * mengurangi outlet yang ditagih, dan ia hanya bisa MEMATIKAN penjualan.
     */
    public function berhentiJual(Request $request, int $outlet): RedirectResponse
    {
        $this->hanyaPemilik($request);
        Outlet::query()->findOrFail($outlet)->update(['is_sales_enabled' => false]);

        return back()->with('success', 'Penjualan outlet itu dimatikan. Tagihan berikutnya ikut berkurang.');
    }

    private function hanyaPemilik(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403, 'Hanya pemilik toko yang mengatur langganan.');
    }

    /** @return array<string, mixed> */
    private function bentuk(Tagihan $t): array
    {
        return [
            'id' => $t->id,
            'nomor' => $t->nomor,
            'jenis' => $t->jenis,
            'kursi' => $t->kursi,
            'bulan' => $t->bulan,
            'hari_prorata' => $t->hari_prorata,
            'nominal_dasar' => $t->nominal_dasar,
            'kode_unik' => $t->kode_unik,
            'total_bayar' => $t->total_bayar,
            'pencocok' => $t->pencocok,
            'status' => $t->status,
            'berlaku_sampai' => $t->berlaku_sampai?->toIso8601String(),
            'dibayar_pada' => $t->dibayar_pada?->toIso8601String(),
            'dibayar_lewat' => $t->dibayar_lewat,
            'periode_sampai' => $t->periode_sampai?->toIso8601String(),
            'dibuat_pada' => $t->created_at?->toIso8601String(),
        ];
    }
}
