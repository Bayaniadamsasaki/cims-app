import ApplicationLogo from "@/Components/ApplicationLogo";
import Dropdown from "@/Components/Dropdown";
import { Link, usePage, router } from "@inertiajs/react";
import { ToastProvider } from "@/Components/Toast";
import {
    ConfirmationProvider,
    ConfirmationDialog,
} from "@/Components/ConfirmationModal";
import { useState } from "react";

export default function AuthenticatedLayout({ header, children }) {
    const user = usePage().props.auth.user;
    const [showingMobileMenu, setShowingMobileMenu] = useState(false);
    const [showingMasterDropdown, setShowingMasterDropdown] = useState(false);
    const [isLogoutModalOpen, setIsLogoutModalOpen] = useState(false);

    const hasRole = (role) => user?.roles?.includes(role);

    // Categorized sidebar navigation
    const navSections = [
        {
            title: "UTAMA",
            items: [
                {
                    name: "Dashboard",
                    route: "dashboard",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                    ),
                },
            ],
        },
        {
            title: "INVENTARIS & TOPOLOGI",
            items: [
                {
                    name: "Device Inventory",
                    route: "devices.index",
                    matchPrefix: "devices.",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-teal-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                        </svg>
                    ),
                },
                {
                    name: "Topology Map",
                    route: "topology.index",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
                        </svg>
                    ),
                },
            ],
        },
        {
            title: "INTEGRASI NETWORK & API",
            items: [
                {
                    name: "MikroTik Live API",
                    route: "mikrotik.index",
                    badge: "Live",
                    badgeColor: "bg-emerald-500/20 text-emerald-300 border-emerald-500/30",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                        </svg>
                    ),
                },
                {
                    name: "Ruijie Cloud Integration",
                    route: "ruijie.index",
                    badge: "Cloud",
                    badgeColor: "bg-cyan-500/20 text-cyan-300 border-cyan-500/30",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 15a4 4 0 004 4h9a5 5 0 001-9.9A7 7 0 103 15z" />
                        </svg>
                    ),
                },
                {
                    name: "Live Monitoring (SNMP)",
                    route: "monitoring.index",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 002 2h2a2 2 0 002-2z" />
                        </svg>
                    ),
                },
            ],
        },
        {
            title: "OPERASIONAL & LAPORAN",
            items: [
                {
                    name: "Security Alerts",
                    route: "alerts.index",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                    ),
                },
                {
                    name: "Maintenance Tickets",
                    route: "maintenance.index",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                    ),
                },
                {
                    name: "Laporan Speedtest Bulanan",
                    route: "speedtest-reports.index",
                    matchPrefix: "speedtest-reports.",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    ),
                },
                {
                    name: "Reports & Export",
                    route: "reports.index",
                    icon: (
                        <svg className="h-5 w-5 mr-3 shrink-0 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    ),
                },
            ],
        },
    ];

    const masterItems = [
        { name: "Buildings (Gedung)", route: "buildings.index" },
        { name: "Vendors & Brand", route: "vendors.index" },
        { name: "Device Categories", route: "device-categories.index" },
    ];

    return (
        <div className="min-h-screen bg-brand-bg text-white flex flex-col lg:flex-row">
            {/* Mobile Header Bar */}
            <div className="lg:hidden flex items-center justify-between bg-brand-bgSecondary border-b border-brand-border px-4 py-3 w-full z-30">
                <div className="flex items-center space-x-2">
                    <ApplicationLogo className="h-8 w-auto fill-current text-brand-primary" />
                    <span className="text-lg font-bold tracking-wider text-white">
                        CIMS
                    </span>
                </div>
                <button
                    onClick={() => setShowingMobileMenu(!showingMobileMenu)}
                    className="p-2 rounded-xl text-brand-textSecondary hover:text-white hover:bg-brand-cardElevated focus:outline-none"
                >
                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        {showingMobileMenu ? (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        ) : (
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                        )}
                    </svg>
                </button>
            </div>

            {/* Sidebar Left (Desktop & Mobile) */}
            <aside
                className={`fixed inset-y-0 left-0 bg-brand-bgSecondary border-r border-brand-border w-64 flex flex-col justify-between z-30 transition-transform duration-300 lg:translate-x-0 ${
                    showingMobileMenu ? "translate-x-0" : "-translate-x-full"
                }`}
            >
                {/* Fixed Brand Logo Header */}
                <div className="h-16 shrink-0 flex items-center px-6 border-b border-brand-border bg-brand-bg">
                    <Link href="/dashboard" className="flex items-center space-x-3">
                        <ApplicationLogo className="h-9 w-auto fill-current text-brand-primary" />
                    </Link>
                </div>

                {/* Scrollable Categorized Navigation Section */}
                <div className="flex-1 overflow-y-auto custom-scrollbar px-3 py-4 space-y-6">
                    {navSections.map((section, secIdx) => (
                        <div key={secIdx} className="space-y-1">
                            <div className="px-3 pb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                                {section.title}
                            </div>
                            {section.items.map((item, itemIdx) => {
                                const active =
                                    route().current(item.route) ||
                                    (item.matchPrefix && route().current(item.matchPrefix + "*"));
                                return (
                                    <Link
                                        key={itemIdx}
                                        href={route(item.route)}
                                        className={`flex items-center justify-between px-3 py-2.5 text-xs font-semibold rounded-xl transition duration-150 group ${
                                            active
                                                ? "bg-brand-primary text-slate-950 shadow-md shadow-brand-primary/20 font-bold"
                                                : "text-brand-textSecondary hover:text-white hover:bg-brand-primary/10"
                                        }`}
                                    >
                                        <div className="flex items-center min-w-0">
                                            {item.icon}
                                            <span className="truncate">{item.name}</span>
                                        </div>
                                        {item.badge && (
                                            <span className={`px-1.5 py-0.5 text-[9px] font-mono font-bold rounded-md border shrink-0 ${active ? "bg-slate-900/40 text-slate-950 border-slate-900/30" : item.badgeColor}`}>
                                                {item.badge}
                                            </span>
                                        )}
                                    </Link>
                                );
                            })}
                        </div>
                    ))}

                    {/* SYSTEM DATA CATEGORY */}
                    <div className="space-y-1 pt-2 border-t border-brand-border/60">
                        <div className="px-3 pb-1.5 text-[10px] font-extrabold uppercase tracking-wider text-slate-400">
                            MANAJEMEN SISTEM
                        </div>

                        {/* Master Data Dropdown */}
                        <div>
                            <button
                                onClick={() => setShowingMasterDropdown(!showingMasterDropdown)}
                                className={`flex items-center justify-between w-full px-3 py-2.5 text-xs font-semibold rounded-xl text-brand-textSecondary hover:text-white hover:bg-brand-primary/10 transition duration-150`}
                            >
                                <div className="flex items-center">
                                    <svg className="h-5 w-5 mr-3 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                    <span>Master Data</span>
                                </div>
                                <svg
                                    className={`h-4 w-4 transform transition-transform duration-150 ${showingMasterDropdown ? "rotate-180 text-brand-primary" : "text-slate-500"}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>

                            <div className={`mt-1 pl-7 space-y-1 overflow-hidden transition-all duration-200 ${showingMasterDropdown ? "max-h-96 opacity-100" : "max-h-0 opacity-0"}`}>
                                {masterItems.map((item, idx) => {
                                    const active = route().current(item.route);
                                    return (
                                        <Link
                                            key={idx}
                                            href={route(item.route)}
                                            className={`block px-3 py-2 text-xs font-medium rounded-lg transition duration-150 ${
                                                active
                                                    ? "text-brand-primary bg-brand-card font-bold"
                                                    : "text-brand-textMuted hover:text-white hover:bg-brand-primary/5"
                                            }`}
                                        >
                                            {item.name}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>

                        {/* User Management (Super Admin only) */}
                        {hasRole("Super Admin") && (
                            <Link
                                href={route("users.index")}
                                className={`flex items-center px-3 py-2.5 text-xs font-semibold rounded-xl transition duration-150 ${
                                    route().current("users.index") || route().current("users.*")
                                        ? "bg-brand-primary text-slate-950 shadow-md shadow-brand-primary/20 font-bold"
                                        : "text-brand-textSecondary hover:text-white hover:bg-brand-primary/10"
                                }`}
                            >
                                <svg className="h-5 w-5 mr-3 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                                <span>User Management</span>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Fixed Bottom User Profile Section */}
                <div className="shrink-0 p-4 border-t border-brand-border bg-brand-bg">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-3 overflow-hidden">
                            <div className="h-10 w-10 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary font-black shrink-0">
                                {user?.name?.charAt(0).toUpperCase()}
                            </div>
                            <div className="overflow-hidden">
                                <div className="text-sm font-bold text-white truncate">
                                    {user?.name}
                                </div>
                                <span className="inline-block text-[9px] bg-brand-primary/15 text-brand-primary border border-brand-primary/25 px-2 py-0.5 rounded-full font-black mt-0.5">
                                    {user?.roles?.[0] || "Member"}
                                </span>
                            </div>
                        </div>

                        <div className="relative">
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button className="p-2 rounded-lg text-brand-textSecondary hover:text-white hover:bg-brand-card transition">
                                        <svg
                                            className="h-5 w-5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth="2"
                                                d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"
                                            />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>

                                <Dropdown.Content placement="top">
                                    <Dropdown.Link href={route("profile.edit")}>
                                        Profile Settings
                                    </Dropdown.Link>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setIsLogoutModalOpen(true)
                                        }
                                        className="block w-full px-4 py-2.5 text-start text-sm leading-5 text-brand-textSecondary transition duration-150 ease-in-out hover:bg-brand-primary hover:text-slate-950 focus:bg-brand-primary focus:text-slate-950 focus:outline-none first:rounded-t-xl last:rounded-b-xl font-medium"
                                    >
                                        Log Out
                                    </button>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Main Content Area */}
            <div className="flex-1 lg:pl-64 flex flex-col min-h-screen">
                {header && (
                    <header className="bg-brand-bgSecondary/60 border-b border-brand-border shadow-lg backdrop-blur-md sticky top-0 z-10 py-6">
                        <div className="mx-auto max-w-7xl px-6 lg:px-8">
                            {header}
                        </div>
                    </header>
                )}

                <main className="flex-1">{children}</main>
            </div>
            {isLogoutModalOpen && (
                <ConfirmationDialog
                    config={{
                        title: "Log Out",
                        message: "Apakah Anda yakin ingin keluar dari sistem?",
                        confirmLabel: "Log Out",
                        cancelLabel: "Batal",
                        variant: "warning",
                        onConfirm: () => router.post(route("logout")),
                        onCancel: () => setIsLogoutModalOpen(false),
                    }}
                />
            )}
        </div>
    );
}
