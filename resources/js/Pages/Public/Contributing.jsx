import { Head } from "@inertiajs/react";
import PublicLayout from "@/Layouts/PublicLayout";
import {
    IconArrowUpRight,
    IconBrandGithub,
    IconCode,
    IconHeart,
    IconScale,
    IconTerminal2,
} from "@tabler/icons-react";
import { BRAND } from "@/Utils/brand";

/*
 * KODE SUMBER — pengganti "Bantu Dikasir Tumbuh".
 *
 * Halaman lama mengajak pembaca berkontribusi ke proyek pembuat aslinya
 * atas nama merek yang sekarang bukan mereknya. Yang jujur dikatakan di
 * bawah merek Aishii ada tiga: kodenya terbuka dan di mana, dari mana ia
 * berasal (kewajiban lisensi MIT), dan ke mana perbaikan yang berlaku umum
 * sebaiknya dikirim — ke proyek aslinya, supaya seluruh pemakainya ikut
 * menikmati.
 */
const perintah = `git clone ${BRAND.sourceUrl}
cd point-of-sales
cp .env.example .env
composer install && PUPPETEER_SKIP_DOWNLOAD=true npm install
php artisan key:generate
php artisan migrate --seed
php artisan storage:link

# Server, antrean, log, dan Vite sekaligus
composer run dev`;

export default function Contributing() {
    return (
        <PublicLayout active="/kontribusi">
            <Head title="Kode sumber" />

            <section className="px-4 pt-16 pb-14 sm:px-6 sm:pt-20 bg-gradient-to-b from-primary-50 dark:from-primary-950/40 to-transparent">
                <div className="mx-auto max-w-3xl text-center">
                    <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-primary-100 bg-primary-50 px-4 py-2 text-sm font-medium text-primary-700 dark:border-primary-900 dark:bg-primary-950/50 dark:text-primary-300">
                        <IconCode size={16} />
                        Kode sumber
                    </div>
                    <h1 className="text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white md:text-5xl">
                        Kodenya terbuka, asal-usulnya disebut
                    </h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg text-slate-600 dark:text-slate-400">
                        {BRAND.name} adalah fork proyek open-source {BRAND.upstream.name} karya{" "}
                        {BRAND.upstream.author}, berlisensi {BRAND.upstream.license}. Siapa pun boleh membaca
                        kodenya.
                    </p>
                </div>
            </section>

            <section className="px-4 pb-16 sm:px-6">
                <div className="mx-auto grid max-w-4xl gap-6 md:grid-cols-2">
                    <a
                        href={BRAND.sourceUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-primary-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"
                    >
                        <div className="flex size-11 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:text-primary-400">
                            <IconBrandGithub size={24} />
                        </div>
                        <h2 className="mt-4 flex items-center gap-1 font-semibold text-slate-900 dark:text-white">
                            Kode {BRAND.name}
                            <IconArrowUpRight size={16} className="text-slate-400 group-hover:text-primary-500" />
                        </h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            Repositori yang benar-benar dijalankan aplikasi ini, berikut dokumen tiap modulnya.
                        </p>
                    </a>
                    <a
                        href={BRAND.upstream.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="group rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-primary-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"
                    >
                        <div className="flex size-11 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:text-primary-400">
                            <IconHeart size={24} />
                        </div>
                        <h2 className="mt-4 flex items-center gap-1 font-semibold text-slate-900 dark:text-white">
                            Proyek aslinya
                            <IconArrowUpRight size={16} className="text-slate-400 group-hover:text-primary-500" />
                        </h2>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            {BRAND.upstream.name} karya {BRAND.upstream.author}. Perbaikan yang berlaku umum
                            sebaiknya dikirim ke sana, supaya seluruh pemakainya ikut menikmati.
                        </p>
                    </a>
                </div>
            </section>

            <section className="px-4 pb-16 sm:px-6">
                <div className="mx-auto max-w-4xl">
                    <h2 className="mb-4 flex items-center gap-3 text-2xl font-bold text-slate-900 dark:text-white">
                        <IconTerminal2 size={24} className="text-primary-600 dark:text-primary-400" />
                        Menjalankannya sendiri
                    </h2>
                    <p className="mb-4 text-sm text-slate-600 dark:text-slate-400">
                        Butuh PHP 8.3+, Composer, Node.js 22, dan MySQL (atau SQLite untuk mencoba).
                    </p>
                    <pre className="overflow-x-auto rounded-2xl bg-slate-900 p-5 text-sm leading-relaxed text-slate-100">
                        <code>{perintah}</code>
                    </pre>
                </div>
            </section>

            <section className="px-4 pb-20 sm:px-6">
                <div className="mx-auto max-w-4xl rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="flex items-center gap-3 text-xl font-bold text-slate-900 dark:text-white">
                        <IconScale size={22} className="text-primary-600 dark:text-primary-400" />
                        Lisensi {BRAND.upstream.license}
                    </h2>
                    <p className="mt-3 text-sm text-slate-600 dark:text-slate-400">
                        Kode ini boleh dipakai, diubah, dan dibagikan, selama pemberitahuan hak cipta{" "}
                        {BRAND.upstream.author} dan teks lisensinya ikut di setiap salinan.{" "}
                        <a
                            href={`${BRAND.sourceUrl}/blob/main/LICENSE`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            Baca teks lisensinya
                        </a>
                        .
                    </p>
                </div>
            </section>
        </PublicLayout>
    );
}
