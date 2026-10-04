import { useEffect, useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import {
    IconArrowRight,
    IconArrowUpRight,
    IconMenu2,
    IconX,
} from "@tabler/icons-react";
import { BRAND, ATRIBUSI } from "@/Utils/brand";

/*
 * Navigasi publik — SATU daftar untuk bilah atas PC, laci ponsel, dan kaki
 * halaman (pola `menuPublik` Aishii). Versi lama menyembunyikan seluruh
 * tautannya di bawah 768px tanpa laci pengganti: di ponsel halaman Fitur dan
 * Dokumentasi tidak punya pintu sama sekali.
 */
export const NAV_LINKS = [
    { label: "Fitur", href: "/fitur" },
    { label: "Dokumentasi", href: "/dokumentasi" },
    { label: "Riwayat versi", href: "/roadmap" },
    { label: "Kode sumber", href: "/kontribusi" },
];

function Merek({ kecil = false }) {
    return (
        <span className="flex items-center gap-2.5">
            <img
                src={BRAND.icon}
                alt=""
                width="32"
                height="32"
                className={kecil ? "size-7 rounded-lg" : "size-8 rounded-lg"}
            />
            {/* Pola merek Aishii: "Aishii" + nama produk berwarna utama. */}
            <span className="text-lg font-bold tracking-tight text-slate-900 dark:text-white max-[359px]:hidden">
                Aishii<span className="text-primary-600 dark:text-primary-400"> POS</span>
            </span>
        </span>
    );
}

export default function PublicLayout({ children, active = "" }) {
    const { auth } = usePage().props;
    const [laciTerbuka, setLaciTerbuka] = useState(false);
    const sudahMasuk = Boolean(auth?.user);

    // Laci menutup sendiri saat halaman berganti.
    const { url } = usePage();
    useEffect(() => setLaciTerbuka(false), [url]);

    const ajakan = sudahMasuk
        ? { label: "Buka Dashboard", href: "/dashboard" }
        : { label: "Masuk", href: "/login" };

    return (
        <div className="min-h-screen bg-slate-50 dark:bg-slate-950 flex flex-col">
            {/* ============ BILAH ATAS ============ */}
            <header className="sticky top-0 z-50 border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-2 px-4 sm:px-6">
                    <Link href="/" aria-label={BRAND.name}>
                        <Merek />
                    </Link>

                    <nav className="hidden lg:flex items-center gap-7">
                        {NAV_LINKS.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className={`text-sm transition-colors ${
                                    active === link.href
                                        ? "text-primary-600 dark:text-primary-400 font-semibold"
                                        : "text-slate-600 dark:text-slate-400 hover:text-primary-600"
                                }`}
                            >
                                {link.label}
                            </Link>
                        ))}
                        <a
                            href={BRAND.parentUrl}
                            className="inline-flex items-center gap-1 text-sm text-slate-600 dark:text-slate-400 hover:text-primary-600"
                        >
                            Aishii Bazar
                            <IconArrowUpRight size={14} />
                        </a>
                    </nav>

                    <div className="flex items-center gap-1 sm:gap-2">
                        <Link
                            href={ajakan.href}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-primary-700"
                        >
                            {ajakan.label}
                            <IconArrowRight size={16} className="max-sm:hidden" />
                        </Link>
                        <button
                            type="button"
                            onClick={() => setLaciTerbuka((v) => !v)}
                            className="lg:hidden rounded-lg p-2 text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            aria-label={laciTerbuka ? "Tutup menu" : "Buka menu"}
                            aria-expanded={laciTerbuka}
                        >
                            {laciTerbuka ? <IconX size={22} /> : <IconMenu2 size={22} />}
                        </button>
                    </div>
                </div>

                {laciTerbuka && (
                    <nav className="lg:hidden border-t border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex flex-col gap-1">
                            {NAV_LINKS.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className={`rounded-lg px-3 py-2.5 text-sm ${
                                        active === link.href
                                            ? "bg-primary-50 font-semibold text-primary-700 dark:bg-primary-950/50 dark:text-primary-300"
                                            : "text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                                    }`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                            <a
                                href={BRAND.parentUrl}
                                className="flex items-center gap-1 rounded-lg px-3 py-2.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                Aishii Bazar — untuk jualan di bazar
                                <IconArrowUpRight size={14} />
                            </a>
                        </div>
                    </nav>
                )}
            </header>

            {/* ============ ISI ============ */}
            <main className="flex-1">{children}</main>

            {/* ============ KAKI ============ */}
            <footer className="border-t border-slate-200 px-4 py-10 dark:border-slate-800 sm:px-6">
                <div className="mx-auto flex max-w-6xl flex-col gap-6 md:flex-row md:items-start md:justify-between">
                    <div className="max-w-sm">
                        <Merek kecil />
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            {BRAND.tagline}. Saudaranya,{" "}
                            <a href={BRAND.parentUrl} className="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                Aishii Bazar
                            </a>
                            , menuntun jualan di bazar dan lapak musiman.
                        </p>
                    </div>

                    {/* MEMBUNGKUS, bukan satu baris — deretan tautan yang tidak
                        boleh membungkus menyeret seluruh halaman di 320px. */}
                    <nav className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-500 dark:text-slate-400">
                        {NAV_LINKS.map((link) => (
                            <Link key={link.href} href={link.href} className="hover:text-primary-600 transition-colors">
                                {link.label}
                            </Link>
                        ))}
                        <Link href="/login" className="hover:text-primary-600 transition-colors">
                            Masuk
                        </Link>
                    </nav>
                </div>

                <div className="mx-auto mt-8 max-w-6xl border-t border-slate-200 pt-6 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <p>
                        © {new Date().getFullYear()} {BRAND.entity}. Dibuat untuk pedagang Indonesia.
                    </p>
                    {/* Atribusi pembuat asli — kewajiban lisensi MIT, dan kejujuran. */}
                    <p className="mt-1">
                        {ATRIBUSI}{" "}
                        <a
                            href={BRAND.upstream.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="underline hover:text-primary-600"
                        >
                            Proyek aslinya
                        </a>
                        .
                    </p>
                </div>
            </footer>
        </div>
    );
}
