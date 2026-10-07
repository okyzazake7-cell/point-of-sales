<?php

namespace App\Http\Controllers\Penyewaan;

use App\AkunAishii\KlienAishii;
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
use Illuminate\Support\Str;
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
    public function create(Request $request, KlienAishii $klien): Response
    {
        $identitas = $klien->aktif() ? MasukAishiiController::identitas($request) : null;

        return Inertia::render('Penyewaan/Daftar', [
            'jenisUsaha' => array_keys(SetupController::BUSINESS_TYPES),
            'hargaPerOutlet' => Harga::perOutlet(),
            'botGuard' => BotGuard::payload(),
            // AU1: hidup → toko lahir dari akun Aishii (tanpa surel dan sandi
            // POS); tanpa identitas, layar ini hanya menawarkan tombol masuknya.
            'akunAishii' => $klien->aktif() ? [
                'email' => $identitas['email'] ?? null,
                'nama' => $identitas['nama'] ?? null,
                'masuk' => route('aishii.masuk'),
            ] : null,
        ]);
    }

    public function store(Request $request, PendaftaranToko $pendaftaran, Penyewaan $penyewaan, KlienAishii $klien): RedirectResponse
    {
        $label = [
            'nama_toko' => 'nama toko',
            'jenis_usaha' => 'jenis usaha',
            'nama' => 'nama Anda',
            'email' => 'surel',
            'password' => 'kata sandi',
        ];
        $aturan = [
            'nama_toko' => ['required', 'string', 'min:3', 'max:120'],
            'jenis_usaha' => ['required', 'string', 'in:'.implode(',', array_keys(SetupController::BUSINESS_TYPES))],
            'nama' => ['required', 'string', 'max:120'],
            'telepon' => ['nullable', 'string', 'max:30'],
        ];

        if ($klien->aktif()) {
            $identitas = MasukAishiiController::identitas($request);
            if (! $identitas) {
                return redirect()->route('daftar')->withErrors([
                    'aishii' => 'Masuk dengan akun Aishii dulu — toko Anda dibuat untuk akun itu.',
                ]);
            }
            // Surel dari akun Aishii yang terverifikasi, BUKAN dari formulir;
            // sandinya acak dan tidak pernah diketahui siapa pun (D2).
            $isian = [
                ...$request->validate($aturan, [], $label),
                'email' => $identitas['email'],
                'password' => Str::password(40),
                'aishii_sub' => $identitas['sub'],
            ];
        } else {
            $isian = $request->validate([
                ...$aturan,
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(8)],
            ], [], $label);
        }

        [$toko] = $pendaftaran->daftarkan($isian);

        $penyewaan->masuk($toko);
        Auth::login(User::query()->where('email', mb_strtolower(trim($isian['email'])))->firstOrFail());
        $request->session()->regenerate();
        $request->session()->put('security.session_started_at', now()->timestamp);
        MasukAishiiController::lupakanIdentitas($request);

        return redirect()->route('langganan.index')
            ->with('success', "Toko {$toko->nama} siap. Bayar tagihan pertama untuk mulai berjualan.");
    }
}
