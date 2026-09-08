import { Link, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
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
        <div className="hidden items-center gap-2 rounded-lg bg-slate-50 px-3 py-1.5 lg:flex">
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
    "relative flex h-10 w-10 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2";

/**
 * Top header (§5C): search bar global di kiri; indikator waktu, tombol aksi,
 * notifikasi, dan profil admin di kanan.
 */
export default function DashboardHeader({ user, unreadAlerts = 0, onOpenSidebar, onExport }) {
    /**
     * Search global menyerahkan kata kunci ke filter `search` inventaris perangkat
     * yang sudah ada (DeviceRepository::paginate). Tidak ada endpoint pencarian
     * lintas modul di aplikasi ini, jadi tujuannya sengaja satu halaman yang
     * memang bisa menjawab: Device Inventory.
     *
     * Kolom yang benar-benar dicari repository itu adalah name, hostname,
     * ip_address, mac_address, serial_number, dan model — bukan lokasi. Label dan
     * placeholder di bawah menyebut tepat itu supaya kontrol ini tidak
     * menjanjikan pencarian yang tidak dilakukan.
     */
    const submitSearch = (event) => {
        event.preventDefault();

        const keyword = new FormData(event.currentTarget).get("search")?.toString().trim();

        if (!keyword) return;

        router.get(route("devices.index"), { search: keyword }, { preserveState: false });
    };

    return (
        <header className="sticky top-0 z-20 flex items-center justify-between gap-3 border-b border-slate-100 bg-white/90 px-4 py-3 backdrop-blur sm:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <button type="button" onClick={onOpenSidebar} className={`${ICON_BUTTON} lg:hidden`}>
                    <IconMenu className="h-5 w-5" />
                    <span className="sr-only">Buka menu navigasi</span>
                </button>

                <form className="min-w-0 flex-1 sm:max-w-md" role="search" onSubmit={submitSearch}>
                    <label htmlFor="cims-global-search" className="sr-only">
                        Cari perangkat berdasarkan nama, hostname, IP, MAC, serial, atau model
                    </label>
                    <div className="relative">
                        <IconSearch className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input
                            id="cims-global-search"
                            name="search"
                            type="search"
                            placeholder="Cari perangkat, IP, atau serial…"
                            className="w-full rounded-lg border border-slate-200 bg-white py-2 pl-11 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:border-blue-600 focus:ring-2 focus:ring-blue-600"
                        />
                    </div>
                </form>
            </div>

            <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                <LiveClock />

                <button
                    type="button"
                    onClick={onExport}
                    className="hidden items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 sm:inline-flex"
                >
                    <IconExport className="h-4 w-4" />
                    Unduh laporan
                </button>

                {/*
                  * Bel notifikasi menuju halaman Security Alerts yang sudah ada.
                  * Sengaja `Link`, bukan dropdown: tidak ada endpoint ringkasan
                  * notifikasi, jadi satu-satunya tujuan yang benar-benar bisa
                  * menampilkan isinya adalah halaman alert itu sendiri.
                  *
                  * Tanpa prefetch — halaman alert menembak pemindaian saat dirender
                  * (item `live: true` di AppShell), dan itu terlalu mahal untuk
                  * dipicu hanya karena pointer melintas.
                  */}
                <Link href={route("alerts.index")} className={ICON_BUTTON}>
                    <IconAlerts className="h-5 w-5" />
                    {unreadAlerts > 0 && (
                        <span className="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-red-500 ring-2 ring-white" />
                    )}
                    <span className="sr-only">
                        Notifikasi{unreadAlerts > 0 ? `, ${unreadAlerts} alert belum dibaca` : ", tidak ada yang baru"}
                    </span>
                </Link>

                <Link
                    href={route("profile.edit")}
                    className="flex items-center gap-2.5 rounded-lg p-1 transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
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
