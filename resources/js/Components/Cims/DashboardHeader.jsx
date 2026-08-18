import { useEffect, useState } from "react";
import { Link } from "@inertiajs/react";
import { IconAlerts, IconClock, IconExport, IconMenu, IconSearch } from "./icons";

/**
 * Jam berjalan diisolasi dalam komponennya sendiri supaya interval 1 detik
 * hanya me-render ulang indikator waktu, bukan seluruh dashboard.
 */
function LiveClock() {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const timer = setInterval(() => setNow(new Date()), 1000);
        return () => clearInterval(timer);
    }, []);

    return (
        <div className="hidden items-center gap-2 rounded-full bg-slate-100 px-3.5 py-2 lg:flex">
            <IconClock className="h-4 w-4 text-slate-400" />
            <span className="text-xs font-medium text-slate-500">
                {now.toLocaleDateString("id-ID", { weekday: "short", day: "numeric", month: "short" })}
            </span>
            {/* aria-live off: jam tidak perlu diumumkan setiap detik oleh screen reader */}
            <span className="text-xs font-semibold tabular-nums text-slate-900" aria-live="off">
                {now.toLocaleTimeString("id-ID", { hour: "2-digit", minute: "2-digit", second: "2-digit" })}
            </span>
        </div>
    );
}

const ICON_BUTTON =
    "relative flex h-11 w-11 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2";

/**
 * Top header (§5C): search bar global di kiri; indikator waktu, tombol aksi,
 * notifikasi, dan profil admin di kanan.
 */
export default function DashboardHeader({ user, unreadAlerts = 0, onOpenSidebar, onExport }) {
    return (
        <header className="sticky top-0 z-20 flex items-center justify-between gap-3 border-b border-slate-100 bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <button type="button" onClick={onOpenSidebar} className={`${ICON_BUTTON} lg:hidden`}>
                    <IconMenu className="h-5 w-5" />
                    <span className="sr-only">Buka menu navigasi</span>
                </button>

                <form className="min-w-0 flex-1 sm:max-w-md" role="search" onSubmit={(e) => e.preventDefault()}>
                    <label htmlFor="cims-global-search" className="sr-only">
                        Cari perangkat, IP, atau lokasi
                    </label>
                    <div className="relative">
                        <IconSearch className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            id="cims-global-search"
                            type="search"
                            placeholder="Cari perangkat, IP, atau lokasi…"
                            className="w-full rounded-full border-0 bg-slate-100 py-2.5 pl-11 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-blue-600"
                        />
                    </div>
                </form>
            </div>

            <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                <LiveClock />

                <button
                    type="button"
                    onClick={onExport}
                    className="hidden items-center gap-2 rounded-full bg-slate-900 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 sm:inline-flex"
                >
                    <IconExport className="h-4 w-4" />
                    Export Report
                </button>

                <button type="button" className={ICON_BUTTON}>
                    <IconAlerts className="h-5 w-5" />
                    {unreadAlerts > 0 && (
                        <span className="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white" />
                    )}
                    <span className="sr-only">
                        Notifikasi{unreadAlerts > 0 ? `, ${unreadAlerts} alert belum dibaca` : ", tidak ada yang baru"}
                    </span>
                </button>

                <Link
                    href={route("profile.edit")}
                    className="flex items-center gap-2.5 rounded-full p-1 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                >
                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-sm font-semibold text-white">
                        {user?.name?.charAt(0).toUpperCase() ?? "A"}
                    </span>
                    <span className="hidden pr-2 text-left xl:block">
                        <span className="block text-xs font-semibold leading-tight text-slate-900">{user?.name}</span>
                        <span className="block text-[11px] leading-tight text-slate-500">
                            {user?.roles?.[0] ?? "Network Admin"}
                        </span>
                    </span>
                </Link>
            </div>
        </header>
    );
}
