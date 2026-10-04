<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    {{--
        CUBIT-ZOOM MENGIKUTI ATURAN AISHII (AK1/AL2 di repo Aishii).
        Viewport-nya TIDAK mematikan zoom: di tab peramban, cubitan adalah cara
        pedagang yang matanya tidak lagi tajam membaca angka kecil (WCAG
        1.4.4). Zoom dimatikan hanya saat aplikasinya BERDIRI SENDIRI — dipasang
        ke layar utama — lewat skrip dini di bawah, sebab di sana cubitan tak
        sengaja saat mengetuk kasir terasa seperti aplikasi yang rusak.
    --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script>
        (function () {
            try {
                var m = window.matchMedia;
                if (!m) return;
                var sendiri = m('(display-mode: standalone)').matches
                    || m('(display-mode: fullscreen)').matches
                    || window.navigator.standalone === true;
                if (!sendiri) return;
                var v = document.querySelector('meta[name="viewport"]');
                if (v && (v.getAttribute('content') || '').indexOf('user-scalable') === -1) {
                    v.setAttribute('content', v.getAttribute('content') + ', maximum-scale=1, user-scalable=no');
                }
            } catch (e) {}
        })();
    </script>
    <meta name="theme-color" content="{{ config('brand.theme_color') }}">
    <meta name="application-name" content="{{ config('brand.name') }}">
    <meta name="apple-mobile-web-app-title" content="{{ config('brand.name') }}">
    <meta name="description" content="{{ config('brand.description') }}">
    <meta property="og:site_name" content="{{ config('brand.name') }}">
    <meta property="og:title" content="{{ config('brand.name') }} — {{ config('brand.tagline') }}">
    <meta property="og:description" content="{{ config('brand.description') }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="id_ID">
    <meta property="og:url" content="{{ config('app.url') }}/">
    <meta property="og:image" content="{{ config('app.url') }}/images/og-image.jpg">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ config('brand.name') }} — {{ config('brand.tagline') }}">
    <meta name="twitter:description" content="{{ config('brand.description') }}">
    <meta name="twitter:image" content="{{ config('app.url') }}/images/og-image.jpg">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/png" sizes="192x192" href="/images/icon-192.png">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

    <title data-inertia>{{ config('brand.name') }}</title>

    {{-- Huruf keluarga Aishii (Plus Jakarta Sans) disajikan dari bundel
         sendiri lewat @fontsource, bukan Google Fonts: kasirnya harus tetap
         rapi saat internet putus, dan CSP `font-src 'self'` tidak perlu
         dilonggarkan untuk server huruf orang lain. --}}

    <!-- Scripts -->
    @routes
    @viteReactRefresh
    @vite('resources/js/app.jsx')
    @inertiaHead
    <style>
        body.dark {
            background-color: rgb(2 6 23);
        }

        body.light {
            background-color: rgb(248 250 252);
        }
    </style>
</head>

<body class="font-sans antialiased bg-slate-50 transition-colors duration-200" onload="setInitialTheme()">

    @inertia
    <script>
        function setInitialTheme() {
            const darkMode = localStorage.getItem('darkMode') === 'true';
            if (darkMode) {
                document.body.classList.add('dark');
                document.body.classList.remove('light');
            } else {
                document.body.classList.add('light');
                document.body.classList.remove('dark');
            }
        }
    </script>
</body>

</html>
