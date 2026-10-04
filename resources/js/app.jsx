import './bootstrap';
import '@fontsource-variable/plus-jakarta-sans';
import '../css/app.css';
import i18n from './i18n';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeSwitcherProvider } from './Context/ThemeSwitcherContext';
import { OnlineStatusProvider } from './Context/OnlineStatusContext';

import { BRAND } from './Utils/brand';
import { setOfflineStore } from './Utils/offlineDb';

/**
 * Mode banyak toko (AS12): data luring dan tembolok service worker milik
 * SATU toko. Antrean luring dipisah per toko di offlineDb; tembolok service
 * worker berkunci ALAMAT, dan alamat dashboard sama di semua toko — jadi
 * begitu perangkat berpindah toko, temboloknya dikosongkan supaya daftar
 * produk toko A tidak pernah tersaji luring kepada kasir toko B.
 */
function ikutiToko(toko) {
    setOfflineStore(toko?.id ?? null);
    if (!toko?.id) return;

    let terakhir = null;
    try {
        terakhir = window.localStorage.getItem('pos-toko-terakhir');
    } catch {
        // Penyimpanan diblokir: tanpa ingatan, tembolok dikosongkan tiap kali.
    }
    if (terakhir === String(toko.id)) return;

    if ('caches' in window) {
        caches.keys()
            .then((kunci) => Promise.all(kunci.filter((k) => k.startsWith('pos-cache')).map((k) => caches.delete(k))))
            .catch(() => {});
    }
    try {
        window.localStorage.setItem('pos-toko-terakhir', String(toko.id));
    } catch {
        // idem
    }
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

createInertiaApp({
    // Pola judul yang sama dengan Aishii (`%s · Aishii Bazar`). Nama merek
    // dibaca dari BRAND, bukan VITE_APP_NAME — `.env` lama berisi "Laravel".
    title: (title) => (title ? `${title} · ${BRAND.name}` : BRAND.name),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        // Bahasa antarmuka React MENGIKUTI server (`SetLocale`: pilihan
        // pengguna → sesi → kuki → Indonesia). Tanpa ini pendeteksi i18n
        // menebak sendiri dari peramban, dan ponsel berbahasa Inggris
        // mendapat layar setengah Inggris di atas halaman yang Indonesia.
        ikutiToko(props.initialPage?.props?.toko);
        router.on('navigate', (event) => ikutiToko(event.detail.page.props?.toko));

        const locale = props.initialPage?.props?.locale?.current;
        if (locale && locale !== i18n.language) {
            i18n.changeLanguage(locale);
        }

        const root = createRoot(el);

        root.render(
            <ThemeSwitcherProvider>
                <OnlineStatusProvider>
                    <App {...props} />
                </OnlineStatusProvider>
            </ThemeSwitcherProvider>
        );
    },
    progress: {
        color: BRAND.themeColor,
    },
});
