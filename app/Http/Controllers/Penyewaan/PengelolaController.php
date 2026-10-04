<?php

namespace App\Http\Controllers\Penyewaan;

use App\Http\Controllers\Controller;
use App\Langganan\BukuTagihanAishii;
use App\Langganan\Langganan;
use App\Models\Pusat\Pengelola;
use App\Models\Pusat\Tagihan;
use App\Models\Pusat\Toko;
use App\Support\BotGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pintu pengelola layanan Aishii POS (dokumen 24 §8): daftar toko dan
 * tagihan, plus "Tandai lunas" beralasan wajib. Hitungan dan tanggal saja —
 * pengelola tidak membuka data dagang toko mana pun.
 */
class PengelolaController extends Controller
{
    public function masukForm(): Response|RedirectResponse
    {
        if (Auth::guard('pengelola')->check()) {
            return redirect()->route('pengelola.index');
        }

        return Inertia::render('Pengelola/Masuk', ['botGuard' => BotGuard::payload()]);
    }

    public function masuk(Request $request): RedirectResponse
    {
        $isian = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('pengelola')->attempt(['email' => mb_strtolower(trim($isian['email'])), 'password' => $isian['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return redirect()->route('pengelola.index');
    }

    public function keluar(Request $request): RedirectResponse
    {
        Auth::guard('pengelola')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('pengelola.masuk');
    }

    public function index(Langganan $langganan, BukuTagihanAishii $buku): Response
    {
        $sekarang = now();

        return Inertia::render('Pengelola/Index', [
            'toko' => Toko::query()->latest('id')->limit(200)->get()->map(fn (Toko $t) => [
                'id' => $t->id,
                'kode' => $t->kode,
                'nama' => $t->nama,
                'email_pemilik' => $t->email_pemilik,
                'status_basis_data' => $t->status_basis_data,
                'aktif_sampai' => $t->aktif_sampai?->toIso8601String(),
                'terkunci' => $langganan->terkunci($t, $sekarang),
                'kursi_outlet' => $t->kursi_outlet,
                'dibuat_pada' => $t->created_at?->toIso8601String(),
            ]),
            'tagihan' => Tagihan::query()->with('toko:id,nama,kode')
                ->where(fn ($q) => $q->where('status', 'menunggu')
                    ->orWhere('updated_at', '>', $sekarang->copy()->subDays(30)))
                ->latest('id')->limit(200)->get()->map(fn (Tagihan $t) => [
                    'id' => $t->id,
                    'nomor' => $t->nomor,
                    'toko' => $t->toko?->nama,
                    'jenis' => $t->jenis,
                    'kursi' => $t->kursi,
                    'bulan' => $t->bulan,
                    'total_bayar' => $t->total_bayar,
                    'pencocok' => $t->pencocok,
                    'status' => $t->status,
                    'berlaku_sampai' => $t->berlaku_sampai?->toIso8601String(),
                    'dibayar_pada' => $t->dibayar_pada?->toIso8601String(),
                    'dibayar_lewat' => $t->dibayar_lewat,
                    'alasan' => $t->alasan,
                ]),
            'konfirmasiOtomatis' => $buku->tersedia(),
            'pengelola' => Auth::guard('pengelola')->user()?->only(['nama', 'email']),
        ]);
    }

    /**
     * Jalan manual (K5): pembayaran yang tidak tercocokkan otomatis — nominal
     * meleset, transfer sesudah tagihan kedaluwarsa, atau buku tagihan Aishii
     * belum dipasang. Kembarannya di Aishii ikut dibatalkan supaya nominalnya
     * tidak bisa "lunas" untuk kedua kalinya.
     */
    public function tandaiLunas(Request $request, Tagihan $tagihan, Langganan $langganan, BukuTagihanAishii $buku): RedirectResponse
    {
        $isian = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']]);
        abort_if($tagihan->status === 'lunas', 422, 'Tagihan ini sudah lunas.');
        abort_if($tagihan->status === 'batal', 422, 'Tagihan yang dibatalkan pemiliknya tidak bisa ditandai lunas — minta ia membuat tagihan baru.');

        /** @var Pengelola $pengelola */
        $pengelola = Auth::guard('pengelola')->user();
        $langganan->terapkanLunas($tagihan, 'manual', $pengelola, $isian['alasan']);

        if ($tagihan->pencocok === 'aishii') {
            $buku->batalkan($tagihan->nomor);
        }

        return back()->with('success', "Tagihan {$tagihan->nomor} ditandai lunas.");
    }
}
