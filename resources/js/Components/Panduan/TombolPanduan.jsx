import { IconQuestionMark } from "@tabler/icons-react";
import { useTour } from "@/Hooks/useTour";
import i18n from "@/i18n";

/**
 * Tombol "Panduan Penggunaan" untuk halaman yang TIDAK memakai bilah atas
 * dashboard atau kasir (halaman struk, halaman tamu). Bilah atas itu sudah
 * memanggil `useTour` sendiri — jangan pasang tombol ini di halamannya,
 * nanti panduannya muncul dua kali.
 */
export default function TombolPanduan({ className = "" }) {
    const { start, isActive, available } = useTour();

    if (!available) return null;

    return (
        <button
            type="button"
            data-tour="page-help"
            onClick={start}
            disabled={isActive}
            title={i18n.t("tour.button")}
            className={`inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm font-medium text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 ${className}`}
        >
            <IconQuestionMark size={18} />
            <span>{i18n.t("tour.button")}</span>
        </button>
    );
}
