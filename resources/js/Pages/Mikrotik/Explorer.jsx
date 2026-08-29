import CimsLayout from "@/Layouts/CimsLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";
import { useEffect, useState } from "react";

export default function MikrotikExplorer({
    auth,
    routerConfig,
    availableRouters = [],
    selectedRouter = null,
    selectedHost,
    connection,
    systemMetrics: initialMetrics,
    ipAddresses: initialIps,
    routes: initialRoutes,
    users: initialUsers,
    packages: initialPackages,
    dns: initialDns,
}) {
    const [activeTab, setActiveTab] = useState("overview");
    const [metrics, setMetrics] = useState(initialMetrics);
    const [ipAddresses, setIpAddresses] = useState(initialIps || []);
    const [routes, setRoutes] = useState(initialRoutes || []);
    const [users, setUsers] = useState(initialUsers || []);
    const [packages, setPackages] = useState(initialPackages || []);
    const [dns, setDns] = useState(initialDns || {});

    // Semua data router dikirim sebagai deferred prop: halaman tampil lebih dulu,
    // hasil probe RouterOS (bisa puluhan detik) menyusul di request berikutnya.
    // Selama `connection` masih undefined, statusnya "belum diketahui" — bukan
    // gagal — jadi banner error tidak boleh ikut muncul.
    const isLiveDataPending = connection === undefined;

    useEffect(() => {
        if (initialMetrics !== undefined) setMetrics(initialMetrics);
        if (initialIps !== undefined) setIpAddresses(initialIps || []);
        if (initialRoutes !== undefined) setRoutes(initialRoutes || []);
        if (initialUsers !== undefined) setUsers(initialUsers || []);
        if (initialPackages !== undefined) setPackages(initialPackages || []);
        if (initialDns !== undefined) setDns(initialDns || {});
    }, [initialMetrics, initialIps, initialRoutes, initialUsers, initialPackages, initialDns]);

    // Dynamic loaded state for secondary tabs
    const [natRules, setNatRules] = useState([]);
    const [firewallFilter, setFirewallFilter] = useState([]);
    const [hotspotActive, setHotspotActive] = useState([]);
    const [neighbors, setNeighbors] = useState([]);
    const [logs, setLogs] = useState([]);
    const [loadingTab, setLoadingTab] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isRouterDropdownOpen, setIsRouterDropdownOpen] = useState(false);
    const [routerNeighborsByHost, setRouterNeighborsByHost] = useState({});
    const [loadingRouterNeighbors, setLoadingRouterNeighbors] = useState({});
    const [expandedRouterHosts, setExpandedRouterHosts] = useState({});

    const currentHost = selectedHost || routerConfig.host;
    const activeRouterObj = selectedRouter ||
        (availableRouters || []).find((r) => r.ip === currentHost) ||
        availableRouters[0] || { name: "Core Router", ip: currentHost };

    // Helper to get API URL with target host query
    const apiRoute = (routeName, extraParams = {}) => {
        const { host: targetHost, ...params } = extraParams;
        const base = route(routeName, params);
        const host = targetHost ?? currentHost;
        if (!host) return base;
        const separator = base.includes("?") ? "&" : "?";
        return `${base}${separator}host=${encodeURIComponent(host)}`;
    };

    const normalizeNeighborRouter = (neighbor, parentRouter, index) => ({
        id: `neighbor-${parentRouter?.ip || "root"}-${neighbor.address || neighbor.identity || index}`,
        name:
            neighbor.identity ||
            (neighbor.address
                ? `Neighbor (${neighbor.address})`
                : "Neighbor Router"),
        model: neighbor.board || neighbor.platform || "MikroTik MNDP",
        location: `Auto-Discovered (${neighbor.interface || "eth"})`,
        ip: neighbor.address || null,
        mac: neighbor.mac || null,
        sourceInterface: neighbor.interface || null,
        sourceHost: parentRouter?.ip || null,
        version: neighbor.version || null,
        isNeighbor: true,
    });

    const handleSelectRouter = (router) => {
        if (!router?.ip) return;

        setIsRouterDropdownOpen(false);
        window.location.href = route("mikrotik.index", { host: router.ip });
    };

    const loadRouterNeighbors = async (router) => {
        if (
            !router?.ip ||
            loadingRouterNeighbors[router.ip] ||
            routerNeighborsByHost[router.ip]
        ) {
            return;
        }

        setLoadingRouterNeighbors((prev) => ({ ...prev, [router.ip]: true }));

        try {
            const res = await axios.get(
                apiRoute("mikrotik.api.neighbors", { host: router.ip }),
            );
            const nextNeighbors = (res.data || [])
                .filter((neighbor) => neighbor.address)
                .map((neighbor, index) =>
                    normalizeNeighborRouter(neighbor, router, index),
                );

            setRouterNeighborsByHost((prev) => ({
                ...prev,
                [router.ip]: nextNeighbors,
            }));
        } catch (error) {
            console.error(`Failed loading neighbors for ${router.ip}`, error);
            setRouterNeighborsByHost((prev) => ({
                ...prev,
                [router.ip]: [],
            }));
        } finally {
            setLoadingRouterNeighbors((prev) => ({
                ...prev,
                [router.ip]: false,
            }));
        }
    };

    const toggleRouterNeighbors = async (router) => {
        if (!router?.ip) return;

        const nextExpanded = !expandedRouterHosts[router.ip];

        setExpandedRouterHosts((prev) => ({
            ...prev,
            [router.ip]: nextExpanded,
        }));

        if (nextExpanded) {
            await loadRouterNeighbors(router);
        }
    };

    const renderRouterNode = (router, depth = 0, ancestry = new Set()) => {
        if (!router?.ip || ancestry.has(router.ip)) {
            return null;
        }

        const nextAncestry = new Set(ancestry);
        nextAncestry.add(router.ip);

        const isSelected = router.ip === currentHost;
        const isExpanded = !!expandedRouterHosts[router.ip];
        const isLoading = !!loadingRouterNeighbors[router.ip];
        const childRouters = (routerNeighborsByHost[router.ip] || []).filter(
            (child) => child.ip && !nextAncestry.has(child.ip),
        );
        const isDiscovered =
            router.isNeighbor ||
            String(router.id || "").startsWith("discovered-");

        return (
            <div
                key={router.id || `${router.ip}-${depth}`}
                className={
                    depth > 0 ? "ml-4 pl-4 border-l border-emerald-500/15" : ""
                }
            >
                <div
                    className={`rounded-xl transition border ${
                        isSelected
                            ? "bg-blue-50 border-blue-200 text-slate-900"
                            : "bg-transparent border-transparent hover:bg-slate-50 hover:border-slate-200 text-slate-600 hover:text-slate-900"
                    }`}
                >
                    <div className="flex items-stretch justify-between gap-2 px-3 py-2.5">
                        <button
                            type="button"
                            onClick={() => handleSelectRouter(router)}
                            className="flex min-w-0 flex-1 items-center space-x-3 text-left"
                        >
                            <div
                                className={`h-8 w-8 rounded-lg flex items-center justify-center font-mono font-bold text-xs shrink-0 ${
                                    isSelected
                                        ? "bg-blue-600 text-white shadow-sm"
                                        : "bg-slate-100 border border-slate-200 text-blue-600 group-hover:border-blue-300"
                                }`}
                            >
                                μT
                            </div>

                            <div className="min-w-0 flex-1">
                                <div className="flex items-center space-x-1.5">
                                    <span
                                        className={`text-xs font-bold truncate ${isSelected ? "text-slate-900" : "text-slate-700"}`}
                                    >
                                        {router.name}
                                    </span>
                                    {isDiscovered && (
                                        <span className="text-[8px] px-1.5 py-0.5 rounded-full bg-cyan-50 text-cyan-700 border border-cyan-200 font-bold uppercase tracking-wider shrink-0">
                                            Neighbor
                                        </span>
                                    )}
                                </div>
                                <div className="text-[10px] text-slate-500 truncate font-mono">
                                    {router.model || "MikroTik"} •{" "}
                                    {router.location || "Data Center"}
                                </div>
                            </div>
                        </button>

                        <div className="flex items-center space-x-2 shrink-0 ml-2">
                            <span
                                className={`text-[11px] font-mono px-2 py-0.5 rounded border ${
                                    isSelected
                                        ? "bg-blue-100 text-blue-800 border-blue-200 font-bold"
                                        : "bg-slate-50 text-slate-600 border-slate-200"
                                }`}
                            >
                                {router.ip}
                            </span>

                            <button
                                type="button"
                                onClick={(event) => {
                                    event.stopPropagation();
                                    toggleRouterNeighbors(router);
                                }}
                                className={`flex h-8 w-8 items-center justify-center rounded-lg border transition ${
                                    isExpanded
                                        ? "border-blue-300 bg-blue-50 text-blue-700"
                                        : "border-slate-200 bg-slate-50 text-slate-500 hover:border-blue-300 hover:text-blue-700"
                                }`}
                                title="Lihat neighbors"
                            >
                                <svg
                                    className={`w-4 h-4 transition-transform duration-200 ${isExpanded ? "rotate-180" : ""}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M19 9l-7 7-7-7"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                {isExpanded && (
                    <div className="mt-2 space-y-2">
                        <div className="px-1 text-[10px] uppercase tracking-wider text-brand-textSecondary">
                            {isLoading
                                ? "Memuat neighbors..."
                                : `${childRouters.length} Neighbor`}
                        </div>

                        {isLoading ? (
                            <div className="px-3 py-2 text-xs text-brand-textSecondary">
                                Mengambil data neighbors untuk {router.name}...
                            </div>
                        ) : childRouters.length > 0 ? (
                            childRouters.map((childRouter) =>
                                renderRouterNode(
                                    childRouter,
                                    depth + 1,
                                    nextAncestry,
                                ),
                            )
                        ) : (
                            <div className="px-3 py-2 text-xs text-brand-textSecondary">
                                Tidak ada neighbors yang bisa dipilih.
                            </div>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // Auto-refresh metrics every 10 seconds if connected
    useEffect(() => {
        if (!connection?.success) return;

        const interval = setInterval(() => {
            fetchMetricsSilently();
        }, 10000);

        return () => clearInterval(interval);
    }, [connection, currentHost]);

    // Load data lazily when tab changes
    useEffect(() => {
        if (!connection?.success) return;

        if (activeTab === "firewall" && natRules.length === 0) {
            setLoadingTab(true);
            Promise.all([
                axios.get(apiRoute("mikrotik.api.nat-rules")),
                axios.get(apiRoute("mikrotik.api.firewall-filter")),
            ]).then(([natRes, fwRes]) => {
                setNatRules(natRes.data);
                setFirewallFilter(fwRes.data);
                setLoadingTab(false);
            });
        } else if (activeTab === "hotspot" && hotspotActive.length === 0) {
            setLoadingTab(true);
            axios.get(apiRoute("mikrotik.api.hotspot-active")).then((res) => {
                setHotspotActive(res.data);
                setLoadingTab(false);
            });
        } else if (activeTab === "neighbors" && neighbors.length === 0) {
            setLoadingTab(true);
            axios.get(apiRoute("mikrotik.api.neighbors")).then((res) => {
                setNeighbors(res.data);
                setLoadingTab(false);
            });
        } else if (activeTab === "logs" && logs.length === 0) {
            setLoadingTab(true);
            axios.get(apiRoute("mikrotik.api.logs")).then((res) => {
                setLogs(res.data);
                setLoadingTab(false);
            });
        }
    }, [activeTab, connection, currentHost]);

    const fetchMetricsSilently = async () => {
        try {
            const res = await axios.get(apiRoute("mikrotik.api.metrics"));
            setMetrics(res.data);
        } catch (e) {
            console.error("Failed refreshing metrics", e);
        }
    };

    const handleManualRefresh = async () => {
        setIsRefreshing(true);
        try {
            const res = await axios.get(apiRoute("mikrotik.api.metrics"));
            setMetrics(res.data);

            if (activeTab === "network") {
                const [ipRes, routeRes] = await Promise.all([
                    axios.get(apiRoute("mikrotik.api.ip-addresses")),
                    axios.get(apiRoute("mikrotik.api.routes")),
                ]);
                setIpAddresses(ipRes.data);
                setRoutes(routeRes.data);
            } else if (activeTab === "firewall") {
                const [natRes, fwRes] = await Promise.all([
                    axios.get(apiRoute("mikrotik.api.nat-rules")),
                    axios.get(apiRoute("mikrotik.api.firewall-filter")),
                ]);
                setNatRules(natRes.data);
                setFirewallFilter(fwRes.data);
            } else if (activeTab === "hotspot") {
                const res = await axios.get(
                    apiRoute("mikrotik.api.hotspot-active"),
                );
                setHotspotActive(res.data);
            } else if (activeTab === "neighbors") {
                const res = await axios.get(apiRoute("mikrotik.api.neighbors"));
                setNeighbors(res.data);
            } else if (activeTab === "logs") {
                const res = await axios.get(apiRoute("mikrotik.api.logs"));
                setLogs(res.data);
            }
        } catch (e) {
            console.error(e);
        } finally {
            setIsRefreshing(false);
        }
    };

    const formatBps = (bps) => {
        if (!bps || bps === 0) return "0 Bps";
        if (bps >= 1000000000) return (bps / 1000000000).toFixed(2) + " Gbps";
        if (bps >= 1000000) return (bps / 1000000).toFixed(2) + " Mbps";
        if (bps >= 1000) return (bps / 1000).toFixed(2) + " Kbps";
        return bps + " bps";
    };

    const formatUptime = (sec) => {
        if (!sec) return "-";
        const days = Math.floor(sec / 86400);
        const hours = Math.floor((sec % 86400) / 3600);
        const mins = Math.floor((sec % 3600) / 60);
        return `${days}d ${hours}h ${mins}m`;
    };

    return (
        <CimsLayout>
            <Head title="MikroTik Live API Explorer" />

            <div className="space-y-6">
                {/* Header Banner */}
                <div className="bg-brand-card border border-brand-border p-6 rounded-2xl">
                    <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-5">
                        {/* Left: Title & Status */}
                        <div className="flex items-center space-x-4">
                            <div className="h-12 w-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-700 font-mono font-bold text-xl shrink-0 shadow-lg shadow-emerald-500/5">
                                μT
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-bold text-slate-900 tracking-wide">
                                        MikroTik Live API Explorer
                                    </h1>
                                    {isLiveDataPending ? (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-700 border border-blue-500/30">
                                            <span className="h-2 w-2 rounded-full bg-blue-400 mr-2 animate-pulse"></span>
                                            Menghubungkan…
                                        </span>
                                    ) : connection?.success ? (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-700 border border-emerald-500/30">
                                            <span className="h-2 w-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>
                                            API Terhubung
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-500/10 text-red-700 border border-red-500/30">
                                            Offline / Kesalahan Koneksi
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-brand-textSecondary mt-1">
                                    Real-time monitoring & administration direct
                                    from RouterOS API (No Winbox required)
                                </p>
                            </div>
                        </div>

                        {/* Right: Router Switcher Card & Refresh Button */}
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                            {/* Custom Glassmorphism Router Selector */}
                            <div className="relative">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setIsRouterDropdownOpen(
                                            !isRouterDropdownOpen,
                                        )
                                    }
                                    className="flex items-center justify-between space-x-3 bg-brand-bg/90 hover:bg-brand-bgSecondary border border-brand-border/90 hover:border-emerald-500/50 px-4 py-2 rounded-xl shadow-inner min-w-[290px] sm:min-w-[340px] transition group text-left"
                                >
                                    <div className="flex items-center space-x-3 min-w-0">
                                        <div className="flex items-center justify-center h-8 w-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 text-sm shrink-0 group-hover:scale-105 transition-transform">
                                            🎛️
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <span className="block text-[10px] font-bold uppercase tracking-wider text-brand-textSecondary">
                                                Perangkat MikroTik Aktif
                                            </span>
                                            <div className="flex items-center space-x-2 truncate">
                                                <span className="text-xs font-bold text-slate-900 truncate">
                                                    {activeRouterObj?.name}
                                                </span>
                                                <span className="text-[10px] font-mono font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 px-1.5 py-0.5 rounded shrink-0">
                                                    {activeRouterObj?.ip}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <svg
                                        className={`w-4 h-4 text-brand-textSecondary transition-transform duration-200 shrink-0 ${isRouterDropdownOpen ? "rotate-180 text-emerald-700" : ""}`}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            strokeWidth="2"
                                            d="M19 9l-7 7-7-7"
                                        />
                                    </svg>
                                </button>

                                {/* Custom Dropdown Glass Panel */}
                                {isRouterDropdownOpen && (
                                    <>
                                        {/* Click Outside Overlay */}
                                        <div
                                            className="fixed inset-0 z-40"
                                            onClick={() =>
                                                setIsRouterDropdownOpen(false)
                                            }
                                        />

                                        <div className="absolute right-0 top-full mt-2 w-full sm:w-[400px] z-50 bg-white border border-slate-200 rounded-2xl shadow-xl p-2 space-y-1 animate-in fade-in duration-150">
                                            <div className="px-3 py-2 border-b border-slate-200 flex items-center justify-between">
                                                <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500 flex items-center space-x-1.5">
                                                    <span>📡</span>
                                                    <span>
                                                        Pilih Target Monitoring
                                                    </span>
                                                </span>
                                                <span className="text-[10px] font-mono font-bold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                                    {
                                                        (availableRouters || [])
                                                            .length
                                                    }{" "}
                                                    Perangkat Terdaftar
                                                </span>
                                            </div>

                                            <div className="max-h-[340px] overflow-y-auto space-y-2 pr-1 custom-scrollbar">
                                                {(availableRouters || []).map(
                                                    (router) =>
                                                        renderRouterNode(
                                                            router,
                                                        ),
                                                )}
                                            </div>
                                        </div>
                                    </>
                                )}
                            </div>

                            <button
                                onClick={handleManualRefresh}
                                disabled={isRefreshing}
                                className="flex items-center justify-center space-x-2 px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-sm font-semibold transition duration-200 disabled:opacity-50 shadow-md shadow-emerald-900/20 shrink-0"
                            >
                                <svg
                                    className={`w-4 h-4 ${isRefreshing ? "animate-spin" : ""}`}
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                    />
                                </svg>
                                <span>
                                    {isRefreshing
                                        ? "Menyegarkan..."
                                        : "Segarkan Data"}
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Probe RouterOS masih jalan — tampilkan status, bukan error. */}
                {isLiveDataPending && (
                    <div className="bg-blue-50 border border-blue-200 p-5 rounded-2xl flex items-center space-x-3 shadow-sm">
                        <svg className="w-6 h-6 shrink-0 animate-spin text-blue-600" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                        </svg>
                        <div>
                            <div className="font-semibold text-blue-900 text-base">
                                Menghubungi RouterOS di {currentHost}…
                            </div>
                            <p className="text-sm text-blue-700 mt-0.5">
                                Identity, metrik sistem, IP, route, dan DNS sedang diambil. Isi tab akan terisi otomatis.
                            </p>
                        </div>
                    </div>
                )}

                {/* Connection Error Alert with Diagnostics */}
                {!isLiveDataPending && !connection?.success && (
                    <div className="bg-red-50 border border-red-200 p-6 rounded-2xl space-y-5 shadow-sm">
                        <div className="flex items-start space-x-3">
                            <svg
                                className="w-6 h-6 shrink-0 mt-0.5 text-red-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                            >
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
                            <div className="flex-1 w-full overflow-hidden">
                                <div className="font-semibold text-red-800 text-base">
                                    Koneksi API Gagal ke {currentHost}
                                </div>
                                {/* Error log box: Mengubah bg gelap menjadi putih dengan text merah agar kontras */}
                                <div className="text-sm mt-2 font-mono bg-white border border-red-100 text-red-600 px-3 py-2 rounded-lg break-words w-full shadow-sm">
                                    {connection?.error ||
                                        "Unable to establish API socket connection to RouterOS."}
                                </div>
                            </div>
                        </div>

                        {/* Diagnostics box: Mengubah bg-slate-800 menjadi bg-white agar sesuai light theme */}
                        <div className="bg-white border border-slate-200 rounded-xl p-5 space-y-3 shadow-sm">
                            <div className="font-semibold text-slate-800 text-sm flex items-center">
                                <span className="mr-2">🔧</span> Kemungkinan
                                Penyebab & Solusi:
                            </div>
                            <ol className="list-decimal list-outside ml-5 space-y-2.5 text-sm text-slate-600 leading-relaxed">
                                <li>
                                    <strong className="text-slate-900">
                                        API Service belum aktif
                                    </strong>{" "}
                                    — Buka Winbox ke{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-blue-600 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        {currentHost}
                                    </code>
                                    , pastikan{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-slate-700 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        IP → Services → api
                                    </code>{" "}
                                    (port 7111) atau{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-slate-700 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        api-ssl
                                    </code>{" "}
                                    (port 7112) sudah <strong>enabled</strong>.
                                </li>
                                <li>
                                    <strong className="text-slate-900">
                                        User API belum dibuat
                                    </strong>{" "}
                                    — Buat user di{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-slate-700 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        System → Users
                                    </code>{" "}
                                    dengan group{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-slate-700 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        full
                                    </code>
                                    , dan tambahkan{" "}
                                    <strong>Allowed Address</strong>:{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-blue-600 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        192.168.91.41
                                    </code>{" "}
                                    (IP server CIMS).
                                </li>
                                <li>
                                    <strong className="text-slate-900">
                                        Firewall memblokir port API
                                    </strong>{" "}
                                    — Pastikan tidak ada filter rule yang
                                    memblokir port 7111/7112 dari IP{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-blue-600 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        192.168.91.41
                                    </code>
                                    .
                                </li>
                                <li>
                                    <strong className="text-slate-900">
                                        Subnet berbeda / Tidak reachable
                                    </strong>{" "}
                                    — Pastikan server CIMS bisa ping ke{" "}
                                    <code className="bg-slate-50 border border-slate-200 text-blue-600 px-1.5 py-0.5 rounded-md font-mono text-xs">
                                        {currentHost}
                                    </code>
                                    . Jika berbeda subnet, pastikan ada routing.
                                </li>
                            </ol>
                        </div>

                        {/* Action Button: Mengubah warna hijau (emerald) menjadi biru (blue) */}
                        {currentHost !== routerConfig?.host && (
                            <button
                                onClick={() => {
                                    window.location.href =
                                        route("mikrotik.index");
                                }}
                                className="inline-flex items-center space-x-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-medium transition-colors shadow-sm"
                            >
                                <svg
                                    className="w-4 h-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M10 19l-7-7m0 0l7-7m-7 7h18"
                                    />
                                </svg>
                                <span>Kembali ke Default Core Router</span>
                            </button>
                        )}
                    </div>
                )}

                {/* Navigation Tabs */}
                <div className="border-b border-brand-border flex overflow-x-auto space-x-2 pb-2">
                    {[
                        {
                            id: "overview",
                            label: "Ringkasan Sistem",
                            icon: "📊",
                        },
                        { id: "network", label: "IP & Routing", icon: "🌐" },
                        { id: "firewall", label: "Firewall & NAT", icon: "🛡️" },
                        { id: "hotspot", label: "Hotspot Aktif", icon: "📡" },
                        { id: "neighbors", label: "Neighbors", icon: "🔗" },
                        { id: "system", label: "DNS & Paket", icon: "📦" },
                        { id: "logs", label: "Log Sistem", icon: "📜" },
                    ].map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-medium transition duration-150 whitespace-nowrap ${
                                activeTab === tab.id
                                    ? "bg-brand-primary/20 text-emerald-700 border border-emerald-500/30"
                                    : "text-brand-textSecondary hover:text-slate-900 hover:bg-brand-cardElevated"
                            }`}
                        >
                            <span>{tab.icon}</span>
                            <span>{tab.label}</span>
                        </button>
                    ))}
                </div>

                {/* Tab 1: System Overview */}
                {activeTab === "overview" && (
                    <div className="space-y-6">
                        {/* Quick Stats Grid */}
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                    Router Identity
                                </div>
                                <div className="text-xl font-bold text-slate-900 mt-1">
                                    {connection?.identity || "-"}
                                </div>
                                <div className="text-xs text-emerald-700 mt-2 font-mono">
                                    {connection?.board || "-"} (
                                    {connection?.version || "-"})
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                    CPU Utilization
                                </div>
                                <div className="flex items-baseline space-x-2 mt-1">
                                    <span className="text-2xl font-bold text-slate-900">
                                        {metrics?.cpu ?? "-"}%
                                    </span>
                                </div>
                                <div className="w-full bg-brand-bg rounded-full h-2 mt-3 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all duration-500 ${
                                            (metrics?.cpu ?? 0) > 80
                                                ? "bg-red-500"
                                                : (metrics?.cpu ?? 0) > 50
                                                  ? "bg-amber-500"
                                                  : "bg-emerald-500"
                                        }`}
                                        style={{
                                            width: `${metrics?.cpu ?? 0}%`,
                                        }}
                                    ></div>
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                    Memory Usage
                                </div>
                                <div className="flex items-baseline space-x-2 mt-1">
                                    <span className="text-2xl font-bold text-slate-900">
                                        {metrics?.ram ?? "-"}%
                                    </span>
                                </div>
                                <div className="w-full bg-brand-bg rounded-full h-2 mt-3 overflow-hidden">
                                    <div
                                        className="h-2 rounded-full bg-blue-500 transition-all duration-500"
                                        style={{
                                            width: `${metrics?.ram ?? 0}%`,
                                        }}
                                    ></div>
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                    Live Bandwidth
                                </div>
                                <div className="text-sm font-mono mt-2 space-y-1">
                                    <div className="flex justify-between">
                                        <span className="text-emerald-700">
                                            RX:
                                        </span>
                                        <span className="text-slate-900 font-bold">
                                            {formatBps(metrics?.rx)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-blue-700">
                                            TX:
                                        </span>
                                        <span className="text-slate-900 font-bold">
                                            {formatBps(metrics?.tx)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Additional Metrics Row */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">
                                        Uptime
                                    </div>
                                    <div className="text-lg font-bold text-slate-900 mt-1">
                                        {formatUptime(metrics?.uptime)}
                                    </div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-emerald-700">
                                    ⏱️
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">
                                        Temperature
                                    </div>
                                    <div className="text-lg font-bold text-slate-900 mt-1">
                                        {metrics?.temp !== null
                                            ? `${metrics?.temp} °C`
                                            : "N/A"}
                                    </div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-amber-700">
                                    🌡️
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">
                                        Storage Usage
                                    </div>
                                    <div className="text-lg font-bold text-slate-900 mt-1">
                                        {metrics?.storage ?? "-"}%
                                    </div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-purple-700">
                                    💾
                                </div>
                            </div>
                        </div>

                        {/* Interface List */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-slate-900 text-base">
                                    Router Interfaces
                                </h3>
                                <span className="text-xs text-brand-textSecondary font-mono">
                                    {metrics?.interfaces?.length || 0}{" "}
                                    Interfaces
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">
                                                Interface Name
                                            </th>
                                            <th className="px-6 py-3">Type</th>
                                            <th className="px-6 py-3">
                                                Status
                                            </th>
                                            <th className="px-6 py-3">
                                                MAC Address
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {metrics?.interfaces?.map(
                                            (iface, idx) => (
                                                <tr
                                                    key={idx}
                                                    className="hover:bg-brand-cardElevated/50 transition"
                                                >
                                                    <td className="px-6 py-4 font-mono font-medium text-slate-900">
                                                        {iface.name}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        {iface.type || "-"}
                                                    </td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${
                                                                iface.status ===
                                                                "up"
                                                                    ? "bg-emerald-500/10 text-emerald-700"
                                                                    : "bg-red-500/10 text-red-700"
                                                            }`}
                                                        >
                                                            {iface.status.toUpperCase()}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 font-mono text-xs text-brand-textSecondary">
                                                        {iface.mac || "-"}
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {/* Tab 2: IP & Routing */}
                {activeTab === "network" && (
                    <div className="space-y-6">
                        {/* IP Addresses */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-slate-900 text-base">
                                    IP Addresses (/ip/address)
                                </h3>
                                <span className="text-xs text-brand-textSecondary font-mono">
                                    {ipAddresses.length} Addresses
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">
                                                IP Address / Prefix
                                            </th>
                                            <th className="px-6 py-3">
                                                Network
                                            </th>
                                            <th className="px-6 py-3">
                                                Interface
                                            </th>
                                            <th className="px-6 py-3">Type</th>
                                            <th className="px-6 py-3">
                                                Comment
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {ipAddresses.map((ip, idx) => (
                                            <tr
                                                key={idx}
                                                className="hover:bg-brand-cardElevated/50 transition"
                                            >
                                                <td className="px-6 py-4 font-mono font-bold text-emerald-700">
                                                    {ip.address}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {ip.network || "-"}
                                                </td>
                                                <td className="px-6 py-4 font-mono font-medium text-slate-900">
                                                    {ip.interface}
                                                </td>
                                                <td className="px-6 py-4 text-xs">
                                                    {ip.dynamic ? (
                                                        <span className="px-2 py-0.5 rounded bg-blue-500/10 text-blue-700">
                                                            Dynamic
                                                        </span>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded bg-purple-500/10 text-purple-700">
                                                            Static
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-xs italic">
                                                    {ip.comment || "-"}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Routing Table */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-slate-900 text-base">
                                    Routing Table (/ip/route)
                                </h3>
                                <span className="text-xs text-brand-textSecondary font-mono">
                                    {routes.length} Routes
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">
                                                Dst. Address
                                            </th>
                                            <th className="px-6 py-3">
                                                Gateway
                                            </th>
                                            <th className="px-6 py-3">
                                                Distance
                                            </th>
                                            <th className="px-6 py-3">Flags</th>
                                            <th className="px-6 py-3">
                                                Comment
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {routes.map((r, idx) => (
                                            <tr
                                                key={idx}
                                                className="hover:bg-brand-cardElevated/50 transition"
                                            >
                                                <td className="px-6 py-4 font-mono font-bold text-slate-900">
                                                    {r.dst_address}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-emerald-700">
                                                    {r.gateway || "connected"}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {r.distance ?? "-"}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="flex space-x-1 text-xs font-mono">
                                                        {r.active && (
                                                            <span className="px-1.5 py-0.5 bg-emerald-500/10 text-emerald-700 rounded">
                                                                A
                                                            </span>
                                                        )}
                                                        {r.static && (
                                                            <span className="px-1.5 py-0.5 bg-blue-500/10 text-blue-700 rounded">
                                                                S
                                                            </span>
                                                        )}
                                                        {r.dynamic && (
                                                            <span className="px-1.5 py-0.5 bg-amber-500/10 text-amber-700 rounded">
                                                                D
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 text-xs italic">
                                                    {r.comment || "-"}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {/* Tab 3: Firewall & NAT */}
                {activeTab === "firewall" && (
                    <div className="space-y-6">
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">
                                Loading Firewall & NAT Rules...
                            </div>
                        ) : (
                            <>
                                {/* NAT Rules */}
                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                        <h3 className="font-bold text-slate-900 text-base">
                                            NAT Rules (/ip/firewall/nat)
                                        </h3>
                                        <span className="text-xs text-brand-textSecondary font-mono">
                                            {natRules.length} Rules
                                        </span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm text-brand-textSecondary">
                                            <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                <tr>
                                                    <th className="px-6 py-3">
                                                        Status
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Chain
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Action
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Protocol / Port
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        To Addresses / Ports
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Out Interface
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Comment
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-brand-border">
                                                {natRules.map((r, idx) => (
                                                    <tr
                                                        key={idx}
                                                        className={`hover:bg-brand-cardElevated/50 transition ${r.disabled ? "opacity-45 bg-red-950/10" : ""}`}
                                                    >
                                                        <td className="px-6 py-4">
                                                            {r.disabled ? (
                                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-700 border border-red-500/30">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-red-400 mr-1.5"></span>
                                                                    Disabled
                                                                    (Non-aktif)
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-700 border border-emerald-500/30">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                                    Enabled
                                                                    (Aktif)
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono text-purple-700">
                                                            {r.chain}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono font-bold text-slate-900">
                                                            {r.action}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono text-xs">
                                                            {r.protocol}{" "}
                                                            {r.dst_port
                                                                ? `:${r.dst_port}`
                                                                : ""}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono text-emerald-700">
                                                            {r.to_addresses ||
                                                                "-"}{" "}
                                                            {r.to_ports
                                                                ? `:${r.to_ports}`
                                                                : ""}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono text-xs">
                                                            {r.out_interface ||
                                                                "-"}
                                                        </td>
                                                        <td className="px-6 py-4 text-xs italic text-brand-textSecondary">
                                                            {r.comment || "-"}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {/* Firewall Filter */}
                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                        <h3 className="font-bold text-slate-900 text-base">
                                            Firewall Filter Rules
                                            (/ip/firewall/filter)
                                        </h3>
                                        <span className="text-xs text-brand-textSecondary font-mono">
                                            {firewallFilter.length} Rules
                                        </span>
                                    </div>
                                    {firewallFilter.length === 0 ? (
                                        <div className="p-8 text-center text-brand-textSecondary text-sm">
                                            No custom firewall filter rules
                                            configured.
                                        </div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left text-sm text-brand-textSecondary">
                                                <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                    <tr>
                                                        <th className="px-6 py-3">
                                                            Status
                                                        </th>
                                                        <th className="px-6 py-3">
                                                            Chain
                                                        </th>
                                                        <th className="px-6 py-3">
                                                            Action
                                                        </th>
                                                        <th className="px-6 py-3">
                                                            Src / Dst Address
                                                        </th>
                                                        <th className="px-6 py-3">
                                                            Bytes / Packets
                                                        </th>
                                                        <th className="px-6 py-3">
                                                            Comment
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-brand-border">
                                                    {firewallFilter.map(
                                                        (r, idx) => (
                                                            <tr
                                                                key={idx}
                                                                className={`hover:bg-brand-cardElevated/50 transition ${r.disabled ? "opacity-45 bg-red-950/10" : ""}`}
                                                            >
                                                                <td className="px-6 py-4">
                                                                    {r.disabled ? (
                                                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-700 border border-red-500/30">
                                                                            <span className="h-1.5 w-1.5 rounded-full bg-red-400 mr-1.5"></span>
                                                                            Disabled
                                                                            (Non-aktif)
                                                                        </span>
                                                                    ) : (
                                                                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-700 border border-emerald-500/30">
                                                                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                                            Enabled
                                                                            (Aktif)
                                                                        </span>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 font-mono text-purple-700">
                                                                    {r.chain}
                                                                </td>
                                                                <td className="px-6 py-4 font-mono font-bold text-slate-900">
                                                                    {r.action}
                                                                </td>
                                                                <td className="px-6 py-4 font-mono text-xs">
                                                                    {r.src_address ||
                                                                        "*"}{" "}
                                                                    →{" "}
                                                                    {r.dst_address ||
                                                                        "*"}
                                                                </td>
                                                                <td className="px-6 py-4 font-mono text-xs">
                                                                    {r.bytes} B
                                                                    ({r.packets}{" "}
                                                                    pkts)
                                                                </td>
                                                                <td className="px-6 py-4 text-xs italic text-brand-textSecondary">
                                                                    {r.comment ||
                                                                        "-"}
                                                                </td>
                                                            </tr>
                                                        ),
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                </div>
                            </>
                        )}
                    </div>
                )}

                {/* Tab 4: Hotspot Active */}
                {activeTab === "hotspot" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-5 border-b border-brand-border flex items-center justify-between">
                            <h3 className="font-bold text-slate-900 text-base">
                                Active Hotspot Users (/ip/hotspot/active)
                            </h3>
                            <span className="text-xs text-brand-textSecondary font-mono">
                                {hotspotActive.length} Active Users
                            </span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">
                                Loading Hotspot Users...
                            </div>
                        ) : hotspotActive.length === 0 ? (
                            <div className="p-8 text-center text-brand-textSecondary text-sm">
                                No active hotspot user sessions currently
                                online.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">
                                                Username
                                            </th>
                                            <th className="px-6 py-3">
                                                IP Address
                                            </th>
                                            <th className="px-6 py-3">
                                                MAC Address
                                            </th>
                                            <th className="px-6 py-3">
                                                Uptime
                                            </th>
                                            <th className="px-6 py-3">
                                                Traffic (In / Out)
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {hotspotActive.map((u, idx) => (
                                            <tr
                                                key={idx}
                                                className="hover:bg-brand-cardElevated/50 transition"
                                            >
                                                <td className="px-6 py-4 font-bold text-emerald-700">
                                                    {u.user}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-slate-900">
                                                    {u.address}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {u.mac}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {u.uptime}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {(
                                                        u.bytes_in /
                                                        1024 /
                                                        1024
                                                    ).toFixed(2)}{" "}
                                                    MB /{" "}
                                                    {(
                                                        u.bytes_out /
                                                        1024 /
                                                        1024
                                                    ).toFixed(2)}{" "}
                                                    MB
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* Tab 5: Neighbors */}
                {activeTab === "neighbors" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-5 border-b border-brand-border flex items-center justify-between">
                            <h3 className="font-bold text-slate-900 text-base">
                                Neighbor Discovery (/ip/neighbor)
                            </h3>
                            <span className="text-xs text-brand-textSecondary font-mono">
                                {neighbors.length} Discovered Nodes
                            </span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">
                                Discovering Neighbors...
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">
                                                Identity / Hostname
                                            </th>
                                            <th className="px-6 py-3">
                                                IP Address
                                            </th>
                                            <th className="px-6 py-3">
                                                Platform / Board
                                            </th>
                                            <th className="px-6 py-3">
                                                Via Interface
                                            </th>
                                            <th className="px-6 py-3">
                                                MAC Address
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {neighbors.map((n, idx) => (
                                            <tr
                                                key={idx}
                                                className="hover:bg-brand-cardElevated/50 transition"
                                            >
                                                <td className="px-6 py-4 font-bold text-slate-900">
                                                    {n.identity ||
                                                        "Unknown Node"}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-emerald-700">
                                                    {n.address || "-"}
                                                </td>
                                                <td className="px-6 py-4 text-xs">
                                                    {n.platform || "MikroTik"}{" "}
                                                    {n.board
                                                        ? `(${n.board})`
                                                        : ""}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs text-purple-700">
                                                    {n.interface}
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {n.mac || "-"}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* Tab 6: System Info (DNS & Packages & Users) */}
                {activeTab === "system" && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        {/* Router Accounts */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border">
                                <h3 className="font-bold text-slate-900 text-base">
                                    Router User Accounts
                                </h3>
                            </div>
                            <div className="p-5 space-y-3">
                                {users.map((u, idx) => (
                                    <div
                                        key={idx}
                                        className="flex items-center justify-between p-3 bg-brand-bgSecondary rounded-xl"
                                    >
                                        <div>
                                            <div className="font-bold text-slate-900">
                                                {u.name}
                                            </div>
                                            <div className="text-xs text-brand-textSecondary font-mono">
                                                Group: {u.group}
                                            </div>
                                        </div>
                                        <span className="px-2.5 py-1 rounded bg-emerald-500/10 text-emerald-700 text-xs font-semibold">
                                            Active
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* DNS Config */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border">
                                <h3 className="font-bold text-slate-900 text-base">
                                    DNS Configuration
                                </h3>
                            </div>
                            <div className="p-5 space-y-4 text-sm text-brand-textSecondary">
                                <div className="flex justify-between border-b border-brand-border/50 pb-2">
                                    <span>DNS Servers:</span>
                                    <span className="font-mono font-bold text-emerald-700">
                                        {dns.servers || "-"}
                                    </span>
                                </div>
                                <div className="flex justify-between border-b border-brand-border/50 pb-2">
                                    <span>Allow Remote Requests:</span>
                                    <span className="font-mono text-slate-900">
                                        {dns.allow_remote ? "Yes" : "No"}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span>DNS Cache Usage:</span>
                                    <span className="font-mono text-purple-700">
                                        {dns.cache_used ?? 0} /{" "}
                                        {dns.cache_size ?? 0}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* System Packages */}
                        <div className="lg:col-span-2 bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-slate-900 text-base">
                                    Installed Packages (/system/package)
                                </h3>
                                <span className="text-xs text-brand-textSecondary font-mono">
                                    {packages.length} Packages
                                </span>
                            </div>
                            <div className="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                {packages.map((pkg, idx) => (
                                    <div
                                        key={idx}
                                        className={`p-3 rounded-xl border ${
                                            pkg.disabled
                                                ? "bg-brand-bg/50 border-brand-border text-brand-textSecondary opacity-50"
                                                : "bg-brand-bgSecondary border-brand-border text-slate-900"
                                        }`}
                                    >
                                        <div className="font-bold text-sm">
                                            {pkg.name}
                                        </div>
                                        <div className="text-xs font-mono text-emerald-700 mt-1">
                                            v{pkg.version}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>
                )}

                {/* Tab 7: System Logs */}
                {activeTab === "logs" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-5 border-b border-brand-border flex items-center justify-between">
                            <h3 className="font-bold text-slate-900 text-base">
                                RouterOS System Logs (/log)
                            </h3>
                            <span className="text-xs text-brand-textSecondary font-mono">
                                Last 50 Entries
                            </span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">
                                Fetching RouterOS Logs...
                            </div>
                        ) : (
                            <div className="p-4 bg-brand-bg font-mono text-xs space-y-1.5 max-h-[500px] overflow-y-auto">
                                {logs.map((log, idx) => (
                                    <div
                                        key={idx}
                                        className="flex space-x-3 hover:bg-brand-card/50 p-1 rounded transition"
                                    >
                                        <span className="text-brand-textSecondary shrink-0">
                                            {log.time}
                                        </span>
                                        <span className="text-purple-700 shrink-0">
                                            [{log.topics}]
                                        </span>
                                        <span className="text-slate-900">
                                            {log.message}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </CimsLayout>
    );
}
