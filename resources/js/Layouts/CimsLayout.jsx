import { useEffect, useState } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import DashboardHeader from "@/Components/Cims/DashboardHeader";
import { ConfirmationDialog } from "@/Components/ConfirmationModal";
import {
    IconAlerts,
    IconChevronDown,
    IconClose,
    IconCloud,
    IconDashboard,
    IconInventory,
    IconLogout,
    IconMaintenance,
    IconMaster,
    IconMonitoring,
    IconReports,
    IconRouter,
    IconSpeedtest,
    IconTopology,
    IconUsers,
} from "@/Components/Cims/icons";

/**
 * Navigasi CIMS (§5B) — dikelompokkan seperti sidebar lama supaya tidak ada menu
 * yang hilang saat pindah ke tema terang. `match` dipakai agar sub-route ikut
 * menandai item aktif.
 */
const NAV_SECTIONS = [
    {
        title: "Utama",
        items: [{ name: "Dashboard", icon: IconDashboard, route: "dashboard" }],
    },
    {
        title: "Inventaris & Topologi",
        items: [
            { name: "Device Inventory", icon: IconInventory, route: "devices.index", match: "devices.*" },
            { name: "Topology Map", icon: IconTopology, route: "topology.index", match: "topology.*" },
        ],
    },
    {
        title: "Integrasi Network & API",
        items: [
            {
                name: "MikroTik Live API",
                icon: IconRouter,
                route: "mikrotik.index",
                match: "mikrotik.*",
                badge: "Live",
                badgeClass: "bg-emerald-50 text-emerald-700",
            },
            {
                name: "Ruijie Cloud",
                icon: IconCloud,
                route: "ruijie.index",
                match: "ruijie.*",
                badge: "Cloud",
                badgeClass: "bg-blue-50 text-blue-700",
            },
            { name: "Live Monitoring", icon: IconMonitoring, route: "monitoring.index", match: "monitoring.*" },
        ],
    },
    {
        title: "Operasional & Laporan",
        items: [
            { name: "Security Alerts", icon: IconAlerts, route: "alerts.index", match: "alerts.*" },
            { name: "Maintenance", icon: IconMaintenance, route: "maintenance.index", match: "maintenance.*" },
            {
                name: "Speedtest Bulanan",
                icon: IconSpeedtest,
                route: "speedtest-reports.index",
                match: "speedtest-reports.*",
            },
            { name: "Reports & Export", icon: IconReports, route: "reports.index", match: "reports.*" },
        ],
    },
];

/** Submenu Master Data — semua punya rute & halaman sendiri di routes/web.php. */
const MASTER_ITEMS = [
    { name: "Buildings (Gedung)", route: "buildings.index" },
    // Detail lantai (floors.show) tetap menyalakan menu Floors.
    { name: "Floors (Lantai)", route: "floors.index", match: "floors.*" },
    { name: "Rooms (Ruangan)", route: "rooms.index" },
    { name: "Racks", route: "racks.index" },
    { name: "Vendors & Brand", route: "vendors.index" },
    { name: "Device Categories", route: "device-categories.index" },
];

const ITEM_BASE =
    "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600";
const ITEM_ACTIVE = "bg-blue-600 text-white font-semibold shadow-md shadow-blue-500/20";
const ITEM_IDLE = "font-medium text-slate-600 hover:bg-blue-600 hover:text-white hover:shadow-md hover:shadow-blue-500/20";

const isCurrent = (name) => {
    // Ziggy melempar bila nama rute tidak terdaftar (mis. modul dimatikan),
    // jadi pengecekan aktif dibuat aman.
    try {
        return route().current(name);
    } catch {
        return false;
    }
};

/** Satu item menu (dipakai baik oleh menu utama maupun submenu Master Data). */
function NavLink({ item, onNavigate }) {
    const active = isCurrent(item.match ?? item.route);
    const Icon = item.icon;

    return (
        <li>
            <Link
                href={route(item.route)}
                aria-current={active ? "page" : undefined}
                onClick={onNavigate}
                className={`group ${ITEM_BASE} justify-between ${active ? ITEM_ACTIVE : ITEM_IDLE}`}
            >
                <span className="flex min-w-0 items-center gap-3">
                    {Icon && (
                        <Icon
                            className={`h-5 w-5 shrink-0 transition-colors ${
                                active ? "text-white" : "text-slate-400 group-hover:text-white"
                            }`}
                        />
                    )}
                    <span className="truncate">{item.name}</span>
                </span>
                {item.badge && (
                    <span
                        className={`shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-bold transition-colors ${
                            active
                                ? "bg-white/20 text-white"
                                : `${item.badgeClass ?? "bg-slate-100 text-slate-600"} group-hover:bg-white/20 group-hover:text-white`
                        }`}
                    >
                        {item.badge}
                    </span>
                )}
            </Link>
        </li>
    );
}

/**
 * Shell dashboard CIMS tema terang (§5): sidebar kiri + top header + area konten
 * yang bisa di-scroll. Di bawah `lg` sidebar berubah menjadi drawer.
 *
 * `header` disediakan agar halaman lama yang sebelumnya memakai
 * AuthenticatedLayout bisa tetap mengirim judul halaman tanpa diubah strukturnya.
 */
export default function CimsLayout({ header, unreadAlerts = 0, onExport, children }) {
    const user = usePage().props.auth?.user;
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [confirmLogout, setConfirmLogout] = useState(false);
    const masterActive = MASTER_ITEMS.some((item) => isCurrent(item.match ?? item.route));
    const [masterOpen, setMasterOpen] = useState(masterActive);
    const canManageUsers = user?.roles?.includes("Super Admin") || user?.permissions?.includes("manage users");

    useEffect(() => {
        const onKeyDown = (event) => event.key === "Escape" && setDrawerOpen(false);
        window.addEventListener("keydown", onKeyDown);
        return () => window.removeEventListener("keydown", onKeyDown);
    }, []);

    const closeDrawer = () => setDrawerOpen(false);

    return (
        <div className="flex h-screen overflow-hidden bg-slate-50 font-inter text-slate-900">
            {drawerOpen && (
                <div
                    className="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
                    onClick={closeDrawer}
                    aria-hidden="true"
                />
            )}

            <aside
                className={`fixed inset-y-0 left-0 z-40 flex w-64 shrink-0 flex-col border-r border-slate-100 bg-white transition-transform duration-300 motion-reduce:transition-none lg:static lg:translate-x-0 ${
                    drawerOpen ? "translate-x-0" : "-translate-x-full"
                }`}
            >
                <div className="flex h-16 shrink-0 items-center justify-between px-5">
                    <Link
                        href={route("dashboard")}
                        className="flex min-w-0 items-center gap-2.5 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                    >
                        <span
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-sm font-bold text-white"
                            aria-hidden="true"
                        >
                            C
                        </span>
                        <span className="min-w-0">
                            <span className="block text-base font-bold leading-tight tracking-tight text-slate-900">
                                CIMS UBG
                            </span>
                            <span className="block truncate text-[11px] font-medium text-slate-400">
                                Network Inventory
                            </span>
                        </span>
                    </Link>
                    <button
                        type="button"
                        onClick={closeDrawer}
                        className="flex h-9 w-9 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 lg:hidden"
                    >
                        <IconClose className="h-5 w-5" />
                        <span className="sr-only">Tutup menu navigasi</span>
                    </button>
                </div>

                <nav aria-label="Navigasi utama" className="flex-1 space-y-5 overflow-y-auto px-3 py-4">
                    {NAV_SECTIONS.map((section) => (
                        <div key={section.title}>
                            <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {section.title}
                            </p>
                            <ul className="space-y-1">
                                {section.items.map((item) => (
                                    <NavLink key={item.name} item={item} onNavigate={closeDrawer} />
                                ))}
                            </ul>
                        </div>
                    ))}

                    <div className="border-t border-slate-100 pt-4">
                        <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            Manajemen Sistem
                        </p>
                        <ul className="space-y-1">
                            <li>
                                <button
                                    type="button"
                                    onClick={() => setMasterOpen((open) => !open)}
                                    aria-expanded={masterOpen}
                                    className={`group ${ITEM_BASE} w-full justify-between ${masterActive ? ITEM_ACTIVE : ITEM_IDLE}`}
                                >
                                    <span className="flex items-center gap-3">
                                        <IconMaster
                                            className={`h-5 w-5 shrink-0 transition-colors ${
                                                masterActive ? "text-white" : "text-slate-400 group-hover:text-white"
                                            }`}
                                        />
                                        Master Data
                                    </span>
                                    <IconChevronDown
                                        className={`h-4 w-4 shrink-0 transition-transform motion-reduce:transition-none ${
                                            masterOpen ? "rotate-180" : ""
                                        } ${masterActive ? "text-white" : "text-slate-400 group-hover:text-white"}`}
                                    />
                                </button>

                                {masterOpen && (
                                    <ul className="mt-1 space-y-1 border-l-2 border-slate-100 pl-3.5">
                                        {MASTER_ITEMS.map((item) => {
                                            const active = isCurrent(item.match ?? item.route);
                                            return (
                                                <li key={item.route}>
                                                    <Link
                                                        href={route(item.route)}
                                                        aria-current={active ? "page" : undefined}
                                                        onClick={closeDrawer}
                                                        className={`block rounded-lg px-3 py-2 text-[13px] transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 ${
                                                            active
                                                                ? "bg-blue-600 font-semibold text-white shadow-sm shadow-blue-500/20"
                                                                : "font-medium text-slate-600 hover:bg-blue-600 hover:text-white hover:shadow-sm hover:shadow-blue-500/20"
                                                        }`}
                                                    >
                                                        {item.name}
                                                    </Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                )}
                            </li>

                            {canManageUsers && (
                                <NavLink
                                    item={{
                                        name: "User Management",
                                        icon: IconUsers,
                                        route: "users.index",
                                        match: "users.*",
                                    }}
                                    onNavigate={closeDrawer}
                                />
                            )}
                        </ul>
                    </div>
                </nav>

                <div className="shrink-0 border-t border-slate-100 p-3">
                    <div className="flex items-center gap-3 rounded-lg px-2 py-2">
                        <span
                            className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-sm font-bold text-blue-700"
                            aria-hidden="true"
                        >
                            {user?.name?.charAt(0).toUpperCase() ?? "A"}
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold text-slate-900">{user?.name}</span>
                            <span className="block truncate text-[11px] font-medium text-slate-500">
                                {user?.roles?.[0] ?? "Member"}
                            </span>
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setConfirmLogout(true)}
                        className="group mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 transition-all duration-150 hover:bg-blue-600 hover:text-white hover:shadow-md hover:shadow-blue-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600"
                    >
                        <IconLogout className="h-5 w-5 shrink-0 text-slate-400 transition-colors group-hover:text-white" />
                        Keluar
                    </button>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col">
                <DashboardHeader
                    user={user}
                    unreadAlerts={unreadAlerts}
                    onOpenSidebar={() => setDrawerOpen(true)}
                    onExport={onExport}
                />
                <main className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                    {header && <div className="mb-6">{header}</div>}
                    {children}
                </main>
            </div>

            {confirmLogout && (
                <ConfirmationDialog
                    config={{
                        title: "Keluar dari CIMS",
                        message: "Apakah Anda yakin ingin keluar dari sistem?",
                        confirmLabel: "Keluar",
                        cancelLabel: "Batal",
                        variant: "warning",
                        onConfirm: () => router.post(route("logout")),
                        onCancel: () => setConfirmLogout(false),
                    }}
                />
            )}
        </div>
    );
}
