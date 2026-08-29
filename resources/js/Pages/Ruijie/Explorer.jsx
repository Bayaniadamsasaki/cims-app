import React, { useState } from "react";
import CimsLayout from "@/Layouts/CimsLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";

/**
 * Kosakata status di halaman ini berasal dari dua sumber nyata: Ruijie Cloud
 * melaporkan `online`/`offline`, sedangkan perangkat yang hanya ada di inventaris
 * CIMS memakai hasil MonitoringService (`online`, `degraded`, `unreachable`,
 * `error`). Status yang belum dilaporkan siapa pun menjadi `unknown`. Tidak ada
 * status hasil karangan.
 */
const STATUS_STYLE = {
    online: {
        label: "ONLINE",
        chip: "bg-emerald-500/10 text-emerald-700 border-emerald-500/30",
        dot: "bg-emerald-400 animate-pulse",
    },
    degraded: {
        label: "DEGRADED",
        chip: "bg-amber-500/10 text-amber-700 border-amber-500/30",
        dot: "bg-amber-400",
    },
    unreachable: {
        label: "UNREACHABLE",
        chip: "bg-red-500/10 text-red-700 border-red-500/30",
        dot: "bg-red-400",
    },
    error: {
        label: "MONITORING ERROR",
        chip: "bg-rose-500/10 text-rose-700 border-rose-500/30",
        dot: "bg-rose-500",
    },
    offline: {
        label: "OFFLINE",
        chip: "bg-red-500/10 text-red-700 border-red-500/30",
        dot: "bg-red-400",
    },
    unknown: {
        label: "NO DATA",
        chip: "bg-slate-100 text-slate-600 border-slate-200",
        dot: "bg-slate-400",
    },
};

const statusStyleOf = (status) => STATUS_STYLE[status] ?? STATUS_STYLE.unknown;

/** Field yang tidak dilaporkan sumbernya dirender sebagai "—", bukan diisi tebakan. */
const NoData = () => <span className="text-brand-textMuted">—</span>;

const show = (value) => (value === null || value === undefined || value === "" ? <NoData /> : value);

const includesTerm = (value, term) =>
    String(value ?? "")
        .toLowerCase()
        .includes(term);

/**
 * Alarm dikembalikan apa adanya oleh Ruijie Cloud, sehingga nama fieldnya bisa
 * berbeda antar versi API. `pick` mencoba beberapa kemungkinan nama dan
 * mengembalikan `null` bila tidak ada yang dilaporkan — tidak menebak isinya.
 */
const pick = (source, ...keys) => {
    for (const key of keys) {
        const value = source?.[key];
        if (value !== null && value !== undefined && value !== "") {
            return value;
        }
    }

    return null;
};

/** Warna level alarm; level yang tidak dikenal tetap netral, bukan dinaikkan jadi kritis. */
const ALARM_LEVEL_STYLE = {
    critical: "bg-red-500/10 text-red-700 border-red-500/30",
    urgent: "bg-red-500/10 text-red-700 border-red-500/30",
    major: "bg-orange-500/10 text-orange-700 border-orange-500/30",
    warning: "bg-amber-500/10 text-amber-700 border-amber-500/30",
    minor: "bg-amber-500/10 text-amber-700 border-amber-500/30",
    info: "bg-cyan-500/10 text-cyan-700 border-cyan-500/30",
};

const alarmLevelStyle = (level) =>
    ALARM_LEVEL_STYLE[String(level ?? "").toLowerCase()] ?? "bg-slate-100 text-slate-600 border-slate-200";


export default function RuijieExplorer({
    ruijieConfig,
    connection: initialConnection,
    summary: initialSummary,
    devices: initialDevices,
    wirelessClients: initialClients,
    alarms: initialAlarms,
}) {
    const [activeTab, setActiveTab] = useState("devices");
    const [connection, setConnection] = useState(initialConnection);
    const [summary, setSummary] = useState(initialSummary || {});
    const [devices, setDevices] = useState(initialDevices || []);
    const [wirelessClients, setWirelessClients] = useState(initialClients || []);
    const [alarms, setAlarms] = useState(initialAlarms || []);
    const [isRefreshing, setIsRefreshing] = useState(false);
    const [refreshError, setRefreshError] = useState(null);
    const [filterType, setFilterType] = useState("ALL");
    const [searchTerm, setSearchTerm] = useState("");

    const cloudConnected = Boolean(connection?.success);

    const handleRefresh = async () => {
        setIsRefreshing(true);
        setRefreshError(null);

        try {
            const [connRes, devRes, clientRes, alarmRes] = await Promise.all([
                axios.get(route("ruijie.api.test")),
                axios.get(route("ruijie.api.devices")),
                axios.get(route("ruijie.api.wireless-clients")),
                axios.get(route("ruijie.api.alarms")),
            ]);

            const rows = devRes.data || [];

            setConnection(connRes.data);
            setDevices(rows);
            setWirelessClients(clientRes.data || []);
            setAlarms(alarmRes.data || []);

            // Rekap mengikuti controller: hanya status dan jumlah klien yang
            // benar-benar dilaporkan yang dihitung. Perangkat tanpa laporan
            // status tidak dianggap offline, dan client_count yang kosong tidak
            // dijadikan nol.
            const countStatus = (statuses) => rows.filter((d) => statuses.includes(d.status)).length;
            const online = countStatus(["online"]);
            const offline = countStatus(["offline", "unreachable"]);
            const degraded = countStatus(["degraded"]);
            const monitoringError = countStatus(["error"]);
            const reportedClients = rows.map((d) => d.client_count).filter((c) => c !== null && c !== undefined);

            setSummary({
                totalDevices: rows.length,
                onlineDevices: online,
                offlineDevices: offline,
                degradedDevices: degraded,
                errorDevices: monitoringError,
                unknownDevices: Math.max(0, rows.length - online - offline - degraded - monitoringError),
                totalClients: reportedClients.length > 0 ? reportedClients.reduce((acc, c) => acc + Number(c), 0) : null,
            });
        } catch (e) {
            setRefreshError(e?.response?.data?.message || e?.message || "Permintaan ke Ruijie Cloud gagal.");
        } finally {
            setIsRefreshing(false);
        }
    };

    // Pencarian dan filter menoleransi field kosong: perangkat yang tidak
    // melaporkan tipe tidak bisa diklaim cocok dengan filter kategori.
    const term = searchTerm.trim().toLowerCase();
    const filteredDevices = devices.filter((d) => {
        const matchesType = filterType === "ALL" || includesTerm(d.type, filterType.toLowerCase());
        const matchesSearch = term === "" || [d.name, d.sn, d.ip, d.model].some((field) => includesTerm(field, term));

        return matchesType && matchesSearch;
    });

    const getRssiColor = (rssi) => {
        if (rssi >= -55) return "text-emerald-700 font-bold";
        if (rssi >= -70) return "text-amber-700 font-medium";
        return "text-red-700 font-medium";
    };

    return (
        <CimsLayout>
            <Head title="Ruijie Reyee Cloud API Integration" />

            <div className="space-y-6">
                {/* Header + status koneksi nyata */}
                <div className="bg-brand-card border border-brand-border p-6 rounded-2xl shadow-xl relative overflow-hidden">
                    <div className="absolute top-0 right-0 transform translate-x-8 -translate-y-8 w-64 h-64 bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

                    <div className="flex flex-col lg:flex-row lg:items-center justify-between gap-5 relative z-10">
                        <div className="flex items-center space-x-4">
                            <div className="h-14 w-14 rounded-2xl bg-cyan-500/10 border border-cyan-500/30 flex items-center justify-center text-cyan-700 text-2xl font-black shrink-0 shadow-lg shadow-cyan-500/10">
                                ☁️
                            </div>
                            <div>
                                <div className="flex flex-wrap items-center gap-3">
                                    <h1 className="text-2xl font-bold text-slate-900 tracking-wide">
                                        Ruijie Reyee Cloud OpenAPI
                                    </h1>
                                    {cloudConnected ? (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-700 border border-emerald-500/30">
                                            <span className="h-2 w-2 rounded-full bg-emerald-400 mr-2 animate-pulse"></span>
                                            CLOUD API TERHUBUNG
                                            {connection?.latency_ms ? ` (${connection.latency_ms} ms)` : ""}
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-700 border border-amber-500/30">
                                            <span className="h-2 w-2 rounded-full bg-amber-400 mr-2"></span>
                                            CLOUD API TIDAK TERHUBUNG — HANYA DATA INVENTARIS
                                        </span>
                                    )}
                                </div>
                                <p className="text-sm text-brand-textSecondary mt-1">
                                    Data perangkat, klien Wi-Fi, dan alarm hanya ditampilkan bila dilaporkan Ruijie
                                    Cloud atau hasil monitoring CIMS.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-center space-x-3">
                            <div className="bg-brand-bg/80 border border-brand-border px-3.5 py-2 rounded-xl text-xs font-mono">
                                <span className="text-brand-textSecondary">APPID: </span>
                                <span className="text-cyan-700 font-bold">
                                    {ruijieConfig?.appId || "belum dikonfigurasi"}
                                </span>
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
                                    aria-hidden="true"
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

                {/* Kegagalan sinkronisasi ditampilkan, tidak disembunyikan di console. */}
                {refreshError && (
                    <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                        <span className="font-bold">Sinkronisasi Ruijie Cloud gagal: </span>
                        {refreshError}
                    </div>
                )}

                {/* Pesan kegagalan koneksi dari server */}
                {!cloudConnected && connection?.message && (
                    <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700">
                        <span className="font-bold">Status koneksi: </span>
                        {connection.message}
                        {connection?.error_code ? (
                            <span className="ml-1 font-mono text-xs">(code {connection.error_code})</span>
                        ) : null}
                    </div>
                )}

                {/* KPI: setiap angka menyebut asal datanya */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">
                                Total Devices
                            </span>
                            <span className="p-2 rounded-lg bg-cyan-500/10 text-cyan-700 text-sm">📡</span>
                        </div>
                        <div className="text-3xl font-extrabold text-slate-900 mt-2">{summary?.totalDevices ?? 0}</div>
                        <div className="text-xs text-cyan-700 mt-1 font-mono">Cloud API + inventaris CIMS</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">
                                Online Nodes
                            </span>
                            <span className="p-2 rounded-lg bg-emerald-500/10 text-emerald-700 text-sm">🟢</span>
                        </div>
                        <div className="text-3xl font-extrabold text-emerald-700 mt-2">
                            {summary?.onlineDevices ?? 0}
                        </div>
                        <div className="text-xs text-brand-textMuted mt-1 font-mono">
                            {summary?.offlineDevices ?? 0} offline · {summary?.degradedDevices ?? 0} degraded ·{" "}
                            {summary?.errorDevices ?? 0} error · {summary?.unknownDevices ?? 0} belum dilaporkan
                        </div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">
                                Wi-Fi Clients (STAs)
                            </span>
                            <span className="p-2 rounded-lg bg-purple-500/10 text-purple-700 text-sm">📱</span>
                        </div>
                        {/* Daftar klien hanya bisa datang dari Cloud API. */}
                        <div className="text-3xl font-extrabold text-purple-700 mt-2">
                            {cloudConnected ? wirelessClients.length : <span className="text-slate-400">—</span>}
                        </div>
                        <div className="text-xs text-brand-textMuted mt-1 font-mono">
                            {cloudConnected ? "Sesi aktif dilaporkan Cloud" : "Butuh koneksi Cloud API"}
                        </div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-5 rounded-2xl">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-brand-textSecondary uppercase tracking-wider font-bold">
                                Cloud Alarms
                            </span>
                            <span className="p-2 rounded-lg bg-amber-500/10 text-amber-700 text-sm">⚠️</span>
                        </div>
                        <div className="text-3xl font-extrabold text-amber-700 mt-2">
                            {cloudConnected ? alarms.length : <span className="text-slate-400">—</span>}
                        </div>
                        <div className="text-xs text-brand-textMuted mt-1 font-mono">
                            {cloudConnected ? "Event aktif dari Cloud" : "Butuh koneksi Cloud API"}
                        </div>
                    </div>
                </div>

                {/* Petunjuk otorisasi — muncul hanya bila server benar-benar mengirim hint */}
                {connection?.hint && (
                    <div className="bg-amber-500/10 border border-amber-500/30 rounded-2xl p-4 flex items-start space-x-3 text-amber-700">
                        <div className="p-2 rounded-xl bg-amber-500/20 text-amber-700 shrink-0 mt-0.5">
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    strokeWidth="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                                />
                            </svg>
                        </div>
                        <div className="space-y-1 text-xs leading-relaxed">
                            <div className="font-bold text-amber-700 text-sm">
                                Otorisasi Ruijie Cloud API diperlukan
                                {connection?.error_code ? ` (code ${connection.error_code})` : ""}
                            </div>
                            <p className="text-amber-700/90">{connection.hint}</p>
                            <ol className="list-decimal list-inside space-y-1 text-amber-700/80 pt-1">
                                <li>
                                    Buka Portal Ruijie Cloud (
                                    <a
                                        href="https://cloud-as.ruijienetworks.com"
                                        target="_blank"
                                        rel="noreferrer"
                                        className="underline text-cyan-700 font-bold"
                                    >
                                        cloud-as.ruijienetworks.com
                                    </a>
                                    )
                                </li>
                                <li>
                                    Masuk ke Project jaringan ➔ <strong>Settings</strong> ➔{" "}
                                    <strong>Open API / Authorize APPID</strong>
                                </li>
                                <li>
                                    Masukkan APPID{" "}
                                    <strong className="text-slate-900 font-mono">{connection?.app_id}</strong> lalu klik{" "}
                                    <strong>Authorize Project Access</strong>
                                </li>
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
                                    ? "bg-cyan-500/20 text-cyan-700 border border-cyan-500/40"
                                    : "text-brand-textSecondary hover:text-slate-900 hover:bg-brand-cardElevated"
                            }`}
                        >
                            <span aria-hidden="true">{tab.icon}</span>
                            <span>{tab.label}</span>
                            {tab.count !== undefined && (
                                <span className="ml-1 px-2 py-0.5 text-xs rounded-full bg-brand-bgSecondary border border-brand-border text-cyan-700 font-mono">
                                    {tab.count}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                {/* ============ TAB: MANAGED DEVICES ============ */}
                {activeTab === "devices" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-4 border-b border-brand-border flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div className="flex-1 max-w-md">
                                <label htmlFor="ruijie-search" className="sr-only">
                                    Cari perangkat Ruijie
                                </label>
                                <input
                                    id="ruijie-search"
                                    type="search"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Cari nama, serial number, IP, atau model…"
                                    className="w-full px-3.5 py-2 rounded-xl bg-brand-bg border border-brand-border text-sm text-slate-900 placeholder:text-brand-textMuted focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <label htmlFor="ruijie-type" className="text-xs font-semibold text-brand-textSecondary">
                                    Tipe
                                </label>
                                <select
                                    id="ruijie-type"
                                    value={filterType}
                                    onChange={(e) => setFilterType(e.target.value)}
                                    className="px-3 py-2 rounded-xl bg-brand-bg border border-brand-border text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                >
                                    <option value="ALL">Semua tipe</option>
                                    <option value="AP">Access Point</option>
                                    <option value="SWITCH">Switch</option>
                                    <option value="GATEWAY">Gateway / Router</option>
                                </select>
                                <span className="text-xs font-mono text-brand-textSecondary whitespace-nowrap">
                                    {filteredDevices.length}/{devices.length}
                                </span>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <caption className="sr-only">
                                    Daftar perangkat Ruijie beserta status, alamat, dan jumlah klien yang dilaporkan
                                </caption>
                                <thead className="bg-brand-bgSecondary/60 text-xs uppercase tracking-wider text-brand-textSecondary">
                                    <tr>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Perangkat
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Model / Tipe
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            IP / MAC
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Grup
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Klien
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Status
                                        </th>
                                        <th scope="col" className="px-5 py-3 font-bold">
                                            Sumber Data
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border">
                                    {filteredDevices.length === 0 && (
                                        <tr>
                                            <td colSpan={7} className="px-5 py-10 text-center">
                                                <div className="text-sm font-semibold text-slate-900">
                                                    {devices.length === 0
                                                        ? "Belum ada perangkat Ruijie yang terdata"
                                                        : "Tidak ada perangkat yang cocok dengan filter"}
                                                </div>
                                                <div className="text-xs text-brand-textSecondary mt-1">
                                                    {devices.length === 0
                                                        ? cloudConnected
                                                            ? "Cloud API terhubung namun belum melaporkan perangkat pada project ini."
                                                            : "Hubungkan Cloud API atau tambahkan perangkat Ruijie ke inventaris CIMS."
                                                        : "Ubah kata kunci pencarian atau pilih tipe lain."}
                                                </div>
                                            </td>
                                        </tr>
                                    )}

                                    {filteredDevices.map((dev, index) => {
                                        const style = statusStyleOf(dev.status);

                                        return (
                                            <tr
                                                key={dev.sn || dev.mac || `${dev.name ?? "device"}-${index}`}
                                                className="hover:bg-brand-cardElevated/60 transition duration-150"
                                            >
                                                <td className="px-5 py-3.5">
                                                    <div className="font-semibold text-slate-900">{show(dev.name)}</div>
                                                    <div className="text-xs font-mono text-brand-textSecondary mt-0.5">
                                                        SN {show(dev.sn)}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <div className="text-slate-900">{show(dev.model)}</div>
                                                    <div className="text-xs text-brand-textSecondary mt-0.5">
                                                        {show(dev.type)}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <div className="font-mono text-cyan-700">{show(dev.ip)}</div>
                                                    <div className="text-xs font-mono text-brand-textSecondary mt-0.5">
                                                        {show(dev.mac)}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3.5 text-brand-textSecondary">
                                                    {show(dev.group_name)}
                                                </td>
                                                {/* client_count kosong berarti tidak dilaporkan — bukan nol klien. */}
                                                <td className="px-5 py-3.5">
                                                    {dev.client_count === null || dev.client_count === undefined ? (
                                                        <NoData />
                                                    ) : (
                                                        <span className="font-semibold text-purple-700">
                                                            {dev.client_count} STAs
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <span
                                                        className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border ${style.chip}`}
                                                    >
                                                        <span
                                                            className={`h-1.5 w-1.5 rounded-full mr-1.5 ${style.dot}`}
                                                            aria-hidden="true"
                                                        ></span>
                                                        {style.label}
                                                    </span>
                                                </td>
                                                {/* Sumber data ditulis apa adanya dari server, tanpa label fallback karangan. */}
                                                <td className="px-5 py-3.5 text-xs font-mono text-brand-textSecondary">
                                                    {show(dev.source)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* ============ TAB: WIRELESS CLIENTS ============ */}
                {activeTab === "clients" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-4 border-b border-brand-border">
                            <h2 className="text-sm font-bold text-slate-900">Klien Wi-Fi Aktif</h2>
                            <p className="text-xs text-brand-textSecondary mt-0.5">
                                Daftar STA hanya bisa dilaporkan Ruijie Cloud. Kolom kosong (—) berarti field tersebut
                                tidak disertakan dalam respons API.
                            </p>
                        </div>

                        {wirelessClients.length === 0 ? (
                            <div className="px-5 py-12 text-center">
                                <div className="text-3xl mb-2" aria-hidden="true">
                                    📡
                                </div>
                                <div className="text-sm font-semibold text-slate-900">
                                    {cloudConnected
                                        ? "Cloud API tidak melaporkan klien Wi-Fi aktif"
                                        : "Butuh koneksi Ruijie Cloud API"}
                                </div>
                                <p className="text-xs text-brand-textSecondary mt-1 max-w-md mx-auto">
                                    {cloudConnected
                                        ? "Tidak ada sesi klien pada respons terakhir. Angka ini tidak diisi perkiraan."
                                        : "Data klien wireless tidak tersimpan di inventaris CIMS, sehingga tidak dapat ditampilkan tanpa koneksi Cloud API yang berhasil."}
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">
                                        Klien Wi-Fi yang dilaporkan aktif oleh Ruijie Cloud
                                    </caption>
                                    <thead className="bg-brand-bgSecondary/60 text-xs uppercase tracking-wider text-brand-textSecondary">
                                        <tr>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                Klien
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                IP
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                SSID / Band
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                Access Point
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                RSSI
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                TX / RX
                                            </th>
                                            <th scope="col" className="px-5 py-3 font-bold">
                                                Online
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border">
                                        {wirelessClients.map((client, index) => (
                                            <tr
                                                key={client.mac || `${client.hostname ?? "client"}-${index}`}
                                                className="hover:bg-brand-cardElevated/60 transition duration-150"
                                            >
                                                <td className="px-5 py-3.5">
                                                    <div className="font-semibold text-slate-900">
                                                        {show(client.hostname)}
                                                    </div>
                                                    <div className="text-xs font-mono text-brand-textSecondary mt-0.5">
                                                        {show(client.mac)}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3.5 font-mono text-cyan-700">
                                                    {show(client.ip)}
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <div className="text-slate-900">{show(client.ssid)}</div>
                                                    <div className="text-xs text-brand-textSecondary mt-0.5">
                                                        {show(client.band)}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-3.5 text-brand-textSecondary">
                                                    {show(client.ap_name)}
                                                </td>
                                                {/* RSSI hanya diberi warna kualitas bila nilainya benar-benar dilaporkan. */}
                                                <td className="px-5 py-3.5">
                                                    {client.rssi === null || client.rssi === undefined ? (
                                                        <NoData />
                                                    ) : (
                                                        <span className={`font-mono ${getRssiColor(client.rssi)}`}>
                                                            {client.rssi} dBm
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-3.5 text-xs font-mono text-brand-textSecondary">
                                                    {show(client.tx_rate)} / {show(client.rx_rate)}
                                                </td>
                                                <td className="px-5 py-3.5 text-xs text-brand-textSecondary">
                                                    {show(client.online_time)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* ============ TAB: ALARMS & EVENTS ============ */}
                {activeTab === "alarms" && (
                    <div className="bg-brand-card border border-brand-border rounded-2xl overflow-hidden">
                        <div className="p-4 border-b border-brand-border">
                            <h2 className="text-sm font-bold text-slate-900">Alarm &amp; Event Ruijie Cloud</h2>
                            <p className="text-xs text-brand-textSecondary mt-0.5">
                                Seluruh event di bawah berasal dari endpoint alarm Ruijie Cloud. CIMS tidak membuat
                                alarm tiruan.
                            </p>
                        </div>

                        {alarms.length === 0 ? (
                            <div className="px-5 py-12 text-center">
                                <div className="text-3xl mb-2" aria-hidden="true">
                                    {cloudConnected ? "✅" : "🔌"}
                                </div>
                                <div className="text-sm font-semibold text-slate-900">
                                    {cloudConnected
                                        ? "Tidak ada alarm aktif pada respons terakhir"
                                        : "Butuh koneksi Ruijie Cloud API"}
                                </div>
                                <p className="text-xs text-brand-textSecondary mt-1 max-w-md mx-auto">
                                    {cloudConnected
                                        ? "Cloud API menjawab tanpa alarm. Status ini hanya berlaku untuk waktu sinkronisasi terakhir."
                                        : "Tanpa koneksi Cloud API yang berhasil, tidak diketahui apakah ada alarm aktif — bukan berarti jaringan aman."}
                                </p>
                            </div>
                        ) : (
                            <ul className="divide-y divide-brand-border">
                                {alarms.map((alarm, index) => {
                                    const level = pick(alarm, "level", "alarmLevel", "severity");
                                    const title = pick(alarm, "title", "alarmName", "name", "type", "alarmType");
                                    const deviceName = pick(alarm, "device_name", "deviceName", "apName");
                                    const deviceSn = pick(alarm, "device_sn", "deviceSn", "sn");
                                    const detail = pick(alarm, "detail", "description", "content", "message");
                                    const occurredAt = pick(alarm, "time", "createTime", "occurTime", "timestamp");

                                    return (
                                        <li
                                            key={pick(alarm, "id", "alarmId") ?? `alarm-${index}`}
                                            className="px-5 py-4 flex flex-col sm:flex-row sm:items-start gap-3"
                                        >
                                            <span
                                                className={`inline-flex shrink-0 items-center px-2.5 py-1 rounded-full text-xs font-bold border ${alarmLevelStyle(level)}`}
                                            >
                                                {level ? String(level).toUpperCase() : "LEVEL TIDAK DILAPORKAN"}
                                            </span>

                                            <div className="min-w-0 flex-1">
                                                <div className="text-sm font-semibold text-slate-900">
                                                    {show(title)}
                                                </div>
                                                <div className="text-xs text-brand-textSecondary mt-1">
                                                    Perangkat: {show(deviceName)} · SN{" "}
                                                    <span className="font-mono">{show(deviceSn)}</span>
                                                </div>
                                                {detail && (
                                                    <p className="text-xs text-brand-textSecondary mt-1 break-words">
                                                        {detail}
                                                    </p>
                                                )}
                                            </div>

                                            <div className="text-xs font-mono text-brand-textMuted shrink-0 sm:text-right">
                                                {show(occurredAt)}
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </div>
                )}

                {/* ============ TAB: API AUTHENTICATION INFO ============ */}
                {activeTab === "diagnostics" && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
                        <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                            <div className="flex items-center justify-between gap-3">
                                <h2 className="text-sm font-bold text-slate-900">Hasil Uji Koneksi Terakhir</h2>
                                {/* Badge mengikuti hasil nyata testConnection(), bukan nilai tetap. */}
                                <span
                                    className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border ${
                                        cloudConnected
                                            ? "bg-emerald-500/10 text-emerald-700 border-emerald-500/30"
                                            : "bg-red-500/10 text-red-700 border-red-500/30"
                                    }`}
                                >
                                    {cloudConnected
                                        ? "SUCCESS"
                                        : `FAILED${connection?.error_code ? ` · CODE ${connection.error_code}` : ""}`}
                                </span>
                            </div>

                            <dl className="mt-4 space-y-3 text-sm">
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-border pb-2">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                        APPID
                                    </dt>
                                    <dd className="font-mono text-cyan-700 break-all">
                                        {show(connection?.app_id ?? ruijieConfig?.appId)}
                                    </dd>
                                </div>
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-border pb-2">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                        Base URL
                                    </dt>
                                    <dd className="font-mono text-slate-900 break-all">
                                        {show(connection?.base_url ?? ruijieConfig?.baseUrl)}
                                    </dd>
                                </div>
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-border pb-2">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                        Latensi Handshake
                                    </dt>
                                    <dd className="font-mono text-slate-900">
                                        {connection?.latency_ms === null || connection?.latency_ms === undefined ? (
                                            <NoData />
                                        ) : (
                                            `${connection.latency_ms} ms`
                                        )}
                                    </dd>
                                </div>
                                {/* Server hanya mengirim potongan awal token; token utuh tidak pernah dikirim ke browser. */}
                                <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-border pb-2">
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                        Access Token
                                    </dt>
                                    <dd className="font-mono text-slate-900 break-all">
                                        {connection?.token ? (
                                            connection.token
                                        ) : (
                                            <span className="text-brand-textMuted">tidak ada token aktif</span>
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                        Pesan Server
                                    </dt>
                                    <dd
                                        className={`mt-1 text-sm ${cloudConnected ? "text-emerald-700" : "text-red-700"}`}
                                    >
                                        {show(connection?.message)}
                                    </dd>
                                </div>
                                {connection?.hint && (
                                    <div>
                                        <dt className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                                            Tindakan yang Disarankan
                                        </dt>
                                        <dd className="mt-1 text-sm text-amber-700">{connection.hint}</dd>
                                    </div>
                                )}
                            </dl>
                        </div>

                        <div className="bg-brand-card border border-brand-border rounded-2xl p-5">
                            <h2 className="text-sm font-bold text-slate-900">Alur Autentikasi &amp; Asal Data</h2>
                            <p className="text-xs text-brand-textSecondary mt-0.5">
                                Ringkasan implementasi nyata pada <span className="font-mono">RuijieService</span>.
                            </p>

                            <ol className="mt-4 space-y-2.5 text-xs text-brand-textSecondary list-decimal list-inside">
                                <li>
                                    <span className="font-mono text-cyan-700">
                                        POST /service/api/oauth20/client/access_token
                                    </span>{" "}
                                    dengan <span className="font-mono">appid</span> +{" "}
                                    <span className="font-mono">secret</span> dari{" "}
                                    <span className="font-mono">config/services.php</span>.
                                </li>
                                <li>
                                    Access token yang berhasil di-cache 3000 detik (±50 menit), lalu dikirim sebagai
                                    parameter <span className="font-mono">accessToken</span> pada setiap permintaan.
                                </li>
                                <li>
                                    Endpoint data: <span className="font-mono">/service/api/device/list</span>,{" "}
                                    <span className="font-mono">/service/api/client/list</span>,{" "}
                                    <span className="font-mono">/service/api/alarm/list</span>.
                                </li>
                                <li>
                                    Bila token tidak diperoleh, permintaan tidak dipaksa jalan dan hasilnya kosong —
                                    tidak diganti data contoh.
                                </li>
                            </ol>

                            <div className="mt-5 pt-4 border-t border-brand-border space-y-2 text-xs text-brand-textSecondary">
                                <div className="font-bold text-slate-900 text-sm">Asal data setiap tab</div>
                                <p>
                                    <span className="font-semibold text-slate-900">Managed Devices:</span> perangkat
                                    dari Cloud API (<span className="font-mono">Ruijie Cloud</span>) digabung dengan
                                    inventaris CIMS bervendor Ruijie/Reyee (
                                    <span className="font-mono">DB Inventory</span>). Status baris{" "}
                                    <span className="font-mono">DB Inventory</span> berasal dari hasil monitoring ICMP
                                    CIMS, bukan dari Cloud.
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-900">Wi-Fi Clients &amp; Alarms:</span>{" "}
                                    hanya dari Cloud API. Tanpa token yang valid, keduanya kosong dan ditandai butuh
                                    koneksi — tidak diisi angka perkiraan.
                                </p>
                                <p>
                                    <span className="font-semibold text-slate-900">Jumlah klien per perangkat:</span>{" "}
                                    tidak tersedia untuk baris inventaris, sehingga ditampilkan{" "}
                                    <span className="font-mono">—</span> alih-alih 0.
                                </p>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </CimsLayout>
    );
}
