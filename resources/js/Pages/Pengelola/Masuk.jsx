import { Head, useForm } from "@inertiajs/react";
import { IconLoader2, IconShieldLock } from "@tabler/icons-react";
import AuthBotGuardFields from "@/Components/AuthBotGuardFields";
import { BRAND } from "@/Utils/brand";

/** Pintu pengelola layanan Aishii POS — akun pusat, bukan akun toko. */
export default function Masuk({ botGuard }) {
    const honeypotField = botGuard?.honeypot_field || "company_website";
    const tokenField = botGuard?.token_field || "bot_guard_token";
    const { data, setData, post, processing, errors } = useForm({
        email: "",
        password: "",
        [honeypotField]: "",
        [tokenField]: botGuard?.token || "",
    });

    const isian =
        "h-12 w-full rounded-xl border-2 border-slate-200 bg-white px-4 text-slate-900 focus:border-primary-500 focus:ring-4 focus:ring-primary-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-white";

    return (
        <>
            <Head title="Pengelola" />
            <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4 py-10 dark:bg-slate-950">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        post("/pengelola/masuk");
                    }}
                    className="w-full max-w-sm space-y-4 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                >
                    <div className="flex items-center gap-3">
                        <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-primary-100 text-primary-700 dark:bg-primary-950 dark:text-primary-300">
                            <IconShieldLock size={22} />
                        </span>
                        <div>
                            <h1 className="text-lg font-bold text-slate-900 dark:text-white">Pengelola {BRAND.name}</h1>
                            <p className="text-xs text-slate-500">Bukan untuk pemilik toko.</p>
                        </div>
                    </div>
                    <AuthBotGuardFields botGuard={botGuard} data={data} setData={setData} />
                    {errors.human && <p className="text-sm text-danger-500">{errors.human}</p>}
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Surel</label>
                        <input type="email" name="email" value={data.email} onChange={(e) => setData("email", e.target.value)} className={isian} autoComplete="username" />
                        {errors.email && <p className="mt-1 text-sm text-danger-500">{errors.email}</p>}
                    </div>
                    <div>
                        <label className="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-300">Kata sandi</label>
                        <input type="password" name="password" value={data.password} onChange={(e) => setData("password", e.target.value)} className={isian} autoComplete="current-password" />
                    </div>
                    <button
                        type="submit"
                        disabled={processing}
                        className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-primary-600 font-semibold text-white hover:bg-primary-700 disabled:opacity-60"
                    >
                        {processing && <IconLoader2 size={18} className="animate-spin" />}
                        Masuk
                    </button>
                </form>
            </div>
        </>
    );
}
