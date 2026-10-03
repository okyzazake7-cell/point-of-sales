import './bootstrap';
import '@fontsource-variable/plus-jakarta-sans';
import '../css/app.css';
import i18n from './i18n';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeSwitcherProvider } from './Context/ThemeSwitcherContext';
import { OnlineStatusProvider } from './Context/OnlineStatusContext';

import { BRAND } from './Utils/brand';

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
