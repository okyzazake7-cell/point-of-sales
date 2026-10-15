/**
 * PANDUAN PER HALAMAN — setiap layar toko punya panduannya sendiri.
 *
 * Dulu panduan ditulis untuk lima layar, tetapi tombol "?" di bilah atas
 * SELALU memulai panduan Dashboard: di Produk, Shift Kasir, Laporan, dan
 * ±60 layar lain tombol itu diam saja (diukur di pos.aishiierp.com,
 * 15 Okt). Orang yang baru mengenal aplikasi kasir justru paling butuh
 * penjelasan di layar yang jarang ia buka.
 *
 * Susunannya tiga berkas:
 *   - `Panduan/daftar.json` — komponen halaman Inertia → panduan:
 *     `[{ key, if?, steps: [{ id, el?, side?, klik? }] }]`. Halaman yang
 *     berbagi formulir (Tambah/Ubah) cukup menunjuk nama komponen lain.
 *     `TourController` membaca berkas yang SAMA untuk daftar kunci yang
 *     boleh ditandai selesai, jadi kunci tidak bisa menyimpang.
 *   - `Panduan/teks/{id,en}.json` — judul dan isi tiap langkah per kunci.
 *     Dimuat saat panduan dibuka (bundel terpisah): ±100 KB teks per
 *     bahasa tidak ikut membebani setiap pemuatan halaman di ponsel.
 *   - atribut `data-tour="…"` di elemen yang ditunjuk.
 *
 * `el` yang tidak terlihat (tersembunyi di lebar ini, daftar kosong, tab
 * lain) DILEWATI — panduan menjelaskan apa yang ada di layar sekarang.
 * Dua elemen boleh berbagi satu nama (tabel di layar lebar, kartu di
 * ponsel); yang terlihatlah yang ditunjuk. `if` memilih panduan menurut
 * keadaan layar (layar kasir sebelum dan sesudah shift dibuka). `klik`
 * menekan elemen itu saat "Berikutnya" ditekan — dipakai untuk pindah tab
 * (Produk → Keranjang di ponsel) supaya langkah sesudahnya terlihat;
 * jangan pernah dipakai pada tombol yang menyimpan, menghapus, atau
 * berpindah halaman.
 */
import i18n from "@/i18n";
import DAFTAR from "@/Panduan/daftar.json";

const MUAT_TEKS = {
    id: () => import("@/Panduan/teks/id.json"),
    en: () => import("@/Panduan/teks/en.json"),
};

/** Panduan sebuah komponen halaman (sudah mengikuti rujukan), atau null. */
export function guidesFor(component) {
    let entri = DAFTAR[component];
    // Rujukan berantai tidak dipakai; satu lompatan cukup dan aman.
    if (typeof entri === "string") entri = DAFTAR[entri];
    return Array.isArray(entri) && entri.length > 0 ? entri : null;
}

export function hasGuide(component) {
    return guidesFor(component) !== null;
}

function terlihat(el) {
    if (!el || !el.isConnected) return false;
    // `display: contents` dan elemen di dalam induk yang disembunyikan
    // tidak punya kotak — driver.js juga tidak bisa menyorotnya.
    if (el.getClientRects().length === 0) return false;
    const kotak = el.getBoundingClientRect();
    if (kotak.width === 0 || kotak.height === 0) return false;
    // Laci yang digeser ke luar layar (sidebar ponsel yang tertutup) tidak
    // bisa digulir ke dalam pandangan — bukan sesuatu yang bisa ditunjuk.
    if (kotak.right <= 0 || kotak.left >= window.innerWidth) return false;
    return getComputedStyle(el).visibility !== "hidden";
}

/** Elemen PERTAMA yang terlihat dengan `data-tour` bernama itu. */
export function findAnchor(name) {
    if (!name || typeof document === "undefined") return null;
    const semua = document.querySelectorAll(`[data-tour="${name}"]`);
    for (const el of semua) {
        if (terlihat(el)) return el;
    }
    return null;
}

/** Panduan yang berlaku untuk keadaan layar sekarang. */
export function pickGuide(component) {
    const daftar = guidesFor(component);
    if (!daftar) return null;
    return daftar.find((g) => !g.if || findAnchor(g.if)) ?? null;
}

export function tourLanguage() {
    return (i18n.language || "id").startsWith("en") ? "en" : "id";
}

const tembolokTeks = {};

export async function loadTourTexts(lang = tourLanguage()) {
    if (!tembolokTeks[lang]) {
        const muat = MUAT_TEKS[lang] ?? MUAT_TEKS.id;
        tembolokTeks[lang] = muat()
            .then((m) => m.default ?? m)
            .catch((galat) => {
                // Jangan simpan kegagalan (mis. sedang luring): coba lagi
                // saat panduan dibuka berikutnya.
                delete tembolokTeks[lang];
                throw galat;
            });
    }
    return tembolokTeks[lang];
}

function popoverDari(teks, spec) {
    return {
        title: teks?.title ?? "",
        description: teks?.desc ?? "",
        ...(spec.side ? { side: spec.side } : {}),
    };
}

/**
 * Langkah-langkah driver.js untuk satu panduan. SEMUA langkah yang punya
 * teks ikut; yang elemennya tidak terlihat dilewati driver.js saat langkah
 * itu dicapai (`skipMissingElement`), bukan saat panduan dibuka — sehingga
 * langkah di balik tab yang baru dibuka (`klik`) tetap bisa ditunjuk.
 *
 * @param {(el: Element) => void} reveal dipanggil saat "Berikutnya" ditekan
 *   pada langkah `klik`, dengan elemen yang sedang disorot.
 */
export function buildSteps(guide, texts, reveal) {
    const teksPanduan = texts?.[guide.key] ?? {};

    return guide.steps.flatMap((spec) => {
        const teks = teksPanduan[spec.id];
        if (!teks) return [];

        const popover = popoverDari(teks, spec);
        if (!spec.el) return [{ popover }];

        if (spec.klik) {
            popover.klik = true;
            popover.onNextClick = (el) => reveal(el);
            // driver.js belum melihat langkah di balik tab (masih
            // tersembunyi), jadi menganggap ini langkah terakhir — tombolnya
            // tetap "Berikutnya", bukan "Selesai".
            popover.doneBtnText = i18n.t("tour.next");
        }

        return [
            {
                // Fungsi, bukan elemen: dinilai ulang setiap kali langkah ini
                // dicapai — React bisa mengganti simpul DOM-nya, dan tab bisa
                // sudah berpindah.
                element: () => findAnchor(spec.el) ?? undefined,
                popover,
            },
        ];
    });
}

/** Banyaknya langkah terlihat di antara indeks `dari` dan `sampai` (inklusif). */
export function countVisible(steps, dari, sampai) {
    let n = 0;
    for (let i = Math.max(dari, 0); i <= sampai && i < steps.length; i++) {
        const step = steps[i];
        if (!step.element || step.element()) n += 1;
    }
    return n;
}
