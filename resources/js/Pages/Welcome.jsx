import { Head, Link, usePage } from "@inertiajs/react";
import PublicLayout from "@/Layouts/PublicLayout";
import { BRAND } from "@/Utils/brand";
import {
    IconArrowRight,
    IconArrowUpRight,
    IconBarcode,
    IconBuildingWarehouse,
    IconChartBar,
    IconCheck,
    IconCloudOff,
    IconHeartHandshake,
    IconPrinter,
    IconQrcode,
    IconReceiptTax,
    IconShieldCheck,
    IconTruck,
    IconWallet,
} from "@tabler/icons-react";

/*
 * HALAMAN DEPAN AISHII POS.
 *
 * Versi pembuat aslinya ditujukan kepada PENGEMBANG — bintang GitHub, "Get
 * Source", perintah `git clone`. Di keluarga Aishii pembacanya pemilik toko:
 * yang ia tanyakan "cocok untuk tokoku atau tidak", bukan "bagaimana
 * memasangnya". Kode sumber dan atribusinya tetap terbuka di halaman "Kode
 * sumber" dan di kaki halaman.
 *
 * Isinya sejajar dengan halaman `/pos` di Aishii (`app/utils/faktaPos.ts`) —
 * orang yang datang lewat menu Aishii membaca janji yang sama di sini.
 */

const fitur = [
    {
        icon: IconBarcode,
        title: "Kasir berbarcode, cepat di jam ramai",
        desc: "Cari barang lewat barcode atau nama — kamera HP pun bisa jadi pemindai. Transaksi bisa ditahan dulu lalu dilanjutkan.",
    },
    {
        icon: IconWallet,
        title: "Tunai, transfer, QRIS, sampai bayar nanti",
        desc: "Satu kasir untuk semua cara bayar; yang dibayar nanti tercatat otomatis sebagai piutang pelanggan.",
    },
    {
        icon: IconBuildingWarehouse,
        title: "Stok per gudang dan per cabang",
        desc: "Stok tiap gudang terpisah, bisa dipindah antar-gudang, dihitung ulang lewat stok opname, berikut tanggal kedaluwarsanya.",
    },
    {
        icon: IconTruck,
        title: "Pemasok, pesanan beli, dan retur",
        desc: "Pesanan ke pemasok, penerimaan barang, retur, dan hutang yang jatuh tempo — riwayat kulakan tersimpan per nota.",
    },
    {
        icon: IconQrcode,
        title: "Pesanan dari meja lewat QR",
        desc: "Pelanggan memindai QR di meja, memesan dari menunya, dan pesanannya masuk ke kasir.",
    },
    {
        icon: IconHeartHandshake,
        title: "Member, poin, dan voucher",
        desc: "Tingkat member, poin yang bisa ditukar, voucher, dan pengingat lewat WhatsApp untuk pelanggan setia.",
    },
    {
        icon: IconChartBar,
        title: "Laporan penjualan dan laba",
        desc: "Penjualan, laba per barang, jam paling ramai, dan kinerja tiap kasir — bisa diunduh ke Excel atau PDF.",
    },
    {
        icon: IconShieldCheck,
        title: "Peran, persetujuan diskon, dan jejak perubahan",
        desc: "Hak akses per peran, diskon besar menunggu persetujuan, dan tiap perubahan data tercatat sebelum-sesudahnya.",
    },
    {
        icon: IconPrinter,
        title: "Struk thermal, PPN, dan shift kasir",
        desc: "Struk 58 atau 80 mm, PPN termasuk atau terpisah, dan kas laci dicocokkan tiap buka-tutup shift.",
    },
];

const sorotan = [
    { icon: IconCloudOff, label: "Tetap mencatat saat internet putus" },
    { icon: IconBuildingWarehouse, label: "Banyak gudang & cabang" },
    { icon: IconReceiptTax, label: "PPN termasuk atau terpisah" },
    { icon: IconBarcode, label: "Kamera HP jadi pemindai" },
];

const pilihBazar = [
    "Ikut bazar, pameran, atau buka lapak musiman",
    "Mau tahu untung-rugi sebelum menyewa lapak",
    "Menu dari resep, modalnya dihitung per porsi",
    "Kasir di HP yang tetap jalan tanpa sinyal",
];

const pilihPos = [
    "Toko, minimarket, atau resto yang buka tiap hari",
    "Barang berbarcode dan stok di lebih dari satu gudang",
    "Belanja ke pemasok, retur, dan hutang kulakan",
    "Meja restoran, member, dan tim kasir bergiliran",
];

const tangkapan = [
    { src: "/screenshots/01-dashboard.png", title: "Dashboard", span: "col-span-2 row-span-2" },
    { src: "/screenshots/02-pos-checkout.png", title: "Kasir" },
    { src: "/screenshots/06-stock-opnames.png", title: "Stok opname" },
    { src: "/screenshots/12-receivables.png", title: "Piutang" },
    { src: "/screenshots/15-sales-report.png", title: "Laporan penjualan" },
];

const tanya = [
    {
        q: `Apa bedanya ${BRAND.name} dengan Aishii Bazar?`,
        a: "Aishii Bazar menuntun jualan di bazar dan lapak musiman: menghitung untung-rugi sebelum menyewa lapak, belanja bahan, kasir di HP, sampai laporan per bazar. Aishii POS untuk toko yang buka tiap hari dengan barang berbarcode, gudang, pemasok, dan tim kasir. Keduanya aplikasi terpisah dengan akunnya masing-masing.",
    },
    {
        q: "Bisakah dipakai untuk banyak cabang?",
        a: "Bisa. Stok terpisah per gudang atau cabang, barang bisa dipindah antar-gudang, dan laporannya bisa dibaca per gudang.",
    },
    {
        q: "Bagaimana kalau internet di toko mati?",
        a: "Pembayaran yang sudah disiapkan masuk antrean di perangkat dan terkirim sendiri begitu koneksi kembali. Menambah barang baru ke keranjang saat luring masih terbatas, sebab keranjangnya disimpan di server.",
    },
    {
        q: "Perlu perangkat khusus?",
        a: "Tidak. Cukup HP, tablet, atau komputer dengan peramban. Kamera HP bisa menjadi pemindai barcode, dan printer thermal disambungkan langsung lewat USB di peramban Chrome.",
    },
    {
        q: "Apakah kode sumbernya terbuka?",
        a: `Ya. ${BRAND.name} dibangun di atas proyek open-source ${BRAND.upstream.name} karya ${BRAND.upstream.author} dengan lisensi ${BRAND.upstream.license}, dan kodenya bisa dibaca siapa pun.`,
    },
];

export default function Welcome() {
    const { auth } = usePage().props;
    const ajakan = auth?.user
        ? { label: "Buka Dashboard", href: "/dashboard" }
        : { label: "Masuk", href: "/login" };

    return (
        <PublicLayout>
            <Head title={BRAND.tagline} />

            {/* ============ PEMBUKA ============ */}
            <section className="relative overflow-hidden px-4 pt-16 pb-12 sm:px-6 sm:pt-24">
                <div className="pointer-events-none absolute inset-0 -z-10" aria-hidden="true">
                    <div className="absolute -top-40 left-1/2 size-[40rem] -translate-x-1/2 rounded-full bg-primary-500/15 blur-3xl" />
                    <div className="absolute top-1/3 -right-32 size-[24rem] rounded-full bg-accent-500/10 blur-3xl" />
                </div>

                <div className="mx-auto max-w-3xl text-center">
                    <span className="inline-flex max-w-full items-center gap-2 rounded-full border border-primary-100 bg-primary-50 px-4 py-1.5 text-sm font-medium text-primary-700 dark:border-primary-900 dark:bg-primary-950/50 dark:text-primary-300">
                        Keluarga Aishii
                    </span>

                    <h1 className="mt-6 text-4xl font-extrabold tracking-tight text-slate-900 dark:text-white sm:text-6xl">
                        Kasir toko yang memegang{" "}
                        <span className="bg-gradient-to-r from-primary-600 to-accent-500 bg-clip-text text-transparent">
                            stok, pemasok, dan cabangmu
                        </span>
                    </h1>

                    <p className="mx-auto mt-6 max-w-2xl text-lg text-slate-600 dark:text-slate-400">
                        {BRAND.name} mencatat penjualan dengan barcode, menjaga stok di tiap gudang,
                        mengurus belanja ke pemasok, dan menyusun laporan laba — dalam bahasa sehari-hari,
                        di HP maupun komputer.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <Link
                            href={ajakan.href}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-primary-600 px-7 py-3.5 text-base font-semibold text-white shadow-lg shadow-primary-600/25 transition-colors hover:bg-primary-700 sm:w-auto"
                        >
                            {ajakan.label}
                            <IconArrowRight size={18} />
                        </Link>
                        <Link
                            href="/fitur"
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl px-7 py-3.5 text-base font-semibold text-slate-700 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 sm:w-auto"
                        >
                            Lihat semua fitur
                        </Link>
                    </div>

                    <p className="mt-6 text-sm text-slate-500 dark:text-slate-400">
                        Jualan di bazar atau lapak musiman?{" "}
                        <a href={BRAND.parentUrl} className="inline-flex items-center gap-0.5 font-medium text-primary-600 hover:underline dark:text-primary-400">
                            Aishii Bazar dibuat untuk itu
                            <IconArrowUpRight size={14} />
                        </a>
                    </p>
                </div>

                {/* Pratinjau aplikasi */}
                <div className="relative mx-auto mt-14 max-w-5xl">
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center gap-2 bg-slate-100 px-4 py-3 dark:bg-slate-800">
                            <div className="flex gap-2" aria-hidden="true">
                                <div className="size-3 rounded-full bg-red-400" />
                                <div className="size-3 rounded-full bg-yellow-400" />
                                <div className="size-3 rounded-full bg-green-400" />
                            </div>
                            <div className="flex-1 truncate text-center text-xs text-slate-500">
                                {BRAND.name}
                            </div>
                        </div>
                        <img
                            src="/screenshots/02-pos-checkout.png"
                            alt={`Layar kasir ${BRAND.name}`}
                            width="1440"
                            height="900"
                            className="w-full"
                            loading="lazy"
                        />
                    </div>
                </div>
            </section>

            {/* ============ SOROTAN ============ */}
            <section className="border-y border-slate-200 bg-white px-4 py-10 dark:border-slate-800 dark:bg-slate-900/50 sm:px-6">
                <div className="mx-auto grid max-w-5xl grid-cols-2 gap-6 md:grid-cols-4">
                    {sorotan.map((s) => (
                        <div key={s.label} className="flex flex-col items-center gap-2 text-center">
                            <div className="flex size-11 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:text-primary-400">
                                <s.icon size={24} />
                            </div>
                            <p className="text-sm font-medium text-slate-700 dark:text-slate-300">{s.label}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* ============ FITUR ============ */}
            <section id="fitur" className="px-4 py-20 sm:px-6">
                <div className="mx-auto max-w-6xl">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                            Yang dikerjakan {BRAND.name}
                        </h2>
                        <p className="mt-4 text-slate-600 dark:text-slate-400">
                            Catat sekali di kasir, terpakai di mana-mana: stok gudang berkurang sendiri,
                            piutang tercatat, dan laporan laba tersusun tanpa diketik ulang.
                        </p>
                    </div>

                    <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {fitur.map((f) => (
                            <div
                                key={f.title}
                                className="h-full rounded-2xl border border-slate-200 bg-white p-6 transition hover:border-primary-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900"
                            >
                                <div className="flex size-11 items-center justify-center rounded-xl bg-primary-500/10 text-primary-600 dark:text-primary-400">
                                    <f.icon size={24} />
                                </div>
                                <h3 className="mt-4 font-semibold text-slate-900 dark:text-white">{f.title}</h3>
                                <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">{f.desc}</p>
                            </div>
                        ))}
                    </div>

                    <div className="mt-10 text-center">
                        <Link
                            href="/fitur"
                            className="inline-flex items-center gap-2 rounded-xl border border-primary-200 px-6 py-3 text-sm font-semibold text-primary-700 transition-colors hover:bg-primary-50 dark:border-primary-800 dark:text-primary-300 dark:hover:bg-primary-950/40"
                        >
                            Jelajahi semua fitur
                            <IconArrowRight size={16} />
                        </Link>
                    </div>
                </div>
            </section>

            {/* ============ BAZAR ATAU TOKO ============ */}
            <section className="border-t border-slate-200 bg-white px-4 py-20 dark:border-slate-800 dark:bg-slate-900/50 sm:px-6">
                <div className="mx-auto max-w-5xl">
                    <div className="mx-auto max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                            Bazar atau toko?
                        </h2>
                        <p className="mt-4 text-slate-600 dark:text-slate-400">
                            Keluarga Aishii punya dua aplikasi. Pilih yang sesuai dengan cara kamu berjualan.
                        </p>
                    </div>

                    <div className="mt-12 grid gap-6 md:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 p-6 dark:border-slate-800">
                            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">Aishii Bazar</h3>
                            <ul className="mt-4 space-y-2">
                                {pilihBazar.map((b) => (
                                    <li key={b} className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-400">
                                        <IconCheck size={18} className="mt-0.5 shrink-0 text-success-500" />
                                        {b}
                                    </li>
                                ))}
                            </ul>
                            <a
                                href={BRAND.parentUrl}
                                className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400"
                            >
                                Kenalan dengan Aishii Bazar
                                <IconArrowUpRight size={16} />
                            </a>
                        </div>
                        <div className="rounded-2xl border-2 border-primary-500 p-6">
                            <h3 className="text-lg font-semibold text-slate-900 dark:text-white">{BRAND.name}</h3>
                            <ul className="mt-4 space-y-2">
                                {pilihPos.map((b) => (
                                    <li key={b} className="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-400">
                                        <IconCheck size={18} className="mt-0.5 shrink-0 text-success-500" />
                                        {b}
                                    </li>
                                ))}
                            </ul>
                            <Link
                                href={ajakan.href}
                                className="mt-6 inline-flex items-center gap-1 text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400"
                            >
                                {ajakan.label}
                                <IconArrowRight size={16} />
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            {/* ============ TAMPILAN ============ */}
            <section className="border-t border-slate-200 px-4 py-20 dark:border-slate-800 sm:px-6">
                <div className="mx-auto max-w-6xl">
                    <div className="mx-auto mb-12 max-w-2xl text-center">
                        <h2 className="text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                            Tampilan aplikasinya
                        </h2>
                        <p className="mt-4 text-slate-600 dark:text-slate-400">
                            Dari kasir harian sampai laporan untuk pemilik — semuanya di satu aplikasi.
                        </p>
                    </div>

                    <div className="grid auto-rows-[120px] grid-cols-2 gap-4 sm:auto-rows-[180px] md:grid-cols-3">
                        {tangkapan.map((t) => (
                            <div
                                key={t.title}
                                className={`${t.span || ""} group relative overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800`}
                            >
                                <img
                                    src={t.src}
                                    alt={t.title}
                                    className="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-105"
                                    loading="lazy"
                                />
                                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/60 to-transparent px-3 py-2">
                                    <span className="text-xs font-medium text-white">{t.title}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ============ TANYA JAWAB ============ */}
            <section className="border-t border-slate-200 bg-white px-4 py-20 dark:border-slate-800 dark:bg-slate-900/50 sm:px-6">
                <div className="mx-auto max-w-3xl">
                    <h2 className="text-center text-3xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-4xl">
                        Yang sering ditanyakan
                    </h2>
                    <div className="mt-10 space-y-3">
                        {tanya.map((t) => (
                            <details
                                key={t.q}
                                className="group rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                            >
                                <summary className="cursor-pointer list-none font-semibold text-slate-900 dark:text-white">
                                    {t.q}
                                </summary>
                                <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">{t.a}</p>
                            </details>
                        ))}
                    </div>
                </div>
            </section>

            {/* ============ AJAKAN PENUTUP ============ */}
            <section className="px-4 py-20 sm:px-6">
                <div className="relative mx-auto max-w-5xl overflow-hidden rounded-3xl bg-gradient-to-br from-primary-700 to-primary-950 px-6 py-16 text-center">
                    <div className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-white/10 blur-3xl" aria-hidden="true" />
                    <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Toko yang rapi mulai dari kasirnya
                    </h2>
                    <p className="mx-auto mt-4 max-w-xl text-primary-100">
                        Masuk dengan akun yang dibuat pemilik toko, lalu mulai mencatat penjualan pertama.
                    </p>
                    <div className="mt-8 flex justify-center">
                        <Link
                            href={ajakan.href}
                            className="inline-flex items-center gap-2 rounded-xl bg-white px-7 py-3.5 text-base font-semibold text-primary-700 transition-colors hover:bg-primary-50"
                        >
                            {ajakan.label}
                            <IconArrowRight size={18} />
                        </Link>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
