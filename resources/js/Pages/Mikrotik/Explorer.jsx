import CimsLayout from "@/Layouts/CimsLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";
import { useEffect, useState } from "react";

/**
 * Tahapan satu putaran speedtest, urut sesuai urutan kerja speedtest-cli.
 *
 * Dipakai untuk mengubah masa tunggu 30–90 detik dari "menatap log" menjadi
 * keterangan yang bisa dibaca: tahap mana yang sedang dikerjakan, dan angka mana
 * yang sudah selesai diukur. Download memang sudah final puluhan detik sebelum
 * putaran berakhir, jadi tidak ada alasan menyembunyikannya sampai akhir.
 */
const SPEEDTEST_STAGES = [
    { id: "config", label: "Konfigurasi" },
    { id: "latency", label: "Pilih server" },
    { id: "download", label: "Download" },
    { id: "upload", label: "Upload" },
];

const SPEEDTEST_STAGE_ORDER = [
    "starting",
    "config",
    "latency",
    "download",
    "upload",
    "done",
];

const speedtestStageIndex = (stage) => {
    const index = SPEEDTEST_STAGE_ORDER.indexOf(stage);

    return index < 0 ? 0 : index;
};

/**
 * Angka sudah terukur atau belum. Dipisah karena 0 adalah nilai yang sah di sini
 * (0 ms latensi ke gateway lokal bukan mustahil), jadi cek kebenaran biasa akan
 * salah menyembunyikannya.
 */
const speedtestHasNumber = (value) => value !== null && value !== undefined;

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
    // null = tab OSPF belum pernah dibuka. Dibedakan dari objek kosong karena
    // router yang memang belum menjalankan OSPF juga menjawab dengan daftar
    // interface kosong, dan keduanya harus tampil berbeda.
    const [ospf, setOspf] = useState(null);
    // null = tab Speedtest belum pernah dibuka. Isinya keadaan container di
    // router (ada/tidak, logging aktif/tidak) plus putaran terakhir — bukan
    // sekadar daftar hasil, karena yang paling sering perlu ditampilkan justru
    // alasan kenapa fitur ini belum bisa dipakai di router tersebut.
    const [speedtest, setSpeedtest] = useState(null);
    const [speedtestStarting, setSpeedtestStarting] = useState(false);
    // Tindakan tulis yang sedang berjalan: "stop" atau "restart". Satu state,
    // bukan dua boolean, karena keduanya tidak boleh berjalan bersamaan.
    const [speedtestAction, setSpeedtestAction] = useState(null);
    // Kegagalan Stop/Restart disimpan terpisah dari speedtest.error: yang kedua
    // berarti daftar container tidak bisa dibaca sama sekali, dan menumpangkan
    // kegagalan tindakan di atasnya akan menyembunyikan kartu container beserta
    // tombol-tombolnya justru ketika operator paling butuh menekannya lagi.
    const [speedtestActionError, setSpeedtestActionError] = useState(null);
    // Log container yang dibuka atas permintaan (padanan tab Log di Winbox),
    // terpisah dari baris log satu putaran. null = belum pernah diminta.
    const [speedtestLog, setSpeedtestLog] = useState(null);
    const [speedtestLogLoading, setSpeedtestLogLoading] = useState(false);
    const [loadingTab, setLoadingTab] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [isRouterDropdownOpen, setIsRouterDropdownOpen] = useState(false);
    const [routerNeighborsByHost, setRouterNeighborsByHost] = useState({});
    const [loadingRouterNeighbors, setLoadingRouterNeighbors] = useState({});
    const [expandedRouterHosts, setExpandedRouterHosts] = useState({});

    const currentHost = selectedHost || routerConfig.host;

    // Memulai speedtest = menulis ke perangkat jaringan bersama sekaligus
    // menjenuhkan uplink kampus. Route-nya sudah dipagari 'manage devices' di
    // server; ini hanya supaya tombolnya tidak ditawarkan ke orang yang pasti
    // akan ditolak.
    const canManageDevices = (auth?.user?.permissions ?? []).includes(
        "manage devices",
    );
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
        } else if (activeTab === "ospf" && ospf === null) {
            setLoadingTab(true);
            axios
                .get(apiRoute("mikrotik.api.ospf"))
                .then((res) => setOspf(res.data))
                .catch(() =>
                    setOspf({
                        reachable: false,
                        error: "Gagal mengambil data OSPF dari router.",
                    }),
                )
                .finally(() => setLoadingTab(false));
        } else if (activeTab === "speedtest" && speedtest === null) {
            setLoadingTab(true);
            axios
                .get(apiRoute("mikrotik.api.speedtest"))
                .then((res) => setSpeedtest(res.data))
                .catch(() =>
                    setSpeedtest({
                        error: "Gagal membaca keadaan container speedtest dari router.",
                    }),
                )
                .finally(() => setLoadingTab(false));
        } else if (activeTab === "logs" && logs.length === 0) {
            setLoadingTab(true);
            axios.get(apiRoute("mikrotik.api.logs")).then((res) => {
                setLogs(res.data);
                setLoadingTab(false);
            });
        }
    }, [activeTab, connection, currentHost]);

    // Keadaan speedtest terikat pada satu router: container-nya, putaran yang
    // sedang berjalan, dan hasil terakhirnya semuanya per-host. Kalau tidak
    // dikosongkan saat pindah router, tab ini akan menampilkan hasil router lama
    // dengan label router baru.
    useEffect(() => {
        setSpeedtest(null);
        setSpeedtestLog(null);
        setSpeedtestActionError(null);
    }, [currentHost]);

    // Satu putaran berjalan 30–90 detik di router dan hasilnya baru muncul di
    // /log secara bertahap, jadi dijemput berkala. Interval dibersihkan begitu
    // state bukan "running" lagi, supaya tab yang dibiarkan terbuka tidak terus
    // menghubungi router tanpa alasan.
    useEffect(() => {
        if (speedtest?.run?.state !== "running") return;

        const timer = setInterval(() => {
            axios
                .get(apiRoute("mikrotik.api.speedtest.poll"))
                .then((res) =>
                    setSpeedtest((prev) => ({ ...prev, run: res.data })),
                )
                .catch(() => {});
        }, 4000);

        return () => clearInterval(timer);
    }, [speedtest?.run?.state, currentHost]);

    const handleStartSpeedtest = async () => {
        setSpeedtestStarting(true);
        try {
            const res = await axios.post(
                apiRoute("mikrotik.api.speedtest.start"),
            );
            setSpeedtest((prev) => ({ ...prev, run: res.data }));
        } catch (e) {
            // 422 dari server membawa alasan yang bisa ditindaklanjuti operator
            // (container belum ada, logging belum aktif, putaran lain jalan) —
            // itu yang ditampilkan, bukan "terjadi kesalahan".
            setSpeedtest((prev) => ({
                ...prev,
                run: {
                    state: "failed",
                    error:
                        e?.response?.data?.error ||
                        "Gagal memulai speedtest di router.",
                    lines: [],
                },
            }));
        } finally {
            setSpeedtestStarting(false);
        }
    };

    // Stop dan Restart menjawab dengan keadaan lengkap (status container + putaran),
    // jadi hasilnya menggantikan seluruh state — bukan hanya bagian run-nya seperti
    // pada poll. Status container memang berubah setelah keduanya.
    const handleStopSpeedtest = async () => {
        setSpeedtestAction("stop");
        setSpeedtestActionError(null);
        try {
            const res = await axios.post(apiRoute("mikrotik.api.speedtest.stop"));
            setSpeedtest(res.data);
        } catch (e) {
            setSpeedtestActionError(
                e?.response?.data?.error ||
                    "Gagal menghentikan container speedtest di router.",
            );
        } finally {
            setSpeedtestAction(null);
        }
    };

    const handleRestartSpeedtest = async () => {
        setSpeedtestAction("restart");
        setSpeedtestActionError(null);
        try {
            const res = await axios.post(
                apiRoute("mikrotik.api.speedtest.restart"),
            );
            setSpeedtest(res.data);
        } catch (e) {
            setSpeedtestActionError(
                e?.response?.data?.error ||
                    "Gagal me-restart container speedtest di router.",
            );
        } finally {
            setSpeedtestAction(null);
        }
    };

    const handleFetchSpeedtestLog = async () => {
        setSpeedtestLogLoading(true);
        try {
            const res = await axios.get(apiRoute("mikrotik.api.speedtest.log"));
            setSpeedtestLog(res.data.lines || []);
        } catch (e) {
            // Dibedakan dari "tidak ada baris container": daftar kosong berarti
            // router menjawab tapi log-nya memang bersih, sedangkan ini berarti
            // log-nya tidak terbaca sama sekali. Dua keadaan itu menuntun ke
            // pemeriksaan yang berbeda.
            setSpeedtestActionError(
                e?.response?.data?.error ||
                    "Gagal membaca /log dari router untuk topik container.",
            );
        } finally {
            setSpeedtestLogLoading(false);
        }
    };

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
            } else if (activeTab === "ospf") {
                const res = await axios.get(apiRoute("mikrotik.api.ospf"));
                setOspf(res.data);
            } else if (activeTab === "speedtest") {
                const res = await axios.get(apiRoute("mikrotik.api.speedtest"));
                setSpeedtest(res.data);
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

    // Peta status OSPF → tampilan. Warna yang sama dipakai di kartu ringkasan dan
    // di baris tabel, supaya angka di atas dan baris di bawah terbaca sebagai hal
    // yang sama tanpa perlu membaca legenda.
    const OSPF_STATUS = {
        full: {
            label: "OSPF Aktif",
            badge: "bg-emerald-500/10 text-emerald-700 border-emerald-500/30",
            dot: "bg-emerald-500",
        },
        passive: {
            label: "Passive",
            badge: "bg-blue-500/10 text-blue-700 border-blue-500/30",
            dot: "bg-blue-500",
        },
        warning: {
            label: "Perlu Diperiksa",
            badge: "bg-amber-500/10 text-amber-700 border-amber-500/30",
            dot: "bg-amber-500",
        },
        not_in_ospf: {
            label: "Belum OSPF",
            badge: "bg-rose-500/10 text-rose-700 border-rose-500/30",
            dot: "bg-rose-500",
        },
        no_ip: {
            label: "Tanpa IP",
            badge: "bg-slate-500/10 text-slate-600 border-slate-400/30",
            dot: "bg-slate-400",
        },
    };

    const ospfSummaryCards = [
        { key: "full", hint: "adjacency terbentuk" },
        { key: "warning", hint: "masuk OSPF, adjacency belum Full" },
        { key: "not_in_ospf", hint: "punya IP, belum masuk OSPF" },
        { key: "passive", hint: "diiklankan tanpa adjacency" },
        { key: "no_ip", hint: "di luar cakupan OSPF" },
    ];

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
                        { id: "ospf", label: "OSPF", icon: "🛰️" },
                        {
                            id: "speedtest",
                            label: "Speedtest Router",
                            icon: "🚀",
                        },
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

                {/* Tab 3: OSPF Coverage */}
                {activeTab === "ospf" && (
                    <div className="space-y-6">
                        {loadingTab && !ospf ? (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-12 text-center text-brand-textSecondary">
                                Membaca instance, area, interface, dan neighbor
                                OSPF dari router...
                            </div>
                        ) : !ospf ? null : (
                            <>
                                {!ospf.reachable && (
                                    <div className="bg-rose-500/10 border border-rose-500/30 rounded-2xl p-5">
                                        <div className="font-bold text-rose-700 text-sm">
                                            Data OSPF tidak dapat dibaca
                                        </div>
                                        <div className="text-xs text-rose-700/80 mt-1 font-mono break-all">
                                            {ospf.error ||
                                                "Router tidak menjawab."}
                                        </div>
                                    </div>
                                )}

                                {ospf.reachable && ospf.supported === false && (
                                    <div className="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-5">
                                        <div className="font-bold text-amber-700 text-sm">
                                            Router tidak mengenali perintah
                                            /routing/ospf
                                        </div>
                                        <div className="text-xs text-amber-700/80 mt-1">
                                            {ospf.error}
                                        </div>
                                    </div>
                                )}

                                {ospf.reachable &&
                                    ospf.supported !== false &&
                                    !ospf.configured && (
                                        <div className="bg-blue-500/10 border border-blue-500/30 rounded-2xl p-5">
                                            <div className="font-bold text-blue-700 text-sm">
                                                OSPF belum dijalankan di router
                                                ini
                                            </div>
                                            <div className="text-xs text-blue-700/80 mt-1">
                                                Tidak ada instance OSPF, jadi
                                                seluruh interface di bawah
                                                berstatus belum routing OSPF.
                                            </div>
                                        </div>
                                    )}

                                {(ospf.instances || []).length > 0 && (
                                    <div className="bg-brand-card border border-brand-border rounded-2xl p-5 flex flex-wrap gap-x-10 gap-y-4">
                                        {(ospf.instances || []).map(
                                            (inst, idx) => (
                                                <div key={idx}>
                                                    <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                                        Instance{" "}
                                                        {inst.name || "-"}
                                                    </div>
                                                    <div className="text-lg font-bold text-slate-900 mt-1 font-mono">
                                                        {inst.router_id ||
                                                            "router-id belum diset"}
                                                    </div>
                                                    <div className="text-xs text-brand-textSecondary mt-0.5">
                                                        {inst.disabled
                                                            ? "Disabled"
                                                            : "Aktif"}
                                                        {inst.version
                                                            ? ` · OSPFv${inst.version}`
                                                            : ""}
                                                    </div>
                                                </div>
                                            ),
                                        )}
                                        <div>
                                            <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">
                                                Area
                                            </div>
                                            <div className="text-lg font-bold text-slate-900 mt-1 font-mono">
                                                {(ospf.areas || []).length
                                                    ? (ospf.areas || [])
                                                          .map(
                                                              (a) =>
                                                                  `${a.name}${a.area_id ? ` (${a.area_id})` : ""}`,
                                                          )
                                                          .join(", ")
                                                    : "-"}
                                            </div>
                                            <div className="text-xs text-brand-textSecondary mt-0.5">
                                                Konfigurasi gaya RouterOS{" "}
                                                {ospf.flavor === "v7"
                                                    ? "v7 (interface-template)"
                                                    : ospf.flavor === "v6"
                                                      ? "v6 (network statement)"
                                                      : "tidak terdeteksi"}
                                            </div>
                                        </div>
                                    </div>
                                )}

                                {ospf.summary && (
                                    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                                        {ospfSummaryCards.map((card) => (
                                            <div
                                                key={card.key}
                                                className={`p-5 rounded-2xl border ${OSPF_STATUS[card.key].badge}`}
                                            >
                                                <div className="flex items-center space-x-2">
                                                    <span
                                                        className={`h-2 w-2 rounded-full ${OSPF_STATUS[card.key].dot}`}
                                                        aria-hidden="true"
                                                    ></span>
                                                    <span className="text-xs uppercase tracking-wider font-semibold">
                                                        {
                                                            OSPF_STATUS[
                                                                card.key
                                                            ].label
                                                        }
                                                    </span>
                                                </div>
                                                <div className="text-3xl font-bold mt-2 text-slate-900">
                                                    {ospf.summary[card.key] ??
                                                        0}
                                                </div>
                                                <div className="text-[11px] mt-1 opacity-80">
                                                    {card.hint}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between gap-4">
                                        <div>
                                            <h3 className="font-bold text-slate-900 text-base">
                                                Cakupan OSPF per Interface
                                            </h3>
                                            <p className="text-xs text-brand-textSecondary mt-0.5">
                                                Dirakit dari /interface,
                                                /ip/address,
                                                /routing/ospf/interface, dan
                                                /routing/ospf/neighbor. Yang
                                                perlu ditindaklanjuti tampil di
                                                atas.
                                            </p>
                                        </div>
                                        <span className="text-xs text-brand-textSecondary font-mono whitespace-nowrap">
                                            {ospf.summary?.in_ospf ?? 0} /{" "}
                                            {ospf.summary?.total ?? 0} di OSPF
                                        </span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm text-brand-textSecondary">
                                            <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                <tr>
                                                    <th className="px-6 py-3">
                                                        Interface
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Alamat IP
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Status OSPF
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Area / Cost
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Neighbor
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Keterangan
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-brand-border">
                                                {(ospf.interfaces || [])
                                                    .length === 0 && (
                                                    <tr>
                                                        <td
                                                            colSpan="6"
                                                            className="px-6 py-12 text-center text-brand-textSecondary"
                                                        >
                                                            Tidak ada interface
                                                            yang terbaca dari
                                                            router.
                                                        </td>
                                                    </tr>
                                                )}
                                                {(ospf.interfaces || []).map(
                                                    (row) => {
                                                        const tone =
                                                            OSPF_STATUS[
                                                                row.status
                                                            ] ||
                                                            OSPF_STATUS.no_ip;

                                                        return (
                                                            <tr
                                                                key={
                                                                    row.interface
                                                                }
                                                                className="hover:bg-brand-cardElevated/50 transition"
                                                            >
                                                                <td className="px-6 py-4">
                                                                    <div className="font-mono font-bold text-slate-900">
                                                                        {
                                                                            row.interface
                                                                        }
                                                                    </div>
                                                                    <div className="text-[11px] mt-0.5">
                                                                        {row.type ||
                                                                            "-"}
                                                                        {row.disabled
                                                                            ? " · disabled"
                                                                            : row.running
                                                                              ? " · running"
                                                                              : " · not running"}
                                                                    </div>
                                                                </td>
                                                                <td className="px-6 py-4 font-mono text-xs">
                                                                    {(
                                                                        row.addresses ||
                                                                        []
                                                                    ).length ===
                                                                    0
                                                                        ? "-"
                                                                        : (
                                                                              row.addresses ||
                                                                              []
                                                                          ).map(
                                                                              (
                                                                                  addr,
                                                                              ) => (
                                                                                  <div
                                                                                      key={
                                                                                          addr
                                                                                      }
                                                                                      className="text-emerald-700"
                                                                                  >
                                                                                      {
                                                                                          addr
                                                                                      }
                                                                                  </div>
                                                                              ),
                                                                          )}
                                                                </td>
                                                                <td className="px-6 py-4">
                                                                    <span
                                                                        className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-bold ${tone.badge}`}
                                                                    >
                                                                        <span
                                                                            className={`h-1.5 w-1.5 rounded-full ${tone.dot}`}
                                                                            aria-hidden="true"
                                                                        ></span>
                                                                        {
                                                                            tone.label
                                                                        }
                                                                    </span>
                                                                    {row.dynamic && (
                                                                        <div className="text-[11px] mt-1">
                                                                            entri
                                                                            dinamis
                                                                        </div>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 font-mono text-xs">
                                                                    {row.area ||
                                                                        "-"}
                                                                    {row.cost
                                                                        ? ` / ${row.cost}`
                                                                        : ""}
                                                                    {row.network_type && (
                                                                        <div className="text-[11px] text-brand-textSecondary">
                                                                            {
                                                                                row.network_type
                                                                            }
                                                                        </div>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4">
                                                                    {row.neighbor_count ===
                                                                    0 ? (
                                                                        <span className="text-xs">
                                                                            -
                                                                        </span>
                                                                    ) : (
                                                                        <div className="space-y-1">
                                                                            {(
                                                                                row.neighbors ||
                                                                                []
                                                                            ).map(
                                                                                (
                                                                                    nb,
                                                                                    i,
                                                                                ) => (
                                                                                    <div
                                                                                        key={
                                                                                            i
                                                                                        }
                                                                                        className="text-xs font-mono"
                                                                                    >
                                                                                        <span
                                                                                            className={
                                                                                                nb.full
                                                                                                    ? "text-emerald-700 font-bold"
                                                                                                    : "text-amber-700 font-bold"
                                                                                            }
                                                                                        >
                                                                                            {nb.state ||
                                                                                                "?"}
                                                                                        </span>{" "}
                                                                                        {nb.router_id ||
                                                                                            nb.address ||
                                                                                            ""}
                                                                                    </div>
                                                                                ),
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </td>
                                                                <td className="px-6 py-4 text-xs max-w-md">
                                                                    {row.detail}
                                                                    {row.matched_by && (
                                                                        <div className="text-[11px] text-brand-textSecondary mt-0.5 font-mono">
                                                                            via{" "}
                                                                            {
                                                                                row.matched_by
                                                                            }
                                                                        </div>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        );
                                                    },
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                        <h3 className="font-bold text-slate-900 text-base">
                                            Neighbor OSPF
                                            (/routing/ospf/neighbor)
                                        </h3>
                                        <span className="text-xs text-brand-textSecondary font-mono">
                                            {ospf.summary?.full_neighbors ?? 0}{" "}
                                            Full dari{" "}
                                            {ospf.summary?.neighbors ?? 0}
                                        </span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm text-brand-textSecondary">
                                            <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                <tr>
                                                    <th className="px-6 py-3">
                                                        Router ID
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Address
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Interface
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        State
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        Adjacency
                                                    </th>
                                                    <th className="px-6 py-3">
                                                        State Changes
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-brand-border">
                                                {(ospf.neighbors || [])
                                                    .length === 0 && (
                                                    <tr>
                                                        <td
                                                            colSpan="6"
                                                            className="px-6 py-12 text-center text-brand-textSecondary"
                                                        >
                                                            Belum ada neighbor
                                                            OSPF yang terbentuk.
                                                        </td>
                                                    </tr>
                                                )}
                                                {(ospf.neighbors || []).map(
                                                    (nb, idx) => (
                                                        <tr
                                                            key={idx}
                                                            className="hover:bg-brand-cardElevated/50 transition"
                                                        >
                                                            <td className="px-6 py-4 font-mono font-bold text-slate-900">
                                                                {nb.router_id ||
                                                                    "-"}
                                                            </td>
                                                            <td className="px-6 py-4 font-mono text-emerald-700">
                                                                {nb.address ||
                                                                    "-"}
                                                            </td>
                                                            <td className="px-6 py-4 font-mono text-xs text-purple-700">
                                                                {nb.interface ||
                                                                    "-"}
                                                            </td>
                                                            <td className="px-6 py-4">
                                                                <span
                                                                    className={`px-2 py-0.5 rounded text-xs font-bold ${
                                                                        nb.full
                                                                            ? "bg-emerald-500/10 text-emerald-700"
                                                                            : "bg-amber-500/10 text-amber-700"
                                                                    }`}
                                                                >
                                                                    {nb.state ||
                                                                        "?"}
                                                                </span>
                                                            </td>
                                                            <td className="px-6 py-4 font-mono text-xs">
                                                                {nb.adjacency ||
                                                                    "-"}
                                                            </td>
                                                            <td className="px-6 py-4 font-mono text-xs">
                                                                {nb.state_changes ??
                                                                    "-"}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                )}

                {/* Tab 3b: Speedtest dari container di router */}
                {activeTab === "speedtest" && (
                    <div className="space-y-6">
                        <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                            <h3 className="text-lg font-bold text-slate-900">
                                Speedtest dari Router
                            </h3>
                            <p className="text-sm text-brand-textSecondary mt-1 leading-relaxed">
                                Pengukuran ini dijalankan oleh container{" "}
                                <span className="font-mono">speedtest-cli</span>{" "}
                                di dalam RouterOS, jadi yang terukur adalah
                                kapasitas uplink router itu sendiri — berbeda
                                dari speedtest di halaman Monitoring yang
                                mengukur dari server aplikasi. Keluaran container
                                dibaca dari{" "}
                                <span className="font-mono">/log</span>, karena
                                RouterOS tidak menyediakan cara lain mengambil
                                stdout container lewat API.
                            </p>
                        </div>

                        {loadingTab && speedtest === null && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-8 text-center text-sm text-brand-textSecondary">
                                Membaca daftar container di router…
                            </div>
                        )}

                        {speedtest?.error && (
                            <div className="bg-rose-500/10 border border-rose-500/30 rounded-2xl p-5">
                                <div className="text-sm font-semibold text-rose-700">
                                    Container tidak bisa dibaca
                                </div>
                                <div className="text-sm text-rose-700/90 mt-1 font-mono break-words">
                                    {speedtest.error}
                                </div>
                            </div>
                        )}

                        {speedtest && !speedtest.error && !speedtest.container && (
                            <div className="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-5">
                                <div className="text-sm font-semibold text-amber-800">
                                    Container speedtest tidak ditemukan
                                </div>
                                <div className="text-sm text-amber-800/90 mt-1 leading-relaxed">
                                    Tidak ada container di router ini yang{" "}
                                    <span className="font-mono">name</span>,{" "}
                                    <span className="font-mono">tag</span>, atau{" "}
                                    <span className="font-mono">root-dir</span>
                                    -nya memuat{" "}
                                    <span className="font-mono font-semibold">
                                        {speedtest.pattern}
                                    </span>
                                    . Sesuaikan{" "}
                                    <span className="font-mono">
                                        MIKROTIK_SPEEDTEST_CONTAINER
                                    </span>{" "}
                                    di <span className="font-mono">.env</span>{" "}
                                    dengan container yang sebenarnya ada.
                                </div>
                            </div>
                        )}

                        {speedtest?.container && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                <div className="px-5 py-4 border-b border-brand-border flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-bold text-slate-900">
                                            {speedtest.container.name ||
                                                speedtest.container.tag ||
                                                "Container"}
                                        </div>
                                        <div className="text-xs text-brand-textSecondary font-mono mt-0.5 break-all">
                                            {speedtest.container.tag}
                                        </div>
                                    </div>
                                    <span
                                        className={`px-3 py-1 rounded-full text-xs font-semibold border ${
                                            speedtest.container.running
                                                ? "bg-emerald-500/10 text-emerald-700 border-emerald-500/30"
                                                : "bg-slate-500/10 text-slate-600 border-slate-400/30"
                                        }`}
                                    >
                                        {speedtest.container.status || "unknown"}
                                    </span>
                                </div>
                                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 px-5 py-4">
                                    {[
                                        {
                                            label: "Interface",
                                            value:
                                                speedtest.container.interface ||
                                                "-",
                                        },
                                        {
                                            label: "Envlist",
                                            value:
                                                speedtest.container.envlist ||
                                                "-",
                                        },
                                        {
                                            label: "Root Dir",
                                            value:
                                                speedtest.container.root_dir ||
                                                "-",
                                        },
                                        {
                                            label: "Logging",
                                            value: speedtest.container.logging
                                                ? "aktif"
                                                : "belum aktif",
                                        },
                                    ].map((item) => (
                                        <div key={item.label}>
                                            <div className="text-[11px] uppercase tracking-wider text-brand-textSecondary font-semibold">
                                                {item.label}
                                            </div>
                                            <div className="text-sm text-slate-900 font-mono mt-1 break-all">
                                                {item.value}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {/* Tanpa logging=yes, tombol Start akan "berhasil"
                                    tapi hasilnya tidak pernah sampai ke aplikasi.
                                    Perintah perbaikannya ditampilkan langsung
                                    supaya tidak perlu ditebak. */}
                                {!speedtest.container.logging && (
                                    <div className="px-5 py-4 bg-amber-500/10 border-t border-amber-500/30 text-sm text-amber-800 leading-relaxed">
                                        Container ini belum{" "}
                                        <span className="font-mono">
                                            logging=yes
                                        </span>
                                        , sehingga hasil speedtest tidak masuk ke{" "}
                                        <span className="font-mono">/log</span>{" "}
                                        dan tidak bisa dibaca aplikasi. Jalankan
                                        di router:
                                        <div className="mt-2 font-mono text-xs bg-brand-bgSecondary border border-brand-border rounded-lg px-3 py-2 text-slate-900 break-all">
                                            /container/set{" "}
                                            {speedtest.container.id} logging=yes
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        {speedtest?.container && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                                <div className="flex-1">
                                    <div className="text-sm font-bold text-slate-900">
                                        Jalankan pengukuran
                                    </div>
                                    {/* Peringatan ini bukan formalitas: speedtest
                                        menarik trafik sebesar-besarnya lewat
                                        gateway yang sama dipakai seluruh kampus,
                                        jadi menjalankannya di jam kuliah terasa
                                        oleh semua pengguna. */}
                                    <div className="text-xs text-brand-textSecondary mt-1 leading-relaxed">
                                        Satu putaran memakai uplink kampus secara
                                        penuh selama 30–90 detik dan akan terasa
                                        oleh pengguna lain. Sebaiknya dijalankan
                                        di luar jam sibuk. Batas tunggu:{" "}
                                        {speedtest.timeout} detik.
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    "Jalankan speedtest di router? Uplink kampus akan terpakai penuh selama 30–90 detik.",
                                                )
                                            ) {
                                                handleStartSpeedtest();
                                            }
                                        }}
                                        disabled={
                                            !canManageDevices ||
                                            speedtestStarting ||
                                            speedtestAction !== null ||
                                            speedtest?.run?.state ===
                                                "running" ||
                                            !speedtest.container.logging
                                        }
                                        className="px-5 py-2.5 rounded-xl text-sm font-semibold bg-brand-primary text-white hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        {speedtest?.run?.state === "running"
                                            ? "Sedang berjalan…"
                                            : speedtestStarting
                                              ? "Memulai…"
                                              : "🚀 Mulai Speedtest"}
                                    </button>

                                    {/* Stop adalah satu-satunya jalan keluar dari
                                        container yang macet tanpa membuka Winbox,
                                        jadi ia tetap bisa ditekan selama container
                                        masih berjalan — termasuk ketika aplikasi
                                        tidak punya catatan putaran apa pun. */}
                                    <button
                                        onClick={handleStopSpeedtest}
                                        disabled={
                                            !canManageDevices ||
                                            speedtestAction !== null ||
                                            !speedtest.container.running
                                        }
                                        className="px-4 py-2.5 rounded-xl text-sm font-semibold border border-rose-500/40 text-rose-700 bg-rose-500/10 hover:bg-rose-500/20 transition disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        {speedtestAction === "stop"
                                            ? "Menghentikan…"
                                            : "⏹ Stop"}
                                    </button>

                                    <button
                                        onClick={() => {
                                            if (
                                                window.confirm(
                                                    "Restart container dan mulai putaran baru? Putaran yang sedang berjalan akan dibatalkan.",
                                                )
                                            ) {
                                                handleRestartSpeedtest();
                                            }
                                        }}
                                        disabled={
                                            !canManageDevices ||
                                            speedtestStarting ||
                                            speedtestAction !== null ||
                                            !speedtest.container.logging
                                        }
                                        className="px-4 py-2.5 rounded-xl text-sm font-semibold border border-brand-border text-slate-700 bg-brand-bgSecondary hover:bg-brand-cardElevated transition disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        {speedtestAction === "restart"
                                            ? "Me-restart…"
                                            : "↻ Restart"}
                                    </button>
                                </div>
                            </div>
                        )}

                        {speedtestActionError && (
                            <div className="bg-rose-500/10 border border-rose-500/30 rounded-2xl p-4">
                                <div className="text-sm font-semibold text-rose-700">
                                    Perintah ke container gagal
                                </div>
                                <div className="text-sm text-rose-700/90 mt-1 leading-relaxed">
                                    {speedtestActionError}
                                </div>
                            </div>
                        )}

                        {!canManageDevices && speedtest?.container && (
                            <div className="text-xs text-brand-textSecondary">
                                Menjalankan speedtest butuh izin{" "}
                                <span className="font-mono">manage devices</span>
                                . Anda masih bisa melihat hasil pengukuran
                                terakhir di bawah.
                            </div>
                        )}

                        {speedtest?.run?.state === "running" && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                                <div className="flex items-center gap-3">
                                    <span className="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse" />
                                    <div className="text-sm font-semibold text-slate-900">
                                        Pengukuran berjalan
                                    </div>
                                    <div className="text-xs text-brand-textSecondary font-mono ml-auto">
                                        {speedtest.run.elapsed ?? 0}s
                                    </div>
                                </div>
                                {/* Stepper: tahap mana yang sedang dikerjakan.
                                    Sumbernya tetap baris /log yang sama, hanya
                                    dibaca sampai tahapnya — bukan tebakan waktu. */}
                                <div className="flex flex-wrap items-center gap-1.5 mt-4">
                                    {SPEEDTEST_STAGES.map((stage, i) => {
                                        const current = speedtestStageIndex(
                                            speedtest.run.progress?.stage,
                                        );
                                        const done = i + 1 < current;
                                        const active = i + 1 === current;

                                        return (
                                            <span
                                                key={stage.id}
                                                className={`px-2.5 py-1 rounded-lg text-[11px] font-semibold border ${
                                                    done
                                                        ? "bg-emerald-500/10 border-emerald-500/30 text-emerald-700"
                                                        : active
                                                          ? "bg-brand-primary/10 border-brand-primary/40 text-brand-primary"
                                                          : "bg-brand-bgSecondary border-brand-border text-brand-textSecondary"
                                                }`}
                                            >
                                                {done ? "✓ " : ""}
                                                {stage.label}
                                            </span>
                                        );
                                    })}
                                </div>

                                {/* Angka yang SUDAH terbaca ditampilkan langsung.
                                    Inilah inti perbedaannya dengan menunggu: hasil
                                    download sudah bisa dibaca operator jauh sebelum
                                    upload selesai. */}
                                <div className="grid grid-cols-3 gap-2 mt-4">
                                    {[
                                        {
                                            label: "Latensi",
                                            value: speedtest.run.progress
                                                ?.ping_ms,
                                            unit: "ms",
                                        },
                                        {
                                            label: "Download",
                                            value: speedtest.run.progress
                                                ?.download_mbps,
                                            unit: "Mbps",
                                        },
                                        {
                                            label: "Upload",
                                            value: speedtest.run.progress
                                                ?.upload_mbps,
                                            unit: "Mbps",
                                        },
                                    ].map((metric) => (
                                        <div
                                            key={metric.label}
                                            className="bg-brand-bgSecondary border border-brand-border rounded-xl p-3"
                                        >
                                            <div className="text-[10px] uppercase tracking-wide text-brand-textSecondary">
                                                {metric.label}
                                            </div>
                                            <div className="text-base font-bold text-slate-900 mt-0.5">
                                                {speedtestHasNumber(
                                                    metric.value,
                                                ) ? (
                                                    <>
                                                        {metric.value}
                                                        <span className="text-[10px] font-medium text-brand-textSecondary ml-1">
                                                            {metric.unit}
                                                        </span>
                                                    </>
                                                ) : (
                                                    // Titik-titik, bukan 0: angkanya
                                                    // belum ada, dan 0 Mbps punya arti
                                                    // sendiri yang salah di sini.
                                                    <span className="text-brand-textSecondary">
                                                        …
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {/* Fase download dan upload CLI Ookla berjalan
                                    dengan --progress=no: belasan detik tanpa satu
                                    baris pun masuk ke /log. Tanpa keterangan ini,
                                    jeda itu terlihat persis seperti putaran macet
                                    dan operator akan menekan Stop pada pengukuran
                                    yang sebenarnya sedang berjalan normal. */}
                                {(speedtest.run.quiet_for ?? 0) >= 8 && (
                                    <div className="text-[11px] text-brand-textSecondary mt-3 leading-relaxed">
                                        Belum ada baris baru di{" "}
                                        <span className="font-mono">/log</span>{" "}
                                        selama {speedtest.run.quiet_for} detik.
                                        Ini normal selama fase download dan
                                        upload — keduanya memang tidak mencetak
                                        apa pun sampai selesai.
                                    </div>
                                )}

                                {/* Baris log mentah tetap disediakan, tapi kini
                                    sebagai lampiran — bukan satu-satunya jendela
                                    ke proses yang sedang berjalan. */}
                                {(speedtest.run.lines ?? []).length > 0 && (
                                    <details className="mt-3">
                                        <summary className="text-xs text-brand-textSecondary cursor-pointer hover:text-slate-700">
                                            Lihat baris /log mentah (
                                            {(speedtest.run.lines ?? []).length})
                                        </summary>
                                        <pre className="mt-2 max-h-48 overflow-auto bg-brand-bgSecondary border border-brand-border rounded-xl p-3 text-[11px] font-mono text-slate-700 whitespace-pre-wrap">
                                            {(speedtest.run.lines ?? []).join(
                                                "\n",
                                            )}
                                        </pre>
                                    </details>
                                )}
                            </div>
                        )}

                        {/* Putaran yang dibatalkan operator: bukan kegagalan router,
                            jadi warnanya netral — tapi baris log yang sudah terkumpul
                            tetap ditampilkan, karena biasanya justru itu alasannya
                            dihentikan. */}
                        {speedtest?.run?.state === "stopped" && (
                            <div className="bg-brand-card border border-amber-500/30 rounded-2xl p-5">
                                <div className="text-sm font-semibold text-amber-700">
                                    Pengukuran dihentikan
                                </div>
                                <div className="text-sm text-brand-textSecondary mt-1 leading-relaxed">
                                    {speedtest.run.error ||
                                        "Putaran dihentikan sebelum hasilnya lengkap, jadi tidak ada angka yang disimpan."}
                                </div>
                                {(speedtest.run.lines ?? []).length > 0 && (
                                    <pre className="mt-3 max-h-48 overflow-auto bg-brand-bgSecondary border border-brand-border rounded-xl p-3 text-[11px] font-mono text-slate-700 whitespace-pre-wrap">
                                        {(speedtest.run.lines ?? []).join("\n")}
                                    </pre>
                                )}
                            </div>
                        )}

                        {speedtest?.run?.state === "failed" && (
                            <div className="bg-rose-500/10 border border-rose-500/30 rounded-2xl p-5">
                                <div className="text-sm font-semibold text-rose-700">
                                    Pengukuran gagal
                                </div>
                                <div className="text-sm text-rose-700/90 mt-1 leading-relaxed">
                                    {speedtest.run.error}
                                </div>
                                {/* Angka yang sempat terukur sebelum putaran mati
                                    ikut ditampilkan: "download 94 Mbps lalu gagal
                                    di upload" menunjuk ke masalah yang sama sekali
                                    berbeda dari "gagal sejak awal". */}
                                {(speedtestHasNumber(
                                    speedtest.run.progress?.download_mbps,
                                ) ||
                                    speedtestHasNumber(
                                        speedtest.run.progress?.ping_ms,
                                    )) && (
                                    <div className="text-xs text-rose-700/90 mt-2">
                                        Sempat terukur sebelum berhenti:{" "}
                                        <span className="font-mono">
                                            {speedtestHasNumber(
                                                speedtest.run.progress?.ping_ms,
                                            )
                                                ? `latensi ${speedtest.run.progress.ping_ms} ms`
                                                : null}
                                            {speedtestHasNumber(
                                                speedtest.run.progress
                                                    ?.download_mbps,
                                            )
                                                ? ` · download ${speedtest.run.progress.download_mbps} Mbps`
                                                : null}
                                        </span>
                                        . Angka separuh jalan tidak disimpan ke
                                        database.
                                    </div>
                                )}
                                {(speedtest.run.lines ?? []).length > 0 && (
                                    <pre className="mt-3 max-h-64 overflow-auto bg-brand-bgSecondary border border-brand-border rounded-xl p-3 text-[11px] font-mono text-slate-700 whitespace-pre-wrap">
                                        {(speedtest.run.lines ?? []).join("\n")}
                                    </pre>
                                )}
                            </div>
                        )}

                        {speedtest?.run?.state === "done" &&
                            speedtest.run.result && (
                                <div className="bg-brand-card border border-emerald-500/30 rounded-2xl p-5">
                                    <div className="text-sm font-bold text-emerald-700">
                                        Hasil pengukuran terbaru
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                                        {[
                                            {
                                                label: "Download",
                                                value: speedtest.run.result
                                                    .download_mbps,
                                                unit: "Mbps",
                                            },
                                            {
                                                label: "Upload",
                                                value: speedtest.run.result
                                                    .upload_mbps,
                                                unit: "Mbps",
                                            },
                                            {
                                                label: "Latensi",
                                                value: speedtest.run.result
                                                    .ping_ms,
                                                unit: "ms",
                                            },
                                        ].map((metric) => (
                                            <div
                                                key={metric.label}
                                                className="bg-brand-bgSecondary border border-brand-border rounded-xl p-4"
                                            >
                                                <div className="text-[11px] uppercase tracking-wider text-brand-textSecondary font-semibold">
                                                    {metric.label}
                                                </div>
                                                <div className="text-2xl font-bold text-slate-900 mt-1">
                                                    {metric.value ?? "–"}
                                                    <span className="text-sm font-medium text-brand-textSecondary ml-1">
                                                        {metric.value != null
                                                            ? metric.unit
                                                            : ""}
                                                    </span>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                                        <div>
                                            <div className="text-[11px] uppercase tracking-wider text-brand-textSecondary font-semibold">
                                                ISP
                                            </div>
                                            <div className="text-sm text-slate-900 mt-1">
                                                {speedtest.run.result.isp ||
                                                    "tidak dilaporkan"}
                                            </div>
                                        </div>
                                        <div>
                                            <div className="text-[11px] uppercase tracking-wider text-brand-textSecondary font-semibold">
                                                Server Uji
                                            </div>
                                            <div className="text-sm text-slate-900 mt-1">
                                                {speedtest.run.result.server ||
                                                    "tidak dilaporkan"}
                                            </div>
                                        </div>
                                    </div>

                                    {/* Keluaran mentah tetap disediakan — parser
                                        ini toleran terhadap tiga format, tapi
                                        satu-satunya cara memastikan angka di atas
                                        benar adalah membandingkannya dengan teks
                                        asli dari container. */}
                                    {(speedtest.run.lines ?? []).length > 0 && (
                                        <details className="mt-4">
                                            <summary className="text-xs text-brand-textSecondary cursor-pointer hover:text-slate-900">
                                                Lihat keluaran mentah container
                                            </summary>
                                            <pre className="mt-2 max-h-64 overflow-auto bg-brand-bgSecondary border border-brand-border rounded-xl p-3 text-[11px] font-mono text-slate-700 whitespace-pre-wrap">
                                                {(
                                                    speedtest.run.lines ?? []
                                                ).join("\n")}
                                            </pre>
                                        </details>
                                    )}
                                </div>
                            )}

                        {/* Hasil tersimpan dari database, bukan dari putaran di
                            layar ini: berguna saat halaman baru dibuka dan
                            operator ingin tahu kapan terakhir uplink diukur tanpa
                            harus mengukur ulang. */}
                        {speedtest?.last_result && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <div className="text-sm font-bold text-slate-900">
                                        Pengukuran tersimpan terakhir
                                    </div>
                                    <div className="text-xs text-brand-textSecondary">
                                        {new Date(
                                            speedtest.last_result.created_at,
                                        ).toLocaleString("id-ID")}
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-x-8 gap-y-2 mt-3 text-sm">
                                    <div>
                                        <span className="text-brand-textSecondary">
                                            Download{" "}
                                        </span>
                                        <span className="font-semibold text-slate-900">
                                            {
                                                speedtest.last_result
                                                    .download_speed_mbps
                                            }{" "}
                                            Mbps
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-brand-textSecondary">
                                            Upload{" "}
                                        </span>
                                        <span className="font-semibold text-slate-900">
                                            {
                                                speedtest.last_result
                                                    .upload_speed_mbps
                                            }{" "}
                                            Mbps
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-brand-textSecondary">
                                            Latensi{" "}
                                        </span>
                                        <span className="font-semibold text-slate-900">
                                            {speedtest.last_result.ping_ms ??
                                                "–"}{" "}
                                            ms
                                        </span>
                                    </div>
                                    <div>
                                        <span className="text-brand-textSecondary">
                                            Router{" "}
                                        </span>
                                        <span className="font-mono text-slate-900">
                                            {speedtest.last_result.router_host}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Padanan tab "Log" di Winbox. Dibuka atas permintaan,
                            bukan otomatis: /log adalah ring buffer yang ratusan
                            barisnya sebagian besar tidak relevan, dan menariknya
                            setiap kali tab dibuka hanya menambah beban router.

                            Yang ditampilkan hanya baris bertopik "container",
                            sehingga keluhan seperti "container tidak jalan" bisa
                            ditelusuri tanpa harus menjalankan speedtest lebih
                            dulu. */}
                        {speedtest && !speedtest.error && (
                            <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div className="text-sm font-bold text-slate-900">
                                            Log container di router
                                        </div>
                                        <div className="text-xs text-brand-textSecondary mt-1">
                                            Baris bertopik{" "}
                                            <span className="font-mono">
                                                container
                                            </span>{" "}
                                            dari{" "}
                                            <span className="font-mono">
                                                /log
                                            </span>
                                            , terbaru di atas.
                                        </div>
                                    </div>
                                    <button
                                        onClick={handleFetchSpeedtestLog}
                                        disabled={speedtestLogLoading}
                                        className="px-4 py-2 rounded-xl text-sm font-semibold border border-brand-border text-slate-700 bg-brand-bgSecondary hover:bg-brand-cardElevated transition disabled:opacity-40 disabled:cursor-not-allowed whitespace-nowrap"
                                    >
                                        {speedtestLogLoading
                                            ? "Mengambil…"
                                            : speedtestLog === null
                                              ? "📄 Tampilkan Log"
                                              : "↻ Muat Ulang"}
                                    </button>
                                </div>

                                {speedtestLog !== null &&
                                    (speedtestLog.length === 0 ? (
                                        <div className="text-xs text-brand-textSecondary mt-4 leading-relaxed">
                                            Tidak ada baris bertopik{" "}
                                            <span className="font-mono">
                                                container
                                            </span>{" "}
                                            di buffer log router saat ini. Itu
                                            wajar kalau container belum pernah
                                            dijalankan sejak router terakhir
                                            reboot, atau kalau buffer sudah
                                            terisi penuh oleh topik lain.
                                        </div>
                                    ) : (
                                        <div className="mt-4 max-h-72 overflow-auto border border-brand-border rounded-xl divide-y divide-brand-border">
                                            {speedtestLog.map((row, i) => (
                                                <div
                                                    key={i}
                                                    className="px-3 py-2 bg-brand-bgSecondary flex flex-wrap gap-x-3 gap-y-0.5 text-[11px]"
                                                >
                                                    <span className="font-mono text-brand-textSecondary shrink-0">
                                                        {row.time}
                                                    </span>
                                                    <span className="font-mono text-slate-700 flex-1 min-w-[12rem] break-all">
                                                        {row.message}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Tab 4: Firewall & NAT */}
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
