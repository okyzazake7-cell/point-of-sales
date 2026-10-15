import { usePage } from "@inertiajs/react";
import { useCallback, useEffect, useRef, useState } from "react";
import axios from "axios";
import toast from "react-hot-toast";
import { driver } from "driver.js";
import "driver.js/dist/driver.css";
import i18n from "@/i18n";
import {
    buildSteps,
    countVisible,
    hasGuide,
    loadTourTexts,
    pickGuide,
} from "@/Utils/tours";

/*
 * Kunci yang selesai di sesi ini. `auth.completedTours` baru ikut berubah
 * pada kunjungan halaman berikutnya — pindah halaman sebelum permintaan
 * "selesai" kembali tidak boleh memunculkan panduan yang baru ditutup.
 */
const selesaiSesiIni = new Set();

/** Dipanggil "Putar Ulang Panduan": semua panduan boleh muncul lagi. */
export function forgetCompletedTours() {
    selesaiSesiIni.clear();
}

/**
 * Panduan untuk HALAMAN YANG SEDANG DIBUKA (komponen Inertia-nya). Tata
 * letak dashboard dan kasir tetap terpasang saat berpindah halaman, jadi
 * hook ini mengikuti `component`: panduan halaman lama ditutup, panduan
 * halaman baru muncul sendiri sekali — sesudahnya lewat tombol "?".
 */
export function useTour() {
    const page = usePage();
    const component = page.component;
    const completed = page.props?.auth?.completedTours;
    const driverRef = useRef(null);
    const keyRef = useRef(null);
    const componentRef = useRef(component);
    const [isActive, setIsActive] = useState(false);

    componentRef.current = component;

    const markCompleted = useCallback((key) => {
        selesaiSesiIni.add(key);
        // ponytail: plain axios — Inertia router rejects the JSON-only response
        axios
            .post(route("tours.complete", { tour: key }), {}, {
                headers: { Accept: "application/json" },
            })
            .catch(() => {});
    }, []);

    const start = useCallback(
        async ({ auto = false } = {}) => {
            if (driverRef.current) return;

            const guide = pickGuide(component);
            if (!guide) return;
            const sudah =
                (completed ?? []).includes(guide.key) ||
                selesaiSesiIni.has(guide.key);
            if (auto && sudah) return;

            let texts;
            try {
                texts = await loadTourTexts();
            } catch {
                if (!auto) toast.error(i18n.t("tour.load_failed"));
                return;
            }
            // Halaman bisa sudah berganti selama teks dimuat.
            if (componentRef.current !== component || driverRef.current) return;

            // Langkah `klik`: tekan elemennya (pindah tab), beri React waktu
            // menggambar tab baru, baru maju — langkah di baliknya kini
            // terlihat. Ketukan ganda tidak boleh melompati satu langkah.
            // `dibuka` mengingat nomor langkah saat tab berpindah, supaya
            // penomoran berlanjut ("7 dari 14"), bukan mulai dari awal.
            let sedangMembuka = false;
            let dibuka = null;
            const reveal = (el) => {
                if (sedangMembuka) return;
                sedangMembuka = true;
                const aktif = driverRef.current?.getActiveIndex() ?? 0;
                dibuka = { indeks: aktif, nomor: countVisible(steps, 0, aktif) };
                el?.click();
                setTimeout(() => {
                    sedangMembuka = false;
                    driverRef.current?.moveNext();
                }, 350);
            };

            const steps = buildSteps(guide, texts, reveal);
            if (steps.length === 0) return;

            const nomorLangkah = (aktif) => {
                const akhir = steps.length - 1;
                if (dibuka && aktif >= dibuka.indeks) {
                    return {
                        current: dibuka.nomor + countVisible(steps, dibuka.indeks + 1, aktif),
                        total: dibuka.nomor + countVisible(steps, dibuka.indeks + 1, akhir),
                    };
                }
                // Sebelum tab dibuka, banyaknya langkah di baliknya belum
                // bisa dihitung: tampilkan nomornya saja.
                const masihTertutup = steps.some(
                    (step, i) => i >= aktif && step.popover.klik && step.element()
                );
                return {
                    current: countVisible(steps, 0, aktif),
                    total: masihTertutup ? null : countVisible(steps, 0, akhir),
                };
            };

            keyRef.current = guide.key;
            driverRef.current = driver({
                // Tombol bawaan driver.js berbahasa Inggris ("Next", "1 of 6")
                // di atas isi berbahasa Indonesia — diterjemahkan lewat i18n.
                nextBtnText: i18n.t("tour.next"),
                prevBtnText: i18n.t("tour.prev"),
                doneBtnText: i18n.t("tour.done"),
                showProgress: true,
                popoverClass: "panduan-popover",
                stagePadding: 6,
                stageRadius: 12,
                smoothScroll: true,
                // Langkah yang elemennya tidak terlihat (lebar layar ini,
                // daftar kosong, tab lain) dilewati, maju maupun mundur.
                skipMissingElement: true,
                // Ketukan tak sengaja di luar kotak tidak menghapus panduan
                // yang belum selesai dibaca; menutup tetap lewat × atau Esc.
                overlayClickBehavior: () => {},
                steps,
                onPopoverRender: (popover, { driver: d, state }) => {
                    // Hitungan "3 dari 8" hanya atas langkah yang terlihat,
                    // supaya nomornya tidak melompat.
                    const { current, total } = nomorLangkah(state.activeIndex ?? 0);
                    popover.progress.innerText =
                        total === null
                            ? i18n.t("tour.step", { n: current })
                            : `${current} ${i18n.t("tour.of")} ${total}`;
                    // Tombol "Berikutnya" yang ditekan sebelum perpindahan
                    // sorotan selesai meninggalkan tanda "aktif" di elemen
                    // lama (driver.js) — elemen itu tetap bisa diketuk.
                    document
                        .querySelectorAll(".driver-active-element")
                        .forEach((el) => {
                            if (el !== state.activeElement) {
                                el.classList.remove("driver-active-element", "driver-no-interaction");
                            }
                        });
                    // Langkah terakhir mengingatkan jalan kembali ke panduan.
                    if (d.isLastStep() && !state.activeStep?.popover?.klik) {
                        const catatan = document.createElement("p");
                        catatan.className = "panduan-catatan";
                        catatan.innerHTML = i18n.t("tour.reopen_hint");
                        popover.description.appendChild(catatan);
                        popover.description.style.display = "block";
                    }
                },
                onDestroyed: () => {
                    driverRef.current = null;
                    keyRef.current = null;
                    setIsActive(false);
                    markCompleted(guide.key);
                },
            });
            setIsActive(true);
            driverRef.current.drive();
        },
        [component, completed, markCompleted]
    );

    // Muncul sendiri sekali per halaman, setelah halamannya tergambar.
    useEffect(() => {
        if (!hasGuide(component)) return;
        const t = setTimeout(() => start({ auto: true }), 700);
        return () => clearTimeout(t);
    }, [component, start]);

    // Kunjungan baru di halaman yang sama bisa mengganti keadaan layar
    // (shift kasir baru dibuka): panduan yang tidak berlaku lagi ditutup,
    // dan panduan keadaan baru muncul lewat efek di atas.
    useEffect(() => {
        if (!driverRef.current) return;
        if (pickGuide(component)?.key !== keyRef.current) {
            driverRef.current.destroy();
        }
    }, [page, component]);

    // Berpindah halaman menutup panduan halaman sebelumnya.
    useEffect(() => {
        return () => {
            driverRef.current?.destroy();
            driverRef.current = null;
        };
    }, [component]);

    const startManually = useCallback(() => start({ auto: false }), [start]);

    return { start: startManually, isActive, available: hasGuide(component) };
}
