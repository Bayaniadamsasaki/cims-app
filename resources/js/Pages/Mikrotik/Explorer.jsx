import React, { useState, useEffect } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";

export default function MikrotikExplorer({
    auth,
    routerConfig,
    availableRouters = [],
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
    
    // Dynamic loaded state for secondary tabs
    const [natRules, setNatRules] = useState([]);
    const [firewallFilter, setFirewallFilter] = useState([]);
    const [hotspotActive, setHotspotActive] = useState([]);
    const [neighbors, setNeighbors] = useState([]);
    const [logs, setLogs] = useState([]);
    const [loadingTab, setLoadingTab] = useState(false);
    const [isRefreshing, setIsRefreshing] = useState(false);

    const currentHost = selectedHost || routerConfig.host;

    // Helper to get API URL with target host query
    const apiRoute = (routeName, extraParams = {}) => {
        const base = route(routeName, extraParams);
        if (!currentHost) return base;
        const separator = base.includes("?") ? "&" : "?";
        return `${base}${separator}host=${encodeURIComponent(currentHost)}`;
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
                const res = await axios.get(apiRoute("mikrotik.api.hotspot-active"));
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
        <AuthenticatedLayout user={auth.user}>
            <Head title="MikroTik Live API Explorer" />

            <div className="space-y-6">
                {/* Header Banner */}
                <div className="bg-brand-card border border-brand-border p-6 rounded-2xl">
                    <div className="flex flex-col xl:flex-row xl:items-center justify-between gap-5">
                        {/* Left: Title & Status */}
                        <div className="flex items-center space-x-4">
                            <div className="h-12 w-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-emerald-400 font-mono font-bold text-xl shrink-0 shadow-lg shadow-emerald-500/5">
                                μT
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-bold text-white tracking-wide">
                                        MikroTik Live API Explorer
                                    </h1>
                                    {connection?.success ? (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                            <span className="h-2 w-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>
                                            API Connected
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/30">
                                            Offline / Connection Error
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-brand-textSecondary mt-1">
                                    Real-time monitoring & administration direct from RouterOS API (No Winbox required)
                                </p>
                            </div>
                        </div>

                        {/* Right: Router Switcher Card & Refresh Button */}
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                            {/* Styled Router Selector */}
                            <div className="flex items-center space-x-3 bg-brand-bg/90 border border-brand-border/80 px-4 py-2 rounded-xl shadow-inner min-w-[300px]">
                                <div className="flex items-center justify-center h-8 w-8 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-sm shrink-0">
                                    🎛️
                                </div>
                                <div className="flex-1 min-w-0">
                                    <label className="block text-[10px] font-bold uppercase tracking-wider text-brand-textSecondary">
                                        Active MikroTik Device
                                    </label>
                                    <select
                                        value={currentHost}
                                        onChange={(e) => {
                                            const selectedIp = e.target.value;
                                            window.location.href = route("mikrotik.index") + "?host=" + encodeURIComponent(selectedIp);
                                        }}
                                        className="w-full bg-transparent text-white text-xs font-mono font-bold border-0 p-0 focus:ring-0 focus:outline-none cursor-pointer truncate"
                                    >
                                        {(availableRouters || []).map((r) => (
                                            <option key={r.id} value={r.ip} className="bg-slate-900 text-white font-sans py-1.5">
                                                {r.name} ({r.model || 'MikroTik'}) — {r.ip}
                                            </option>
                                        ))}
                                    </select>
                                </div>
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
                                <span>{isRefreshing ? "Refreshing..." : "Refresh Data"}</span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Connection Error Alert */}
                {!connection?.success && (
                    <div className="bg-red-500/10 border border-red-500/30 p-4 rounded-xl text-red-400 flex items-start space-x-3">
                        <svg className="w-6 h-6 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <div>
                            <div className="font-semibold text-white">Router Connection Failed</div>
                            <div className="text-sm mt-1">{connection?.error || "Unable to establish API socket connection to RouterOS."}</div>
                        </div>
                    </div>
                )}

                {/* Navigation Tabs */}
                <div className="border-b border-brand-border flex overflow-x-auto space-x-2 pb-2">
                    {[
                        { id: "overview", label: "System Overview", icon: "📊" },
                        { id: "network", label: "IP & Routing", icon: "🌐" },
                        { id: "firewall", label: "Firewall & NAT", icon: "🛡️" },
                        { id: "hotspot", label: "Hotspot Active", icon: "📡" },
                        { id: "neighbors", label: "Neighbors", icon: "🔗" },
                        { id: "system", label: "DNS & Packages", icon: "📦" },
                        { id: "logs", label: "System Logs", icon: "📜" },
                    ].map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-medium transition duration-150 whitespace-nowrap ${
                                activeTab === tab.id
                                    ? "bg-brand-primary/20 text-emerald-400 border border-emerald-500/30"
                                    : "text-brand-textSecondary hover:text-white hover:bg-brand-cardElevated"
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
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">Router Identity</div>
                                <div className="text-xl font-bold text-white mt-1">{connection?.identity || "-"}</div>
                                <div className="text-xs text-emerald-400 mt-2 font-mono">{connection?.board || "-"} ({connection?.version || "-"})</div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">CPU Utilization</div>
                                <div className="flex items-baseline space-x-2 mt-1">
                                    <span className="text-2xl font-bold text-white">{metrics?.cpu ?? "-"}%</span>
                                </div>
                                <div className="w-full bg-brand-bg rounded-full h-2 mt-3 overflow-hidden">
                                    <div
                                        className={`h-2 rounded-full transition-all duration-500 ${
                                            (metrics?.cpu ?? 0) > 80 ? "bg-red-500" : (metrics?.cpu ?? 0) > 50 ? "bg-amber-500" : "bg-emerald-500"
                                        }`}
                                        style={{ width: `${metrics?.cpu ?? 0}%` }}
                                    ></div>
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">Memory Usage</div>
                                <div className="flex items-baseline space-x-2 mt-1">
                                    <span className="text-2xl font-bold text-white">{metrics?.ram ?? "-"}%</span>
                                </div>
                                <div className="w-full bg-brand-bg rounded-full h-2 mt-3 overflow-hidden">
                                    <div
                                        className="h-2 rounded-full bg-blue-500 transition-all duration-500"
                                        style={{ width: `${metrics?.ram ?? 0}%` }}
                                    ></div>
                                </div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                                <div className="text-xs text-brand-textSecondary uppercase tracking-wider font-semibold">Live Bandwidth</div>
                                <div className="text-sm font-mono mt-2 space-y-1">
                                    <div className="flex justify-between">
                                        <span className="text-emerald-400">RX:</span>
                                        <span className="text-white font-bold">{formatBps(metrics?.rx)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-blue-400">TX:</span>
                                        <span className="text-white font-bold">{formatBps(metrics?.tx)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Additional Metrics Row */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">Uptime</div>
                                    <div className="text-lg font-bold text-white mt-1">{formatUptime(metrics?.uptime)}</div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-emerald-400">⏱️</div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">Temperature</div>
                                    <div className="text-lg font-bold text-white mt-1">{metrics?.temp !== null ? `${metrics?.temp} °C` : "N/A"}</div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-amber-400">🌡️</div>
                            </div>

                            <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex items-center justify-between">
                                <div>
                                    <div className="text-xs text-brand-textSecondary uppercase">Storage Usage</div>
                                    <div className="text-lg font-bold text-white mt-1">{metrics?.storage ?? "-"}%</div>
                                </div>
                                <div className="p-3 bg-brand-cardElevated rounded-xl text-purple-400">💾</div>
                            </div>
                        </div>

                        {/* Interface List */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-white text-base">Router Interfaces</h3>
                                <span className="text-xs text-brand-textSecondary font-mono">{metrics?.interfaces?.length || 0} Interfaces</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">Interface Name</th>
                                            <th className="px-6 py-3">Type</th>
                                            <th className="px-6 py-3">Status</th>
                                            <th className="px-6 py-3">MAC Address</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {metrics?.interfaces?.map((iface, idx) => (
                                            <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                <td className="px-6 py-4 font-mono font-medium text-white">{iface.name}</td>
                                                <td className="px-6 py-4">{iface.type || "-"}</td>
                                                <td className="px-6 py-4">
                                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${
                                                        iface.status === "up" ? "bg-emerald-500/10 text-emerald-400" : "bg-red-500/10 text-red-400"
                                                    }`}>
                                                        {iface.status.toUpperCase()}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 font-mono text-xs text-brand-textSecondary">{iface.mac || "-"}</td>
                                            </tr>
                                        ))}
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
                                <h3 className="font-bold text-white text-base">IP Addresses (/ip/address)</h3>
                                <span className="text-xs text-brand-textSecondary font-mono">{ipAddresses.length} Addresses</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">IP Address / Prefix</th>
                                            <th className="px-6 py-3">Network</th>
                                            <th className="px-6 py-3">Interface</th>
                                            <th className="px-6 py-3">Type</th>
                                            <th className="px-6 py-3">Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {ipAddresses.map((ip, idx) => (
                                            <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                <td className="px-6 py-4 font-mono font-bold text-emerald-400">{ip.address}</td>
                                                <td className="px-6 py-4 font-mono text-xs">{ip.network || "-"}</td>
                                                <td className="px-6 py-4 font-mono font-medium text-white">{ip.interface}</td>
                                                <td className="px-6 py-4 text-xs">
                                                    {ip.dynamic ? (
                                                        <span className="px-2 py-0.5 rounded bg-blue-500/10 text-blue-400">Dynamic</span>
                                                    ) : (
                                                        <span className="px-2 py-0.5 rounded bg-purple-500/10 text-purple-400">Static</span>
                                                    )}
                                                </td>
                                                <td className="px-6 py-4 text-xs italic">{ip.comment || "-"}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Routing Table */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-white text-base">Routing Table (/ip/route)</h3>
                                <span className="text-xs text-brand-textSecondary font-mono">{routes.length} Routes</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">Dst. Address</th>
                                            <th className="px-6 py-3">Gateway</th>
                                            <th className="px-6 py-3">Distance</th>
                                            <th className="px-6 py-3">Flags</th>
                                            <th className="px-6 py-3">Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {routes.map((r, idx) => (
                                            <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                <td className="px-6 py-4 font-mono font-bold text-white">{r.dst_address}</td>
                                                <td className="px-6 py-4 font-mono text-emerald-400">{r.gateway || "connected"}</td>
                                                <td className="px-6 py-4 font-mono text-xs">{r.distance ?? "-"}</td>
                                                <td className="px-6 py-4">
                                                    <div className="flex space-x-1 text-xs font-mono">
                                                        {r.active && <span className="px-1.5 py-0.5 bg-emerald-500/10 text-emerald-400 rounded">A</span>}
                                                        {r.static && <span className="px-1.5 py-0.5 bg-blue-500/10 text-blue-400 rounded">S</span>}
                                                        {r.dynamic && <span className="px-1.5 py-0.5 bg-amber-500/10 text-amber-400 rounded">D</span>}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 text-xs italic">{r.comment || "-"}</td>
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
                            <div className="p-12 text-center text-brand-textSecondary">Loading Firewall & NAT Rules...</div>
                        ) : (
                            <>
                                {/* NAT Rules */}
                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                        <h3 className="font-bold text-white text-base">NAT Rules (/ip/firewall/nat)</h3>
                                        <span className="text-xs text-brand-textSecondary font-mono">{natRules.length} Rules</span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-left text-sm text-brand-textSecondary">
                                            <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                <tr>
                                                    <th className="px-6 py-3">Status</th>
                                                    <th className="px-6 py-3">Chain</th>
                                                    <th className="px-6 py-3">Action</th>
                                                    <th className="px-6 py-3">Protocol / Port</th>
                                                    <th className="px-6 py-3">To Addresses / Ports</th>
                                                    <th className="px-6 py-3">Out Interface</th>
                                                    <th className="px-6 py-3">Comment</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-brand-border">
                                                {natRules.map((r, idx) => (
                                                    <tr key={idx} className={`hover:bg-brand-cardElevated/50 transition ${r.disabled ? "opacity-45 bg-red-950/10" : ""}`}>
                                                        <td className="px-6 py-4">
                                                            {r.disabled ? (
                                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/30">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-red-400 mr-1.5"></span>
                                                                    Disabled (Non-aktif)
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                                    Enabled (Aktif)
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-6 py-4 font-mono text-purple-400">{r.chain}</td>
                                                        <td className="px-6 py-4 font-mono font-bold text-white">{r.action}</td>
                                                        <td className="px-6 py-4 font-mono text-xs">{r.protocol} {r.dst_port ? `:${r.dst_port}` : ""}</td>
                                                        <td className="px-6 py-4 font-mono text-emerald-400">{r.to_addresses || "-"} {r.to_ports ? `:${r.to_ports}` : ""}</td>
                                                        <td className="px-6 py-4 font-mono text-xs">{r.out_interface || "-"}</td>
                                                        <td className="px-6 py-4 text-xs italic text-brand-textSecondary">{r.comment || "-"}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {/* Firewall Filter */}
                                <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                                    <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                        <h3 className="font-bold text-white text-base">Firewall Filter Rules (/ip/firewall/filter)</h3>
                                        <span className="text-xs text-brand-textSecondary font-mono">{firewallFilter.length} Rules</span>
                                    </div>
                                    {firewallFilter.length === 0 ? (
                                        <div className="p-8 text-center text-brand-textSecondary text-sm">No custom firewall filter rules configured.</div>
                                    ) : (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left text-sm text-brand-textSecondary">
                                                <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                                    <tr>
                                                        <th className="px-6 py-3">Status</th>
                                                        <th className="px-6 py-3">Chain</th>
                                                        <th className="px-6 py-3">Action</th>
                                                        <th className="px-6 py-3">Src / Dst Address</th>
                                                        <th className="px-6 py-3">Bytes / Packets</th>
                                                        <th className="px-6 py-3">Comment</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-brand-border">
                                                    {firewallFilter.map((r, idx) => (
                                                        <tr key={idx} className={`hover:bg-brand-cardElevated/50 transition ${r.disabled ? "opacity-45 bg-red-950/10" : ""}`}>
                                                            <td className="px-6 py-4">
                                                                {r.disabled ? (
                                                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/30">
                                                                        <span className="h-1.5 w-1.5 rounded-full bg-red-400 mr-1.5"></span>
                                                                        Disabled (Non-aktif)
                                                                    </span>
                                                                ) : (
                                                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                                                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 mr-1.5"></span>
                                                                        Enabled (Aktif)
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="px-6 py-4 font-mono text-purple-400">{r.chain}</td>
                                                            <td className="px-6 py-4 font-mono font-bold text-white">{r.action}</td>
                                                            <td className="px-6 py-4 font-mono text-xs">{r.src_address || "*"} → {r.dst_address || "*"}</td>
                                                            <td className="px-6 py-4 font-mono text-xs">{r.bytes} B ({r.packets} pkts)</td>
                                                            <td className="px-6 py-4 text-xs italic text-brand-textSecondary">{r.comment || "-"}</td>
                                                        </tr>
                                                    ))}
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
                            <h3 className="font-bold text-white text-base">Active Hotspot Users (/ip/hotspot/active)</h3>
                            <span className="text-xs text-brand-textSecondary font-mono">{hotspotActive.length} Active Users</span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">Loading Hotspot Users...</div>
                        ) : hotspotActive.length === 0 ? (
                            <div className="p-8 text-center text-brand-textSecondary text-sm">No active hotspot user sessions currently online.</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">Username</th>
                                            <th className="px-6 py-3">IP Address</th>
                                            <th className="px-6 py-3">MAC Address</th>
                                            <th className="px-6 py-3">Uptime</th>
                                            <th className="px-6 py-3">Traffic (In / Out)</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {hotspotActive.map((u, idx) => (
                                            <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                <td className="px-6 py-4 font-bold text-emerald-400">{u.user}</td>
                                                <td className="px-6 py-4 font-mono text-white">{u.address}</td>
                                                <td className="px-6 py-4 font-mono text-xs">{u.mac}</td>
                                                <td className="px-6 py-4 font-mono text-xs">{u.uptime}</td>
                                                <td className="px-6 py-4 font-mono text-xs">
                                                    {(u.bytes_in / 1024 / 1024).toFixed(2)} MB / {(u.bytes_out / 1024 / 1024).toFixed(2)} MB
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
                            <h3 className="font-bold text-white text-base">Neighbor Discovery (/ip/neighbor)</h3>
                            <span className="text-xs text-brand-textSecondary font-mono">{neighbors.length} Discovered Nodes</span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">Discovering Neighbors...</div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">Identity / Hostname</th>
                                            <th className="px-6 py-3">IP Address</th>
                                            <th className="px-6 py-3">Platform / Board</th>
                                            <th className="px-6 py-3">Via Interface</th>
                                            <th className="px-6 py-3">MAC Address</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {neighbors.map((n, idx) => (
                                            <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                <td className="px-6 py-4 font-bold text-white">{n.identity || "Unknown Node"}</td>
                                                <td className="px-6 py-4 font-mono text-emerald-400">{n.address || "-"}</td>
                                                <td className="px-6 py-4 text-xs">{n.platform || "MikroTik"} {n.board ? `(${n.board})` : ""}</td>
                                                <td className="px-6 py-4 font-mono text-xs text-purple-400">{n.interface}</td>
                                                <td className="px-6 py-4 font-mono text-xs">{n.mac || "-"}</td>
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
                                <h3 className="font-bold text-white text-base">Router User Accounts</h3>
                            </div>
                            <div className="p-5 space-y-3">
                                {users.map((u, idx) => (
                                    <div key={idx} className="flex items-center justify-between p-3 bg-brand-bgSecondary rounded-xl">
                                        <div>
                                            <div className="font-bold text-white">{u.name}</div>
                                            <div className="text-xs text-brand-textSecondary font-mono">Group: {u.group}</div>
                                        </div>
                                        <span className="px-2.5 py-1 rounded bg-emerald-500/10 text-emerald-400 text-xs font-semibold">Active</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* DNS Config */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border">
                                <h3 className="font-bold text-white text-base">DNS Configuration</h3>
                            </div>
                            <div className="p-5 space-y-4 text-sm text-brand-textSecondary">
                                <div className="flex justify-between border-b border-brand-border/50 pb-2">
                                    <span>DNS Servers:</span>
                                    <span className="font-mono font-bold text-emerald-400">{dns.servers || "-"}</span>
                                </div>
                                <div className="flex justify-between border-b border-brand-border/50 pb-2">
                                    <span>Allow Remote Requests:</span>
                                    <span className="font-mono text-white">{dns.allow_remote ? "Yes" : "No"}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>DNS Cache Usage:</span>
                                    <span className="font-mono text-purple-400">{dns.cache_used ?? 0} / {dns.cache_size ?? 0}</span>
                                </div>
                            </div>
                        </div>

                        {/* System Packages */}
                        <div className="lg:col-span-2 bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                            <div className="p-5 border-b border-brand-border flex items-center justify-between">
                                <h3 className="font-bold text-white text-base">Installed Packages (/system/package)</h3>
                                <span className="text-xs text-brand-textSecondary font-mono">{packages.length} Packages</span>
                            </div>
                            <div className="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                {packages.map((pkg, idx) => (
                                    <div
                                        key={idx}
                                        className={`p-3 rounded-xl border ${
                                            pkg.disabled
                                                ? "bg-brand-bg/50 border-brand-border text-brand-textSecondary opacity-50"
                                                : "bg-brand-bgSecondary border-brand-border text-white"
                                        }`}
                                    >
                                        <div className="font-bold text-sm">{pkg.name}</div>
                                        <div className="text-xs font-mono text-emerald-400 mt-1">v{pkg.version}</div>
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
                            <h3 className="font-bold text-white text-base">RouterOS System Logs (/log)</h3>
                            <span className="text-xs text-brand-textSecondary font-mono">Last 50 Entries</span>
                        </div>
                        {loadingTab ? (
                            <div className="p-12 text-center text-brand-textSecondary">Fetching RouterOS Logs...</div>
                        ) : (
                            <div className="p-4 bg-brand-bg font-mono text-xs space-y-1.5 max-h-[500px] overflow-y-auto">
                                {logs.map((log, idx) => (
                                    <div key={idx} className="flex space-x-3 hover:bg-brand-card/50 p-1 rounded transition">
                                        <span className="text-brand-textSecondary shrink-0">{log.time}</span>
                                        <span className="text-purple-400 shrink-0">[{log.topics}]</span>
                                        <span className="text-white">{log.message}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
