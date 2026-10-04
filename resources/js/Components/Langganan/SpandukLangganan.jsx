import { useEffect } from "react";
import { Link, usePage } from "@inertiajs/react";
import toast from "react-hot-toast";
import { IconLock, IconClockExclamation, IconArrowRight } from "@tabler/icons-react";
import { tanggalPendek } from "@/Utils/rupiah";

/**
 * Spanduk status langganan Aishii POS (mode banyak toko, dokumen 24 §6).
 *
 * Ada di SEMUA halaman toko, untuk semua peran: kasir yang tombolnya
 * ditolak harus tahu sebabnya tanpa bertanya. Tulis yang ditolak kunci
 * kembali dengan `errors.langganan` — spanduk ini juga yang mengabarkannya.
 * Mode satu toko tidak membagikan prop `langganan`, jadi spanduknya diam.
 */
export default function SpandukLangganan({ className = "" }) {
    const { langganan, errors, url } = { ...usePage().props, url: usePage().url };
    const pesanKunci = errors?.langganan;

    useEffect(() => {
        if (pesanKunci) toast.error(pesanKunci, { id: "kunci-langganan", duration: 6000 });
    }, [pesanKunci]);

    if (!langganan) return null;
    const diHalamanLangganan = url.startsWith("/dashboard/langganan");

    if (langganan.terkunci) {
        return (
            <div
                data-spanduk-langganan="terkunci"
                className={`flex flex-col gap-3 rounded-2xl border border-danger-200 bg-danger-50 px-4 py-3 text-danger-800 dark:border-danger-900 dark:bg-danger-950/40 dark:text-danger-200 sm:flex-row sm:items-center ${className}`}
            >
                <IconLock size={22} className="hidden shrink-0 sm:block" />
                <div className="min-w-0 flex-1 text-sm">
                    <p className="font-semibold">
                        {langganan.pernah_aktif
                            ? `Langganan berakhir ${tanggalPendek(langganan.aktif_sampai)}`
                            : "Toko ini belum aktif"}
                    </p>
                    <p className="mt-0.5">
                        {langganan.pernah_aktif
                            ? "Kasir dan pencatatan berhenti. Laporan dan data tetap bisa dibaca — tidak ada yang dihapus."
                            : "Bayar tagihan pertama untuk mulai berjualan. Sampai itu, semua halaman bisa dilihat tetapi belum bisa dipakai mencatat."}
                    </p>
                </div>
                {!diHalamanLangganan && (
                    <Link
                        href="/dashboard/langganan"
                        className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-danger-600 px-4 py-2 text-sm font-semibold text-white hover:bg-danger-700"
                    >
                        {langganan.pernah_aktif ? "Perpanjang" : "Bayar sekarang"}
                        <IconArrowRight size={16} />
                    </Link>
                )}
            </div>
        );
    }

    if (langganan.perlu_diingatkan) {
        return (
            <div
                data-spanduk-langganan="pengingat"
                className={`flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200 sm:flex-row sm:items-center ${className}`}
            >
                <IconClockExclamation size={22} className="hidden shrink-0 sm:block" />
                <p className="min-w-0 flex-1 text-sm">
                    <span className="font-semibold">
                        Langganan berakhir {langganan.sisa_hari <= 1 ? "besok" : `dalam ${langganan.sisa_hari} hari`}
                    </span>{" "}
                    ({tanggalPendek(langganan.aktif_sampai)}). Sesudah itu kasir berhenti sampai diperpanjang.
                </p>
                {!diHalamanLangganan && (
                    <Link
                        href="/dashboard/langganan"
                        className="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700"
                    >
                        Perpanjang
                        <IconArrowRight size={16} />
                    </Link>
                )}
            </div>
        );
    }

    return null;
}
