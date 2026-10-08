<?php

namespace App\Http\Controllers\Auth;

use App\AkunAishii\AkunTertaut;
use App\AkunAishii\BuktiKonfirmasi;
use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService
    ) {}

    /**
     * Show the confirm password view.
     */
    public function show(Request $request): Response
    {
        return Inertia::render('Auth/ConfirmPassword', [
            'challenge' => session('security.step_up_context'),
            // AU1 — D2: akun yang tertaut ke akun Aishii tidak punya sandi
            // POS; konfirmasinya pun lewat akun Aishii.
            'konfirmasiAishii' => AkunTertaut::pengguna($request->user())
                ? AkunTertaut::alamatKonfirmasi(app(BuktiKonfirmasi::class)->kode($request))
                : null,
        ]);
    }

    /**
     * Confirm the user's password.
     */
    public function store(Request $request): RedirectResponse
    {
        if (AkunTertaut::pengguna($request->user())) {
            throw ValidationException::withMessages([
                'password' => 'Akun ini dikonfirmasi lewat akun Aishii — tekan "Konfirmasi dengan akun Aishii".',
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            $this->auditLogService->log(
                event: 'auth.password_confirmation_failed',
                module: 'auth',
                auditable: $request->user(),
                description: 'Konfirmasi password untuk aksi sensitif gagal.',
                meta: [
                    'severity' => 'warning',
                    'route' => $request->route()?->getName(),
                    'challenge' => session('security.step_up_context'),
                ],
            );

            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        $challenge = $request->session()->pull('security.step_up_context');

        $this->auditLogService->log(
            event: 'auth.password_confirmed',
            module: 'auth',
            auditable: $request->user(),
            description: 'Password berhasil dikonfirmasi ulang.',
            meta: [
                'severity' => 'info',
                'route' => $request->route()?->getName(),
                'challenge' => $challenge,
            ],
        );
        $this->auditLogService->log(
            event: 'security.privileged_action_confirmed',
            module: 'security',
            auditable: $request->user(),
            description: 'Aksi sensitif diotorisasi setelah konfirmasi password.',
            meta: [
                'severity' => 'high',
                'challenge' => $challenge,
            ],
        );

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
