import React, { useState, useEffect, useRef } from "react";
import CimsLayout from "@/Layouts/CimsLayout";
import { Head } from "@inertiajs/react";
import axios from "axios";

export default function AlertsIndex({ auth, alerts: initialAlerts, stats: initialStats, telegramStatus }) {
    const [alerts, setAlerts] = useState(initialAlerts || []);
    const [stats, setStats] = useState(initialStats || {});
    const [filterSeverity, setFilterSeverity] = useState("all");
    const [isScanning, setIsScanning] = useState(false);
    const [isTestingTelegram, setIsTestingTelegram] = useState(false);
    const [toastMessage, setToastMessage] = useState(null);
    const [toastType, setToastType] = useState("success");

    // `alerts` & `stats` dikirim sebagai deferred prop dari controller, jadi render
    // pertama sengaja kosong (halaman langsung tampil) dan datanya baru menyusul.
    // Selama prop-nya masih undefined, scan pertama dianggap masih berjalan.
    const isInitialScanPending = initialAlerts === undefined;

    // Auto-Refresh state
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [refreshInterval, setRefreshInterval] = useState(15); // Default 15 seconds
    const [countdown, setCountdown] = useState(15);
    const [lastRefreshedAt, setLastRefreshedAt] = useState(new Date().toLocaleTimeString());

    // Dibaca efek sinkronisasi deferred prop tanpa ikut jadi dependency-nya.
    const refreshIntervalRef = useRef(refreshInterval);
    refreshIntervalRef.current = refreshInterval;

    // Satu scan menembak MikroTik dan bisa memakan belasan detik. Tanpa penjaga
    // ini, auto-refresh 15 detik akan menumpuk request yang saling menunggu dan
    // membuat seluruh aplikasi ikut lambat.
    const scanInFlight = useRef(false);

    useEffect(() => {
        if (initialAlerts !== undefined) {
            setAlerts(initialAlerts);
            setStats(initialStats ?? {});
            setLastRefreshedAt(new Date().toLocaleTimeString());
            setCountdown(refreshIntervalRef.current);
        }
    }, [initialAlerts, initialStats]);

    const showToast = (msg, type = "success") => {
        setToastMessage(msg);
        setToastType(type);
        setTimeout(() => setToastMessage(null), 5000);
    };

    const handleRunScan = async (isSilent = false) => {
        if (scanInFlight.current) return;
        scanInFlight.current = true;
        if (!isSilent) setIsScanning(true);
        try {
            const res = await axios.post(route("alerts.scan"));
            if (res.data.success) {
                setAlerts(res.data.alerts);
                const newAlerts = res.data.alerts;
                setStats({
                    total_alerts: newAlerts.length,
                    critical_count: newAlerts.filter((a) => a.severity === "CRITICAL").length,
                    warning_count: newAlerts.filter((a) => a.severity === "WARNING").length,
                    info_count: newAlerts.filter((a) => a.severity === "INFO").length,
                });
                setLastRefreshedAt(new Date().toLocaleTimeString());
                if (!isSilent) {
                    showToast(`Security scan completed. ${newAlerts.length} total anomalies found.`);
                }
            }
        } catch (e) {
            if (!isSilent) showToast("Failed executing security scan.", "error");
        } finally {
            scanInFlight.current = false;
            if (!isSilent) setIsScanning(false);
        }
    };

    // Hitungan mundur auto-refresh. Ditahan selama scan pertama (deferred prop)
    // belum selesai supaya tidak ada dua scan berjalan bersamaan.
    useEffect(() => {
        if (!autoRefresh || refreshInterval <= 0 || isInitialScanPending) return;

        const timer = setInterval(() => setCountdown((prev) => Math.max(prev - 1, 0)), 1000);

        return () => clearInterval(timer);
    }, [autoRefresh, refreshInterval, isInitialScanPending]);

    // Pemicu scan dipisah dari updater `setCountdown`: efek samping di dalam
    // updater dieksekusi dua kali oleh React StrictMode (dua scan per siklus).
    // Countdown baru diisi ulang setelah scan selesai, jadi jarak antar scan
    // dihitung dari selesainya scan sebelumnya — tidak pernah bertumpuk.
    useEffect(() => {
        if (countdown > 0 || !autoRefresh || refreshInterval <= 0 || isInitialScanPending) return;

        let cancelled = false;
        handleRunScan(true).finally(() => {
            if (!cancelled) setCountdown(refreshInterval);
        });

        return () => {
            cancelled = true;
        };
    }, [countdown, autoRefresh, refreshInterval, isInitialScanPending]);

    // Reset countdown when refreshInterval changes
    const handleIntervalChange = (newInterval) => {
        const val = parseInt(newInterval, 10);
        if (val === 0) {
            setAutoRefresh(false);
        } else {
            setAutoRefresh(true);
            setRefreshInterval(val);
            setCountdown(val);
        }
    };

    const handleTestTelegram = async () => {
        setIsTestingTelegram(true);
        try {
            const res = await axios.post(route("alerts.test-telegram"));
            if (res.data.success) {
                showToast(res.data.message, "success");
            } else {
                showToast(res.data.message, "error");
            }
        } catch (e) {
            showToast("Failed sending test Telegram message.", "error");
        } finally {
            setIsTestingTelegram(false);
        }
    };

    const handleResolveAlert = (id) => {
        setAlerts((prev) => prev.filter((a) => a.id !== id));
        showToast("Alert acknowledged and marked resolved.");
    };

    const filteredAlerts = alerts.filter((a) => {
        if (filterSeverity === "all") return true;
        return a.severity === filterSeverity;
    });

    const getSeverityBadge = (severity) => {
        switch (severity) {
            case "CRITICAL":
                return (
                    <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-red-500/10 text-red-700 border border-red-500/30 inline-flex items-center">
                        <span className="h-2 w-2 rounded-full bg-red-400 mr-1.5 animate-ping"></span>
                        CRITICAL
                    </span>
                );
            case "WARNING":
                return (
                    <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-700 border border-amber-500/30 inline-flex items-center">
                        <span className="h-2 w-2 rounded-full bg-amber-400 mr-1.5"></span>
                        WARNING
                    </span>
                );
            default:
                return (
                    <span className="px-2.5 py-1 rounded-full text-xs font-bold bg-blue-500/10 text-blue-700 border border-blue-500/30 inline-flex items-center">
                        <span className="h-2 w-2 rounded-full bg-blue-400 mr-1.5"></span>
                        INFO
                    </span>
                );
        }
    };

    return (
        <CimsLayout>
            <Head title="Pusat Peringatan Keamanan & Anomali" />

            <div className="space-y-6">
                {/* Toast Notification Alert */}
                {toastMessage && (
                    <div
                        className={`p-4 rounded-xl border flex items-center justify-between text-sm transition ${
                            toastType === "error"
                                ? "bg-red-500/10 border-red-500/30 text-red-700"
                                : "bg-emerald-500/10 border-emerald-500/30 text-emerald-700"
                        }`}
                    >
                        <div className="flex items-center space-x-3">
                            <span className="text-xl">{toastType === "error" ? "❌" : "✅"}</span>
                            <span className="font-medium">{toastMessage}</span>
                        </div>
                        <button onClick={() => setToastMessage(null)} className="text-xs opacity-70 hover:opacity-100">
                            Dismiss
                        </button>
                    </div>
                )}

                {/* Header Section */}
                <div className="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-brand-card border border-brand-border p-6 rounded-2xl">
                    <div className="flex items-center space-x-4">
                        <div className="h-12 w-12 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-amber-700 font-bold text-2xl">
                            🚨
                        </div>
                        <div>
                            <div className="flex items-center space-x-3">
                                <h1 className="text-2xl font-bold text-slate-900 tracking-wide">
                                    Security & Anomaly Alerts Center
                                </h1>
                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-500/10 text-red-700 border border-red-500/30">
                                    Live Log Threat Scanner
                                </span>
                            </div>
                            <p className="text-sm text-brand-textSecondary mt-1">
                                Real-time brute force attack detection, CPU/RAM overload monitors, and Telegram Bot dispatch
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {/* Live Auto-Refresh Status Badge */}
                        <div className="flex items-center space-x-2 px-3 py-2 bg-brand-bg/80 border border-brand-border rounded-xl text-xs font-medium text-brand-textSecondary">
                            {autoRefresh ? (
                                <>
                                    <span className="relative flex h-2.5 w-2.5">
                                        <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                        <span className="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                                    </span>
                                    <span className="text-emerald-700 font-bold">Auto Syncing ({countdown}s)</span>
                                </>
                            ) : (
                                <>
                                    <span className="h-2.5 w-2.5 rounded-full bg-zinc-500"></span>
                                    <span className="text-slate-500 font-semibold">Auto-Sync Off</span>
                                </>
                            )}
                            <span className="text-zinc-600">•</span>
                            <span className="text-slate-500 font-mono">Last: {lastRefreshedAt}</span>
                        </div>

                        {/* Interval Selector Dropdown */}
                        <select
                            value={autoRefresh ? refreshInterval : 0}
                            onChange={(e) => handleIntervalChange(e.target.value)}
                            className="bg-brand-bg border border-brand-border text-slate-900 text-xs rounded-xl px-3 py-2 focus:ring-1 focus:ring-emerald-500 cursor-pointer"
                        >
                            <option value="10">Auto Refresh: 10s</option>
                            <option value="15">Auto Refresh: 15s</option>
                            <option value="30">Auto Refresh: 30s</option>
                            <option value="60">Auto Refresh: 60s</option>
                            <option value="0">Auto Refresh: OFF</option>
                        </select>

                        {/* Manual Trigger Scan Button */}
                        <button
                            onClick={() => handleRunScan(false)}
                            disabled={isScanning || isInitialScanPending}
                            className="flex items-center space-x-2 px-4 py-2 bg-brand-primary hover:bg-emerald-500 text-white font-medium rounded-xl text-xs transition duration-200 disabled:opacity-50"
                        >
                            <svg
                                className={`w-3.5 h-3.5 ${isScanning || isInitialScanPending ? "animate-spin" : ""}`}
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
                            <span>{isScanning || isInitialScanPending ? "Scanning..." : "Scan Now"}</span>
                        </button>
                    </div>
                </div>

                {/* Summary Stat Cards */}
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Total Detected</div>
                            <div className="text-2xl font-bold text-slate-900 mt-1">{stats.total_alerts || 0}</div>
                        </div>
                        <div className="p-3 bg-brand-bgSecondary rounded-xl text-amber-700 font-bold">⚠️</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Critical Threats</div>
                            <div className="text-2xl font-bold text-red-700 mt-1">{stats.critical_count || 0}</div>
                        </div>
                        <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-700 font-bold">🛡️</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Warning Overloads</div>
                            <div className="text-2xl font-bold text-amber-700 mt-1">{stats.warning_count || 0}</div>
                        </div>
                        <div className="p-3 bg-amber-500/10 border border-amber-500/20 rounded-xl text-amber-700 font-bold">⚡</div>
                    </div>

                    <div className="bg-brand-card border border-brand-border p-4 rounded-2xl flex items-center justify-between">
                        <div>
                            <div className="text-xs text-brand-textSecondary uppercase font-semibold">Info Events</div>
                            <div className="text-2xl font-bold text-blue-700 mt-1">{stats.info_count || 0}</div>
                        </div>
                        <div className="p-3 bg-blue-500/10 border border-blue-500/20 rounded-xl text-blue-700 font-bold">ℹ️</div>
                    </div>
                </div>

                {/* Telegram Bot Integration Status Bar */}
                <div className="bg-brand-card border border-brand-border p-5 rounded-2xl flex flex-col md:flex-row items-center justify-between gap-4">
                    <div className="flex items-center space-x-3">
                        <div className="p-3 bg-sky-500/10 border border-sky-500/30 rounded-xl text-sky-700 text-2xl font-bold">
                            ✈️
                        </div>
                        <div>
                            <div className="flex items-center space-x-2">
                                <h3 className="font-bold text-slate-900 text-base">Telegram Instant Alert Channel</h3>
                                {telegramStatus?.configured ? (
                                    <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-700 border border-emerald-500/30">
                                        Connected ({telegramStatus.chat_id})
                                    </span>
                                ) : (
                                    <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-700 border border-amber-500/30">
                                        Not Configured (.env)
                                    </span>
                                )}
                            </div>
                            <p className="text-xs text-brand-textSecondary mt-0.5">
                                Automatically sends instant alert messages to your Telegram Admin Group when brute-force or CPU spikes occur.
                            </p>
                        </div>
                    </div>

                    <button
                        onClick={handleTestTelegram}
                        disabled={isTestingTelegram}
                        className="px-4 py-2 bg-sky-600 hover:bg-sky-500 text-white font-medium text-xs rounded-xl transition whitespace-nowrap disabled:opacity-50"
                    >
                        {isTestingTelegram ? "Sending Telegram Test..." : "Send Test Telegram Alert 📲"}
                    </button>
                </div>

                {/* Filter Toolbar */}
                <div className="flex items-center justify-between bg-brand-card border border-brand-border p-4 rounded-2xl">
                    <div className="flex items-center space-x-2">
                        {[
                            { id: "all", label: `All Alerts (${alerts.length})` },
                            { id: "CRITICAL", label: `Critical (${stats.critical_count || 0})` },
                            { id: "WARNING", label: `Warning (${stats.warning_count || 0})` },
                            { id: "INFO", label: `Info (${stats.info_count || 0})` },
                        ].map((btn) => (
                            <button
                                key={btn.id}
                                onClick={() => setFilterSeverity(btn.id)}
                                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition ${
                                    filterSeverity === btn.id
                                        ? "bg-purple-600 text-white"
                                        : "bg-brand-bg text-brand-textSecondary hover:text-slate-900 hover:bg-brand-cardElevated"
                                }`}
                            >
                                {btn.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Alert Cards Feed */}
                <div className="space-y-4">
                    {isInitialScanPending ? (
                        <div className="bg-brand-card border border-brand-border p-12 text-center rounded-2xl text-brand-textSecondary">
                            <svg
                                className="w-8 h-8 mx-auto mb-3 animate-spin text-purple-600"
                                fill="none"
                                viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path
                                    className="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                />
                            </svg>
                            <div className="font-bold text-slate-900 text-base">Menjalankan security scan…</div>
                            <p className="text-xs mt-1">
                                Log MikroTik, beban CPU, dan perangkat inventaris sedang diperiksa. Hasilnya muncul otomatis.
                            </p>
                        </div>
                    ) : filteredAlerts.length === 0 ? (
                        <div className="bg-brand-card border border-brand-border p-12 text-center rounded-2xl text-brand-textSecondary">
                            <div className="text-4xl mb-2">🎉</div>
                            <div className="font-bold text-slate-900 text-base">No Security Anomalies Detected</div>
                            <p className="text-xs mt-1">All MikroTik logs, CPU loads, and inventory devices are operating normally.</p>
                        </div>
                    ) : (
                        filteredAlerts.map((item) => (
                            <div
                                key={item.id}
                                className={`bg-brand-card border rounded-2xl p-5 space-y-3 transition hover:border-brand-textSecondary/40 ${
                                    item.severity === "CRITICAL"
                                        ? "border-red-500/40 bg-red-950/10"
                                        : item.severity === "WARNING"
                                        ? "border-amber-500/30"
                                        : "border-brand-border"
                                }`}
                            >
                                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-brand-border/60 pb-3">
                                    <div className="flex items-center space-x-3">
                                        {getSeverityBadge(item.severity)}
                                        <h3 className="font-bold text-slate-900 text-base">{item.title}</h3>
                                    </div>
                                    <div className="flex items-center space-x-3 text-xs text-brand-textSecondary font-mono">
                                        <span>🖥️ {item.device}</span>
                                        <span>•</span>
                                        <span>⏰ {item.timestamp}</span>
                                    </div>
                                </div>

                                <div className="text-sm text-brand-textSecondary font-mono bg-brand-bg/80 p-3 rounded-xl border border-brand-border">
                                    {item.message}
                                </div>

                                {item.suggestion && (
                                    <div className="flex items-start space-x-2 text-xs bg-purple-500/10 border border-purple-500/20 p-3 rounded-xl text-purple-700">
                                        <span className="font-bold">💡 Recommended Action:</span>
                                        <span>{item.suggestion}</span>
                                    </div>
                                )}

                                <div className="flex justify-end pt-1">
                                    <button
                                        onClick={() => handleResolveAlert(item.id)}
                                        className="px-3 py-1.5 bg-brand-bgSecondary hover:bg-brand-cardElevated border border-brand-border text-slate-900 text-xs font-medium rounded-lg transition"
                                    >
                                        Acknowledge & Mark Resolved ✓
                                    </button>
                                </div>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </CimsLayout>
    );
}
