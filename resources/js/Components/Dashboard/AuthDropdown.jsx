import React, { useState, useRef, useEffect } from "react";
import { Menu, Transition } from "@headlessui/react";
import { usePage, router, Link } from "@inertiajs/react";
import {
    IconCheck,
    IconLanguage,
    IconLogout,
    IconQuestionMark,
    IconRotate,
    IconUser,
} from "@tabler/icons-react";
import { useForm } from "@inertiajs/react";
import axios from "axios";
import MenuLink from "@/Utils/Menu";
import LinkItem from "./LinkItem";
import LinkItemDropdown from "./LinkItemDropdown";
import i18n from "@/i18n";
export default function AuthDropdown({ auth, isMobile, onStartTour }) {
    // define usefrom
    const { post } = useForm();
    // define url from usepage
    const { url } = usePage();

    // define state isToggle
    const [isToggle, setIsToggle] = useState(false);
    // define state isOpen
    const [isOpen, setIsOpen] = useState(false);
    // define ref dropdown
    const dropdownRef = useRef(null);

    // define method handleClickOutside
    const handleClickOutside = (event) => {
        if (
            dropdownRef.current &&
            !dropdownRef.current.contains(event.target)
        ) {
            setIsToggle(false);
        }
    };

    // get menu from utils
    const menuNavigation = MenuLink();

    // define useEffect
    useEffect(() => {
        // add event listener
        window.addEventListener("mousedown", handleClickOutside);

        // remove event listener
        return () => {
            window.removeEventListener("mousedown", handleClickOutside);
        };
    }, []);

    // define function logout
    const logout = async (e) => {
        e.preventDefault();

        post(route("logout"));
    };

    const resetTours = (e) => {
        e.preventDefault();

        // ponytail: plain axios — Inertia router rejects the JSON-only response
        axios
            .post(route("tours.reset"), {}, { headers: { Accept: "application/json" } })
            .then(() => router.reload({ only: ["auth"] }));
    };

    const avatarUrl = auth.user.avatar;
    const userInitial =
        auth.user.name?.charAt(0)?.toUpperCase() ??
        auth.user.email?.charAt(0)?.toUpperCase() ??
        "?";

    const { locale } = usePage().props;
    const currentLocale = locale?.current || "id";

    const switchLanguage = (code) => (e) => {
        e.preventDefault();
        if (code === currentLocale) return;
        router.post(
            route("language.switch"),
            { locale: code },
            { preserveScroll: true, onSuccess: () => window.location.reload() }
        );
    };

    const itemClass =
        "w-full px-3 py-2 text-sm flex items-center gap-2 text-gray-600 hover:text-gray-900 hover:bg-slate-50 dark:text-gray-400 dark:hover:text-gray-200 dark:hover:bg-slate-900";

    /*
     * SATU MENU UNTUK SEMUA LEBAR. Di ponsel komponen ini dulu hanya
     * menggambar avatar — tanpa menu — dan laci samping tidak memuat
     * "Keluar", jadi di ponsel TIDAK ADA jalan keluar dari akun sama sekali.
     * Di ponsel menunya juga menampung yang mengalah dari bilah atas:
     * panduan dan bahasa.
     */
    return (
        <Menu className="relative z-10" as="div">
            <Menu.Button
                className="flex items-center rounded-full"
                aria-label={i18n.t("account.menu")}
            >
                {avatarUrl ? (
                    <img
                        src={avatarUrl}
                        alt={auth.user.name}
                        className="w-9 h-9 md:w-10 md:h-10 rounded-full object-cover"
                    />
                ) : (
                    <div className="w-9 h-9 md:w-10 md:h-10 rounded-full bg-primary-100 text-primary-700 flex items-center justify-center font-semibold">
                        {userInitial}
                    </div>
                )}
            </Menu.Button>
            <Transition
                enter="transition duration-100 ease-out"
                enterFrom="transform scale-95 opacity-0"
                enterTo="transform scale-100 opacity-100"
                leave="transition duration-75 ease-out"
                leaveFrom="transform scale-100 opacity-100"
                leaveTo="transform scale-95 opacity-0"
            >
                <Menu.Items className="absolute rounded-xl w-60 max-w-[calc(100vw-1.5rem)] border mt-2 py-1 right-0 z-[100] bg-white shadow-lg dark:bg-gray-950 dark:border-gray-900">
                    <div className="px-3 py-2 border-b border-gray-100 dark:border-gray-900">
                        <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">
                            {auth.user.name}
                        </p>
                        <p className="truncate text-xs text-slate-500">
                            {auth.user.email}
                        </p>
                    </div>
                    <div className="py-1">
                        <Menu.Item>
                            <Link href={route("profile.edit")} className={itemClass}>
                                <IconUser strokeWidth={1.5} size={20} />
                                {i18n.t("account.profile")}
                            </Link>
                        </Menu.Item>
                        {isMobile && onStartTour && (
                            <Menu.Item>
                                <button onClick={onStartTour} className={itemClass}>
                                    <IconQuestionMark strokeWidth={1.5} size={20} />
                                    {i18n.t("tour.button")}
                                </button>
                            </Menu.Item>
                        )}
                        <Menu.Item>
                            <button onClick={resetTours} className={itemClass}>
                                <IconRotate strokeWidth={1.5} size={20} />
                                {i18n.t("tour.reset")}
                            </button>
                        </Menu.Item>
                    </div>
                    {isMobile && (
                        <div className="py-1 border-t border-gray-100 dark:border-gray-900">
                            {[
                                { code: "id", name: "Bahasa Indonesia" },
                                { code: "en", name: "English" },
                            ].map((lang) => (
                                <Menu.Item key={lang.code}>
                                    <button
                                        onClick={switchLanguage(lang.code)}
                                        className={itemClass}
                                        aria-current={currentLocale === lang.code}
                                    >
                                        <IconLanguage strokeWidth={1.5} size={20} />
                                        <span className="flex-1 text-left">{lang.name}</span>
                                        {currentLocale === lang.code && (
                                            <IconCheck size={16} className="text-primary-600" />
                                        )}
                                    </button>
                                </Menu.Item>
                            ))}
                        </div>
                    )}
                    <div className="py-1 border-t border-gray-100 dark:border-gray-900">
                        <Menu.Item>
                            <button
                                onClick={logout}
                                className={`${itemClass} hover:!text-danger-600`}
                            >
                                <IconLogout strokeWidth={1.5} size={20} />
                                {i18n.t("account.logout")}
                            </button>
                        </Menu.Item>
                    </div>
                </Menu.Items>
            </Transition>
        </Menu>
    );
}
