/** Rupiah tanpa desimal, cara orang Indonesia menulisnya: "Rp 25.000". */
export function rupiah(angka) {
    return `Rp ${new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 }).format(Number(angka) || 0)}`;
}

/** Tanggal pendek berbahasa Indonesia: "3 Nov 2026". */
export function tanggalPendek(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleDateString("id-ID", { day: "numeric", month: "short", year: "numeric" });
}

/** Tanggal + jam: "3 Nov 2026 10.00". */
export function tanggalJam(iso) {
    if (!iso) return "—";
    return new Date(iso).toLocaleString("id-ID", {
        day: "numeric",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}
