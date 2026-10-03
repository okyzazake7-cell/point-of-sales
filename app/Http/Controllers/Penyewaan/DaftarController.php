<?php

namespace App\Http\Controllers\Penyewaan;

use App\Http\Controllers\Controller;
use App\Http\Controllers\SetupController;
use App\Langganan\Harga;
use App\Models\User;
use App\Penyewaan\PendaftaranToko;
use App\Penyewaan\Penyewaan;
use App\Support\BotGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * /daftar — toko baru Aishii POS (AS7). Satu layar; sesudahnya pemilik
 * langsung masuk dan mendarat di Langganan, sebab tanpa masa coba tokonya
 * lahir terkunci sampai tagihan pertama lunas.
 */
class DaftarController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Penyewaan/Daftar', [
            'jenisUsaha' => array_keys(SetupController::BUSINESS_TYPES),
            'hargaPerOutlet' => Harga::perOutlet(),
            'botGuard' => BotGuard::payload(),
        ]);
    }

    public function store(Request $request, PendaftaranToko $pendaftaran, Penyewaan $penyewaan): RedirectResponse
    {
        $isian = $request->validate([
            'nama_toko' => ['required', 'string', 'min:3', 'max:120'],
            'jenis_usaha' => ['required', 'string', 'in:'.implode(',', array_keys(SetupController::BUSINESS_TYPES))],
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ], [], [
            'nama_toko' => 'nama toko',
            'jenis_usaha' => 'jenis usaha',
            'nama' => 'nama Anda',
            'email' => 'surel',
            'password' => 'kata sandi',
        ]);

        [$toko] = $pendaftaran->daftarkan($isian);

        $penyewaan->masuk($toko);
        Auth::login(User::query()->where('email', mb_strtolower(trim($isian['email'])))->firstOrFail());
        $request->session()->regenerate();
        $request->session()->put('security.session_started_at', now()->timestamp);

        return redirect()->route('langganan.index')
            ->with('success', "Toko {$toko->nama} siap. Bayar tagihan pertama untuk mulai berjualan.");
    }
}
