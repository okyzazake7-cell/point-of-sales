import { useState } from "react";
import { Head, router, useForm } from "@inertiajs/react";
import { Toaster } from "react-hot-toast";
import { IconLogout, IconReceipt, IconBuildingStore } from "@tabler/icons-react";
import { BRAND } from "@/Utils/brand";
import { rupiah, tanggalJam, tanggalPendek } from "@/Utils/rupiah";

/**
 * Pengelola layanan Aishii POS (dokumen 24 §8): tagihan dan toko — hitungan
 * dan tanggal, bukan data dagang. Kartu, bukan tabel lebar: pengelola
 * paling sering membukanya dari ponsel, tepat sesudah notifikasi GoPay.
 */
const lencanaStatus = {
    menunggu: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200",
    lunas: "bg-success-100 text-success-700 dark:bg-success-950 dark:text-success-300",
    kedaluwarsa: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
    batal: "bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300",
};

function TandaiLunas({ tagihan }) {
    const [buka, setBuka] = useState(false);
    const { data, setData, post, processing, errors } = useForm({ alasan: "" });

    if (!buka) {
        return (
            <button
                type="button"
                onClick={() => setBuka(true)}
                className="mt-3 h-10 w-full rounded-xl bg-primary-600 text-sm font-semibold text-white hover:bg-primary-700"
            >
                Tandai lunas
            </button>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                post(`/pengelola/tagihan/${tagihan.id}/lunas`, { preserveScroll: true });
            }}
            className="mt-3 space-y-2"
        >
            <textarea
                value={data.alasan}
                onChange={(e) => setData("alasan", e.target.value)}
                rows={2}
                placeholder={`mis. Transfer ${rupiah(tagihan.total_bayar)} terlihat di GoPay pukul 10.05`}
                className="w-full rounded-xl border-2 border-slate-200 bg-white p-3 text-sm text-slate-900 focus:border-primary-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
            />
            {errors.alasan && <p className="text-sm text-danger-500">{errors.alasan}</p>}
            <div className="grid grid-cols-2 gap-2">
                <button type="button" onClick={() => setBuka(false)} className="h-10 rounded-xl border border-slate-200 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-300">
                    Batal
                </button>
                <button type="submit" disabled={processing} className="h-10 rounded-xl bg-primary-600 text-sm font-semibold text-white disabled:opacity-60">
                    Simpan lunas
                </button>
            </div>
        </form>
    );
}

export default function Index({ toko = [], tagihan = [], konfirmasiOtomatis, pengelola }) {
    const [tab, setTab] = useState("tagihan");
    const menunggu = tagihan.filter((t) => t.status === "menunggu").length;

    return (
        <>
            <Head title="Pengelola" />
            <Toaster position="top-center" />
            <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
                <header className="sticky top-0 z-10 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
                    <div className="mx-auto flex max-w-3xl items-center justify-between gap-3">
                        <div className="min-w-0">
                            <p className="truncate font-bold text-slate-900 dark:text-white">Pengelola {BRAND.name}</p>
                            <p className="truncate text-xs text-slate-500">{pengelola?.email}</p>
                        </div>
                        <button
                            type="button"
                            onClick={() => router.post("/pengelola/keluar")}
                            className="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-300"
                        >
                            <IconLogout size={16} />
                            Keluar
                        </button>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl space-y-4 px-4 py-4">
                    {!konfirmasiOtomatis && (
                        <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                            Buku tagihan Aishii belum terpasang (AISHII_SUPABASE_URL / AISHII_RAHASIA_POS) — semua tagihan
                            bernominal bulat dan menunggu tanda lunas di sini.
                        </p>
                    )}

                    <div className="grid grid-cols-2 gap-2 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                        <button
                            type="button"
                            onClick={() => setTab("tagihan")}
                            className={`inline-flex h-10 items-center justify-center gap-1.5 rounded-lg text-sm font-semibold ${tab === "tagihan" ? "bg-white text-primary-700 shadow dark:bg-slate-900 dark:text-primary-300" : "text-slate-600 dark:text-slate-300"}`}
                        >
                            <IconReceipt size={16} />
                            Tagihan{menunggu > 0 ? ` (${menunggu})` : ""}
                        </button>
                        <button
                            type="button"
                            onClick={() => setTab("toko")}
                            className={`inline-flex h-10 items-center justify-center gap-1.5 rounded-lg text-sm font-semibold ${tab === "toko" ? "bg-white text-primary-700 shadow dark:bg-slate-900 dark:text-primary-300" : "text-slate-600 dark:text-slate-300"}`}
                        >
                            <IconBuildingStore size={16} />
                            Toko ({toko.length})
                        </button>
                    </div>

                    {tab === "tagihan" && (
                        <ul className="space-y-3">
                            {tagihan.length === 0 && <li className="text-center text-sm text-slate-500">Belum ada tagihan.</li>}
                            {tagihan.map((t) => (
                                <li key={t.id} data-tagihan-pengelola className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-xl font-extrabold text-slate-900 dark:text-white">{rupiah(t.total_bayar)}</p>
                                            <p className="truncate text-sm text-slate-600 dark:text-slate-400">{t.toko}</p>
                                        </div>
                                        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${lencanaStatus[t.status] ?? ""}`}>{t.status}</span>
                                    </div>
                                    <p className="mt-2 break-words text-xs text-slate-500">
                                        {t.nomor} · {t.jenis === "perpanjang" ? `${t.kursi} outlet × ${t.bulan} bln` : `+${t.kursi} outlet`} ·{" "}
                                        {t.pencocok === "aishii" ? "kode unik otomatis" : "manual"}
                                    </p>
                                    <p className="text-xs text-slate-500">
                                        {t.status === "lunas"
                                            ? `Lunas ${tanggalJam(t.dibayar_pada)} (${t.dibayar_lewat})${t.alasan ? ` — ${t.alasan}` : ""}`
                                            : `Berlaku sampai ${tanggalJam(t.berlaku_sampai)}`}
                                    </p>
                                    {["menunggu", "kedaluwarsa"].includes(t.status) && <TandaiLunas tagihan={t} />}
                                </li>
                            ))}
                        </ul>
                    )}

                    {tab === "toko" && (
                        <ul className="space-y-3">
                            {toko.length === 0 && <li className="text-center text-sm text-slate-500">Belum ada toko.</li>}
                            {toko.map((t) => (
                                <li key={t.id} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="truncate font-semibold text-slate-900 dark:text-white">{t.nama}</p>
                                            <p className="truncate text-xs text-slate-500">{t.email_pemilik}</p>
                                        </div>
                                        <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${t.terkunci ? lencanaStatus.menunggu : lencanaStatus.lunas}`}>
                                            {t.terkunci ? (t.aktif_sampai ? "Terkunci" : "Belum bayar") : "Aktif"}
                                        </span>
                                    </div>
                                    <p className="mt-2 break-words text-xs text-slate-500">
                                        {t.kode} · {t.kursi_outlet} kursi outlet ·{" "}
                                        {t.aktif_sampai ? `aktif sampai ${tanggalPendek(t.aktif_sampai)}` : `daftar ${tanggalPendek(t.dibuat_pada)}`}
                                        {t.status_basis_data !== "siap" ? ` · basis data ${t.status_basis_data}` : ""}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </main>
            </div>
        </>
    );
}
