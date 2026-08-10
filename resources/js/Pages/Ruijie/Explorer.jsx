import React, { useState, useEffect } from "react";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";

export default function RuijieExplorer({
    auth,
    ruijieConfig,
    connection: initialConnection,
    summary: initialSummary,
    devices: initialDevices,
    wirelessClients: initialClients,
    alarms: initialAlarms,
}) {
    const [activeTab, setActiveTab] = useState("devices");
    const [connection, setConnection] = useState(initialConnection);
    const [summary, setSummary] = useState(initialSummary);
    const [devices, setDevices] = useState(initialDevices || []);
    const [wirelessClients, setWirelessClients] = useState(initialClients || []);
    const [alarms, setAlarms] = useState(initialAlarms || []);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [filterType, setFilterType] = useState("ALL");
    const [searchTerm, setSearchTerm] = useState("");

    const handleRefresh = async () => {
        setIsRefreshing(true);
        try {
            const [connRes, devRes, clientRes, alarmRes] = await Promise.all([
                axios.get(route("ruijie.api.test")),
                axios.get(route("ruijie.api.devices")),
                axios.get(route("ruijie.api.wireless-clients")),
                axios.get(route("ruijie.api.alarms")),
            ]);

            setConnection(connRes.data);
            setDevices(devRes.data);
            setWirelessClients(clientRes.data);
            setAlarms(alarmRes.data);

            const total = devRes.data.length;
            const online = devRes.data.filter((d) => d.status === "online").length;
            const totalClients = devRes.data.reduce((acc, curr) => acc + (curr.client_count || 0), 0);

            setSummary({
                totalDevices: total,
                onlineDevices: online,
                offlineDevices: total - online,
                totalClients: totalClients,
            });
        } catch (e) {
            console.error("Failed refreshing Ruijie Cloud metrics:", e);
        } finally {
            setIsRefreshing(false);
        }
    };

    // Filter devices based on type and search query
    const filteredDevices = devices.filter((d) => {
        const matchesType = filterType === "ALL" || d.type.toLowerCase().includes(filterType.toLowerCase());
        const matchesSearch =
            d.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            d.sn.toLowerCase().includes(searchTerm.toLowerCase()) ||
            d.ip.toLowerCase().includes(searchTerm.toLowerCase()) ||
            d.model.toLowerCase().includes(searchTerm.toLowerCase());
        return matchesType && matchesSearch;
    });

    const getRssiColor = (rssi) => {
        if (rssi >= -55) return "text-emerald-400 font-bold";
        if (rssi >= -70) return "text-amber-400 font-medium";
        return "text-red-400 font-medium";
    };

    return (
        <AuthenticatedLayout user={auth.user}>
            <Head title="Ruijie Reyee Cloud API Integration" />

            <div className="space-y-6">
                {/* Top Header Banner */}
                <div className="bg-brand-card border border-brand-border p-6 rounded-2xl shadow-xl relative overflow-hidden">
                    <div className="absolute top-0 right-0 transform translate-x-8 -translate-y-8 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-5 relative z-10">
                        {/* Left Info */}
                        <div className="flex items-center space-x-4">
                            <div className="h-14 w-14 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-400 text-2xl font-black shrink-0 shadow-lg shadow-cyan-500/10">
                                ☁️
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-bold text-white tracking-wide">
                                        Ruijie Reyee Cloud OpenAPI
                                    </h1>
                                    {connection?.success ? (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                            <span className="h-2 w-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>
                                            LIVE CLOUD API CONNECTED
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/30">
                                            <span className="h-2 w-2 rounded-full bg-amber-400 mr-2"></span>
                                            INVENTORY FALLBACK MODE ({connection?.latency_ms || 0}ms)
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-brand-textSecondary mt-1">
                                    Centralized Cloud Monitoring & Wireless Management for Ruijie Reyee APs, Switches & Gateways
                                </p>
                            </div>
                        </div>

                        {/* Right Actions */}
                        <div className="flex items-center space-x-3">
                            <div className="bg-brand-bg/80 border border-brand-border px-3.5 py-2 rounded-xl text-xs font-mono">
                                <span className="text-brand-textSecondary">APPID: </span>
                                <span className="text-cyan-400 font-bold">{ruijieConfig?.appId || "open2a30c702449b"}</span>
                            </div>

                            <button
                                onClick={handleRefresh}
                                disabled={isRefreshing}
                                className="flex items-center justify-center space-x-2 px-5 py-2.5 bg-cyan-600 hover:bg-cyan-500 text-white rounded-xl text-sm font-semibold transition duration-200 disabled:opacity-50 shadow-lg shadow-cyan-900/30 shrink-0"
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
                                <span>{isRefreshing ? "Syncing Cloud..." : "Sync Cloud API"}</span>
                            </button>
                        </div>
                    </div>
                </div>

                {/* Metric Summary KPI Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl hover:border-cyan-500/40 transition">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">Total Devices</span>
                            <span className="p-2 rounded-lg bg-cyan-500/10 text-cyan-400 text-sm">📡</span>
                        </div>
                        <div className="text-3xl font-extrabold text-white mt-2">{summary?.totalDevices || 0}</div>
                        <div className="text-xs text-cyan-400 mt-1 font-mono">Managed via Ruijie Cloud</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl hover:border-emerald-500/40 transition">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">Online Nodes</span>
                            <span className="p-2 rounded-lg bg-emerald-500/10 text-emerald-400 text-sm">🟢</span>
                        </div>
                        <div className="text-3xl font-extrabold text-emerald-400 mt-2">{summary?.onlineDevices || 0}</div>
                        <div className="text-xs text-emerald-400/80 mt-1 font-mono">Active & Transmitting</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl hover:border-purple-500/40 transition">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">Wi-Fi Clients (STAs)</span>
                            <span className="p-2 rounded-lg bg-purple-500/10 text-purple-400 text-sm">📱</span>
                        </div>
                        <div className="text-3xl font-extrabold text-purple-400 mt-2">{wirelessClients.length}</div>
                        <div className="text-xs text-purple-300 mt-1 font-mono">Connected STAs</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl hover:border-amber-500/40 transition">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">Cloud Alarms</span>
                            <span className="p-2 rounded-lg bg-amber-500/10 text-amber-400 text-sm">⚠️</span>
                        </div>
                        <div className="text-3xl font-extrabold text-amber-400 mt-2">{alarms.length}</div>
                        <div className="text-xs text-amber-300 mt-1 font-mono">Active Events</div>
                    </div>
                </div>

                {/* Diagnostic Authorization Hint Banner */}
                {connection?.hint && (
                    <div className="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-4 flex items-start space-x-3 text-amber-200">
                        <div className="p-2 rounded-xl bg-amber-500/20 text-amber-400 shrink-0 mt-0.5">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div className="space-y-1 text-xs leading-relaxed">
                            <div className="font-bold text-amber-300 text-sm flex items-center space-x-2">
                                <span>Langkah Penting Otorisasi Ruijie Cloud API (Code 5 - Permission Required)</span>
                            </div>
                            <p className="text-amber-200/90">
                                APPID <code className="bg-amber-950/60 px-1.5 py-0.5 rounded font-mono text-amber-300">{connection?.app_id}</code> sudah benar dan diterima oleh Ruijie Cloud, namun **perlu di-Otorisasi (Authorize)** di dalam akun/project Ruijie Cloud Anda:
                            </p>
                            <ol className="list-decimal list-inside space-y-1 text-amber-200/80 pt-1 font-mono">
                                <li>Buka Portal Ruijie Cloud (<a href="https://cloud-as.ruijienetworks.com" target="_blank" rel="noreferrer" className="underline text-cyan-300 font-bold">cloud-as.ruijienetworks.com</a> atau <a href="https://cloud.ruijienetworks.com" target="_blank" rel="noreferrer" className="underline text-cyan-300 font-bold">cloud.ruijienetworks.com</a>)</li>
                                <li>Masuk ke Project Jaringan Anda ➔ Klik <strong>Settings</strong> ➔ <strong>Open API / Authorize APPID</strong></li>
                                <li>Masukkan APPID: <strong className="text-white">{connection?.app_id}</strong> lalu klik <strong>Authorize Project Access</strong></li>
                            </ol>
                        </div>
                    </div>
                )}

                {/* Tab Navigation */}
                <div className="border-b border-brand-border flex overflow-x-auto space-x-2 pb-2">
                    {[
                        { id: "devices", label: "Managed Devices", icon: "📡", count: devices.length },
                        { id: "clients", label: "Connected Wi-Fi Clients", icon: "📱", count: wirelessClients.length },
                        { id: "alarms", label: "Alarms & Events", icon: "🔔", count: alarms.length },
                        { id: "diagnostics", label: "API Authentication Info", icon: "🔑" },
                    ].map((tab) => (
                        <button
                            key={tab.id}
                            onClick={() => setActiveTab(tab.id)}
                            className={`flex items-center space-x-2 px-4 py-2.5 rounded-xl text-sm font-medium transition duration-150 whitespace-nowrap ${
                                activeTab === tab.id
                                    ? "bg-cyan-500/20 text-cyan-300 border border-cyan-500/40"
                                    : "text-brand-textSecondary hover:text-white hover:bg-brand-cardElevated"
                            }`}
                        >
                            <span>{tab.icon}</span>
                            <span>{tab.label}</span>
                            {tab.count !== undefined && (
                                <span className="ml-1 px-2 py-0.5 text-xs rounded-full bg-brand-bgSecondary border border-brand-border text-cyan-400 font-mono">
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* Tab 1: Managed Devices */}
                {activeTab === "devices" && (
                    <div className="space-y-4">
                        {/* Search & Filter Bar */}
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 bg-brand-card border border-brand-border p-4 rounded-2xl">
                            <div className="relative flex-1">
                                <input
                                    type="text"
                                    placeholder="Search by Device Name, SN, IP, or Model..."
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    className="w-full bg-brand-bg border border-brand-border rounded-xl px-4 py-2 text-sm text-white placeholder-brand-textSecondary focus:outline-none focus:border-cyan-500"
                                />
                            </div>

                            <div className="flex items-center space-x-2">
                                {["ALL", "Access Point", "Switch", "Gateway"].map((type) => (
                                    <button
                                        key={type}
                                        onClick={() => setFilterType(type)}
                                        className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                                            filterType === type
                                                ? "bg-cyan-500/20 text-cyan-300 border border-cyan-500/40"
                                                : "bg-brand-bg text-brand-textSecondary border border-brand-border hover:text-white"
                                        }`}
                                    >
                                        {type}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Devices Table */}
                        <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden shadow-lg">
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm text-brand-textSecondary">
                                    <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th className="px-6 py-3">Device Name & Model</th>
                                            <th className="px-6 py-3">Serial Number (SN)</th>
                                            <th className="px-6 py-3">IP & MAC Address</th>
                                            <th className="px-6 py-3">Category</th>
                                            <th className="px-6 py-3">Status</th>
                                            <th className="px-6 py-3">Clients</th>
                                            <th className="px-6 py-3">Data Source</th>
                                            <th className="px-6 py-3">Group Site</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {filteredDevices.length === 0 ? (
                                            <tr>
                                                <td colSpan="8" className="text-center py-10 text-brand-textSecondary">
                                                    No devices match the selected filters.
                                                </td>
                                            </tr>
                                        ) : (
                                            filteredDevices.map((dev, idx) => (
                                                <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                                    <td className="px-6 py-4">
                                                        <div className="font-bold text-white flex items-center space-x-2">
                                                            <span className="text-cyan-400">📡</span>
                                                            <span>{dev.name}</span>
                                                        </div>
                                                        <div className="text-xs text-brand-textSecondary font-mono mt-0.5">{dev.model}</div>
                                                    </td>
                                                    <td className="px-6 py-4 font-mono text-xs font-semibold text-cyan-300">{dev.sn}</td>
                                                    <td className="px-6 py-4">
                                                        <div className="font-mono text-xs font-bold text-white">{dev.ip}</div>
                                                        <div className="font-mono text-[11px] text-brand-textSecondary">{dev.mac}</div>
                                                    </td>
                                                    <td className="px-6 py-4 text-xs font-medium text-slate-300">{dev.type}</td>
                                                    <td className="px-6 py-4">
                                                        <span
                                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold ${
                                                                dev.status === "online"
                                                                    ? "bg-emerald-500/10 text-emerald-400 border border-emerald-500/30"
                                                                    : "bg-red-500/10 text-red-400 border border-red-500/30"
                                                            }`}
                                                        >
                                                            <span
                                                                className={`h-1.5 w-1.5 rounded-full mr-1.5 ${
                                                                    dev.status === "online" ? "bg-emerald-400 animate-pulse" : "bg-red-400"
                                                                }`}
                                                            ></span>
                                                            {dev.status.toUpperCase()}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 font-mono font-bold text-purple-400">{dev.client_count} STAs</td>
                                                    <td className="px-6 py-4">
                                                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-semibold ${
                                                            dev.source === 'Cloud API'
                                                                ? 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/40'
                                                                : dev.source === 'DB Inventory'
                                                                ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40'
                                                                : 'bg-amber-500/20 text-amber-300 border border-amber-500/40'
                                                        }`}>
                                                            {dev.source || 'Demo Fallback'}
                                                        </span>
                                                    </td>
                                                    <td className="px-6 py-4 text-xs text-brand-textSecondary">{dev.group_name}</td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                )}

                {/* Tab 2: Connected Wireless Clients */}
                {activeTab === "clients" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden shadow-lg">
                        <div className="p-5 border-b border-brand-border flex items-center justify-between">
                            <h3 className="font-bold text-white text-base">Connected Wi-Fi Clients (STAs)</h3>
                            <span className="text-xs text-cyan-400 font-mono font-bold">{wirelessClients.length} Active Sessions</span>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-brand-textSecondary">
                                <thead className="bg-brand-bgSecondary text-xs uppercase tracking-wider text-brand-textSecondary">
                                    <tr>
                                        <th className="px-6 py-3">Hostname & MAC</th>
                                        <th className="px-6 py-3">IP Address</th>
                                        <th className="px-6 py-3">SSID</th>
                                        <th className="px-6 py-3">Associated AP</th>
                                        <th className="px-6 py-3">Signal (RSSI)</th>
                                        <th className="px-6 py-3">Band & Speed</th>
                                        <th className="px-6 py-3">Uptime</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border">
                                    {wirelessClients.map((client, idx) => (
                                        <tr key={idx} className="hover:bg-brand-cardElevated/50 transition">
                                            <td className="px-6 py-4">
                                                <div className="font-bold text-white">{client.hostname}</div>
                                                <div className="font-mono text-xs text-brand-textSecondary">{client.mac}</div>
                                            </td>
                                            <td className="px-6 py-4 font-mono text-xs font-bold text-cyan-300">{client.ip}</td>
                                            <td className="px-6 py-4 font-semibold text-emerald-400 text-xs">{client.ssid}</td>
                                            <td className="px-6 py-4 text-xs font-medium text-white">{client.ap_name}</td>
                                            <td className="px-6 py-4 font-mono text-xs">
                                                <span className={getRssiColor(client.rssi)}>{client.rssi} dBm</span>
                                            </td>
                                            <td className="px-6 py-4">
                                                <div className="text-xs text-purple-300 font-semibold">{client.band}</div>
                                                <div className="text-[11px] font-mono text-brand-textSecondary">
                                                    RX: {client.rx_rate} | TX: {client.tx_rate}
                                                </div>
                                            </td>
                                            <td className="px-6 py-4 font-mono text-xs">{client.online_time}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Tab 3: Alarms */}
                {activeTab === "alarms" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden shadow-lg">
                        <div className="p-5 border-b border-brand-border flex items-center justify-between">
                            <h3 className="font-bold text-white text-base">Cloud Security Alarms & System Logs</h3>
                            <span className="text-xs text-amber-400 font-mono font-bold">{alarms.length} Events</span>
                        </div>

                        <div className="p-5 space-y-3">
                            {alarms.map((alarm, idx) => (
                                <div
                                    key={idx}
                                    className="p-4 bg-brand-bgSecondary border border-brand-border rounded-xl flex items-start justify-between space-x-4"
                                >
                                    <div className="flex items-start space-x-3">
                                        <span className="text-xl shrink-0 mt-0.5">
                                            {alarm.level === "INFO" ? "ℹ️" : "⚠️"}
                                        </span>
                                        <div>
                                            <div className="flex items-center space-x-2">
                                                <span className="font-bold text-white text-sm">{alarm.title}</span>
                                                <span className="text-[10px] font-mono px-2 py-0.5 rounded bg-cyan-500/10 text-cyan-300 border border-cyan-500/20">
                                                    {alarm.device_name} ({alarm.device_sn})
                                                </span>
                                            </div>
                                            <p className="text-xs text-brand-textSecondary mt-1">{alarm.detail}</p>
                                        </div>
                                    </div>
                                    <div className="text-xs font-mono text-brand-textMuted shrink-0">{alarm.time}</div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Tab 4: API Diagnostics */}
                {activeTab === "diagnostics" && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="bg-brand-card border border-brand-border p-6 rounded-2xl space-y-4">
                            <h3 className="text-lg font-bold text-white flex items-center space-x-2">
                                <span>🔐</span>
                                <span>Ruijie Cloud API Credentials</span>
                            </h3>

                            <div className="space-y-3 text-sm font-mono">
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between">
                                    <span className="text-brand-textSecondary">APPID:</span>
                                    <span className="text-cyan-400 font-bold">{ruijieConfig?.appId}</span>
                                </div>
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between">
                                    <span className="text-brand-textSecondary">Base URL:</span>
                                    <span className="text-white">{ruijieConfig?.baseUrl}</span>
                                </div>
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between">
                                    <span className="text-brand-textSecondary">OAuth Endpoint:</span>
                                    <span className="text-purple-300 text-xs">/service/api/oauth20/client/access_token</span>
                                </div>
                            </div>
                        </div>

                        <div className="bg-brand-card border border-brand-border p-6 rounded-2xl space-y-4">
                            <h3 className="text-lg font-bold text-white flex items-center space-x-2">
                                <span>⚡</span>
                                <span>Connection Status Diagnostics</span>
                            </h3>

                            <div className="space-y-3 text-sm font-mono">
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between items-center">
                                    <span className="text-brand-textSecondary">Authentication Result:</span>
                                    <span className="px-2.5 py-0.5 rounded text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                                        SUCCESS
                                    </span>
                                </div>
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between">
                                    <span className="text-brand-textSecondary">API Latency:</span>
                                    <span className="text-emerald-400 font-bold">{connection?.latency_ms} ms</span>
                                </div>
                                <div className="p-3 bg-brand-bg rounded-xl border border-brand-border flex justify-between">
                                    <span className="text-brand-textSecondary">Active Token:</span>
                                    <span className="text-cyan-400">{connection?.token || "ruijie_tk_..."}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
