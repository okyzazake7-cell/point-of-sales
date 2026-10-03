<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        // Kamera dan USB dibuka untuk ASAL SENDIRI saja: kamera adalah
        // pemindai barcode di HP (html5-qrcode) dan USB jalur printer struk
        // thermal (WebUSB). `camera=()`/`usb=()` mematikan keduanya di
        // peramban yang menegakkan kebijakan ini (Chrome: getUserMedia →
        // NotAllowedError) — fitur yang dijanjikan, mati tanpa satu galat
        // yang bisa dibaca kasir. Sisanya tetap tertutup.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), microphone=(), geolocation=(), payment=(), usb=(self), accelerometer=(), gyroscope=()'
        );

        // HSTS only over HTTPS in production to avoid locking out plain-HTTP dev.
        if ($request->secure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // CSP is rolled out in report-only mode first so violations can be
        // collected before enforcement (Inertia/Vite inline assets).
        $response->headers->set('Content-Security-Policy-Report-Only', $this->contentSecurityPolicy());

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "connect-src 'self'",
        ];

        return implode('; ', $directives);
    }
}
