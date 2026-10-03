import React, { useEffect, useState } from "react";
import { usePage } from "@inertiajs/react";
import {
    IconMenu2,
    IconMoon,
    IconSun,
    IconSearch,
    IconQuestionMark,
} from "@tabler/icons-react";
import AuthDropdown from "@/Components/Dashboard/AuthDropdown";
import LanguageSwitcher from "@/Components/Dashboard/LanguageSwitcher";
import OutletSwitcher from "@/Components/Dashboard/OutletSwitcher";
import Menu from "@/Utils/Menu";
import Notification from "@/Components/Dashboard/Notification";
import { useTour } from "@/Hooks/useTour";
import i18n from "@/i18n";

export default function Navbar({ toggleSidebar, themeSwitcher, darkMode }) {
    const { auth, storeProfile } = usePage().props;
    const { start: startTour, isActive: tourActive } = useTour("dashboard");
    const menuNavigation = Menu();

    const storeName = storeProfile?.name || "KASIR";
    const storeInitial = storeName?.charAt(0)?.toUpperCase() || "K";

    // Get current page title
    const links = menuNavigation.flatMap((item) => item.details);
    const sublinks = links
        .filter((item) => item.hasOwnProperty("subdetails"))
        .flatMap((item) => item.subdetails);

    const getCurrentTitle = () => {
        for (const link of links) {
            if (link.hasOwnProperty("subdetails")) {
                const activeSublink = sublinks.find((s) => s.active);
                if (activeSublink) return activeSublink.title;
            } else if (link.active) {
                return link.title;
            }
        }
        return "Dashboard";
    };

    const [isMobile, setIsMobile] = useState(false);

    useEffect(() => {
        const handleResize = () => setIsMobile(window.innerWidth <= 768);
        window.addEventListener("resize", handleResize);
        handleResize();
        return () => window.removeEventListener("resize", handleResize);
    }, []);

    return (
        <header
            className="sticky top-0 z-30 h-16 flex items-center gap-2 px-3 md:px-6
            bg-white dark:bg-slate-900
            border-b border-slate-200 dark:border-slate-800
            transition-colors duration-200"
        >
            {/* Sidebar Toggle */}
            <button
                onClick={toggleSidebar}
                className="flex shrink-0 p-2 rounded-lg text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                title={i18n.t("account.toggle_menu")}
                aria-label={i18n.t("account.toggle_menu")}
            >
                <IconMenu2 size={20} strokeWidth={1.5} />
            </button>

            {/*
                Nama toko di ponsel SATU BARIS dan boleh terpotong. Ia dulu
                dibiarkan membungkus dan tidak boleh menyusut, sehingga di 390px
                ia mendorong empat tombol (panduan, bahasa, mode gelap,
                notifikasi) keluar layar — terpotong, tidak bisa diketuk, di
                SETIAP halaman dashboard (diukur: 112 dari 136 halaman×lebar).
            */}
            <div className="md:hidden flex min-w-0 flex-1 items-center gap-2">
                <div className="w-7 h-7 shrink-0 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex items-center justify-center max-[359px]:hidden">
                    <span className="text-white font-bold text-xs">{storeInitial}</span>
                </div>
                <span className="truncate text-base font-bold text-slate-800 dark:text-white">
                    {storeName}
                </span>
            </div>

            {/* Current Page Title */}
            <div className="hidden md:flex min-w-0 flex-1 items-center">
                <div className="w-px h-6 bg-slate-200 dark:bg-slate-700 mr-4" />
                <h1 className="truncate text-base font-semibold text-slate-800 dark:text-slate-200">
                    {getCurrentTitle()}
                </h1>
            </div>

            {/* Right Section */}
            <div className="flex shrink-0 items-center gap-1 md:gap-2">
                {/*
                    Yang jarang disentuh MENGALAH di ponsel (pola bilah atas
                    Aishii): pemilih outlet pindah ke laci menu, panduan dan
                    bahasa ke menu akun. Mode gelap TETAP di sini — ia disentuh
                    justru saat cahaya berubah, yaitu di toko, di ponsel.
                */}
                <div className="hidden md:block">
                    <OutletSwitcher outlet={auth?.currentOutlet} outlets={auth?.outlets} locked={auth?.outletLocked} />
                </div>
                {/* Tour Guide */}
                <button
                    onClick={startTour}
                    disabled={tourActive}
                    className="hidden md:flex p-2.5 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors disabled:opacity-50"
                    title={i18n.t("tour.button")}
                >
                    <IconQuestionMark size={20} strokeWidth={1.5} />
                </button>

                {/* Language Switcher */}
                <div className="hidden md:block">
                    <LanguageSwitcher />
                </div>

                {/* Theme Toggle */}
                <button
                    onClick={themeSwitcher}
                    className="p-2.5 rounded-xl text-slate-500 hover:text-slate-700 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:bg-slate-800 transition-colors"
                    title={i18n.t(darkMode ? "account.theme_light" : "account.theme_dark")}
                    aria-label={i18n.t(darkMode ? "account.theme_light" : "account.theme_dark")}
                >
                    {darkMode ? (
                        <IconSun
                            size={20}
                            strokeWidth={1.5}
                            className="text-amber-500"
                        />
                    ) : (
                        <IconMoon size={20} strokeWidth={1.5} />
                    )}
                </button>

                {/* Notifications */}
                <Notification />

                {/* Divider */}
                <div className="hidden md:block w-px h-8 bg-slate-200 dark:bg-slate-700 mx-1" />

                {/* User Dropdown */}
                <AuthDropdown
                    auth={auth}
                    isMobile={isMobile}
                    onStartTour={startTour}
                />
            </div>
        </header>
    );
}
