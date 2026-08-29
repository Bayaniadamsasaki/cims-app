import CimsLayout from "@/Layouts/CimsLayout";
import { Head, router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";

/**
 * Kosakata status monitoring nyata (tidak ada status hasil simulasi):
 * - `online`      ICMP menjawab DAN metrik berhasil dibaca.
 * - `degraded`    ICMP menjawab, tetapi sumber metrik (RouterOS API/SNMP) gagal.
 * - `unreachable` tidak ada balasan ICMP sama sekali.
 * - `error`       pemindaian tidak dapat dijalankan (alamat/protokol bermasalah).
 * - `maintenance` perangkat sengaja ditandai maintenance di inventaris.
 * - `unknown`     belum pernah dipindai — bukan berarti perangkat sehat.
 */
const MONITORING_STATUS = {
    online: { label: "Online", dot: "bg-emerald-500 animate-pulse", chip: "bg-emerald-50 text-emerald-700 border-emerald-200" },
    degraded: { label: "Degraded", dot: "bg-amber-500", chip: "bg-amber-50 text-amber-700 border-amber-200" },
    unreachable: { label: "Unreachable", dot: "bg-rose-500", chip: "bg-rose-50 text-rose-700 border-rose-200" },
    error: { label: "Monitoring Error", dot: "bg-rose-600", chip: "bg-rose-50 text-rose-700 border-rose-200" },
    maintenance: { label: "Maintenance", dot: "bg-amber-500", chip: "bg-amber-50 text-amber-700 border-amber-200" },
    unknown: { label: "No Data", dot: "bg-slate-400", chip: "bg-slate-100 text-slate-600 border-slate-200" },
};

/** Prop yang cukup dimuat ulang saat menunggu hasil pindai masuk dari antrean. */
const MONITORING_PROPS = ["devices", "summary", "alerts"];

/**
 * Pemindaian berjalan di antrean: satu job per perangkat, hasilnya masuk
 * bertahap. Jeda pemuatan ulang berikut mengikuti kenyataan itu — cepat untuk
 * perangkat yang langsung menjawab, lalu makin longgar untuk perangkat yang
 * baru selesai setelah batas waktu ICMP-nya habis.
 */
const REFRESH_DELAYS_MS = [4000, 12000, 30000];

/** Perangkat maintenance ditandai lebih dulu; sisanya mengikuti hasil pindai nyata. */
const statusKeyOf = (device) => {
    if (device.status === "maintenance") return "maintenance";

    const status = device.metrics?.last_ping_status;

    return MONITORING_STATUS[status] ? status : "unknown";
};

/** Metrik hanya boleh dirender bila benar-benar terukur. */
const hasValue = (value) => value !== null && value !== undefined;

const formatCheckedAt = (value) => {
    if (!value) return "Belum pernah dipindai";

    const at = new Date(value);

    if (Number.isNaN(at.getTime())) return "Belum pernah dipindai";

    return `Dipindai ${at.toLocaleString("id-ID", { dateStyle: "short", timeStyle: "short" })}`;
};

/** Penanda seragam untuk data yang tidak tersedia. */
const NoData = () => <span className="text-slate-400" title="Tidak ada data terukur">—</span>;

/** Kartu ringkasan: satu angka nyata + keterangan asal angkanya. */
function SummaryCard({ label, value, unit, caption, tone = "neutral" }) {
    const valueTone = {
        neutral: "text-slate-900",
        primary: "text-brand-primary",
        danger: "text-rose-700",
        warning: "text-amber-700",
        muted: "text-brand-textMuted",
    }[tone];

    return (
        <div className="rounded-2xl border border-brand-border bg-brand-card p-6">
            <div className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">{label}</div>
            <div className="mt-2 flex items-baseline">
                <span className={`text-4xl font-extrabold tabular-nums ${valueTone}`}>{value}</span>
                {unit && <span className="ml-2 text-xs text-brand-textSecondary">{unit}</span>}
            </div>
            <div className="mt-4 text-xs text-brand-textMuted">{caption}</div>
        </div>
    );
}

export default function Index({ devices = [], summary = {}, alerts = [], latestSpeedtest = null }) {
    const [scanning, setScanning] = useState(false);
    const [collecting, setCollecting] = useState(false);
    const [testingSpeed, setTestingSpeed] = useState(false);
    const refreshTimers = useRef([]);

    const total = summary.total ?? 0;
    const online = summary.online ?? 0;
    const degraded = summary.degraded ?? 0;
    const unreachable = summary.unreachable ?? 0;
    const monitoringError = summary.error ?? 0;
    const unknown = summary.unknown ?? 0;
    const onlinePercent = summary.onlinePercent ?? 0;
    const needsAttention = degraded + unreachable + monitoringError;
    const checked = total - unknown;

    const clearRefreshTimers = () => {
        refreshTimers.current.forEach((timer) => window.clearTimeout(timer));
        refreshTimers.current = [];
    };

    // Timer yang masih menggantung setelah halaman ditinggalkan akan menembak
    // request untuk komponen yang sudah tidak ada, jadi selalu dibersihkan.
    useEffect(() => clearRefreshTimers, []);

    /**
     * Hasil pindai tidak lagi tiba bersamaan dengan respons tombol: worker
     * antrean mengisinya perangkat demi perangkat. Pemuatan ulang bertahap
     * membuat hasil nyata muncul sendiri tanpa pengguna menebak kapan harus
     * menekan refresh, dan hanya prop monitoring yang diminta ulang.
     */
    const stageResultRefreshes = () => {
        clearRefreshTimers();
        setCollecting(true);

        refreshTimers.current = REFRESH_DELAYS_MS.map((delay, index) => {
            const isLast = index === REFRESH_DELAYS_MS.length - 1;

            return window.setTimeout(() => {
                router.reload({
                    only: MONITORING_PROPS,
                    onFinish: () => {
                        if (isLast) setCollecting(false);
                    },
                });
            }, delay);
        });
    };

    const refreshResultsNow = () => router.reload({ only: MONITORING_PROPS });

    /**
     * Tombol ini hanya MENJADWALKAN pemindaian lalu langsung kembali — tidak
     * pernah menunggu ratusan perangkat menjawab di dalam satu request. Bila
     * tidak ada satu pun job yang dikirim (inventaris kosong), server mengirim
     * flash error dan tidak ada hasil yang perlu ditunggu.
     */
    const handleScanNow = () => {
        setScanning(true);
        router.post(
            route("monitoring.scan"),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!page.props.flash?.error) stageResultRefreshes();
                },
                onFinish: () => setScanning(false),
            },
        );
    };

    const handleRunSpeedtest = () => {
        setTestingSpeed(true);
        router.post(route("monitoring.speedtest"), {}, { onFinish: () => setTestingSpeed(false) });
    };

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            Pemantauan Infrastruktur Langsung
                        </h2>
                        <p className="text-sm text-slate-500">
                            Status ICMP, latensi paket, beban CPU/RAM, dan kesehatan perangkat — seluruhnya
                            dari hasil pengecekan jaringan yang benar-benar dijalankan.
                        </p>
                    </div>
                    <button
                        onClick={handleScanNow}
                        disabled={scanning}
                        aria-busy={scanning || collecting}
                        className={`inline-flex items-center rounded-xl px-4 py-3 text-sm font-semibold text-white transition duration-150 ${
                            scanning ? "cursor-not-allowed bg-blue-400" : "bg-blue-600 hover:bg-blue-700"
                        }`}
                    >
                        <svg
                            className={`mr-2 h-5 w-5 shrink-0 ${scanning || collecting ? "animate-spin" : ""}`}
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89H18"
                            />
                        </svg>
                        <span className="leading-5">
                            {scanning ? "Menjadwalkan Pindai..." : "Pindai Sekarang"}
                        </span>
                    </button>

                    {/* Pemindaian berjalan di latar belakang: keadaannya harus terlihat, bukan ditebak. */}
                    {collecting && (
                        <div
                            className="w-full rounded-xl border border-blue-200 bg-blue-50 px-4 py-3"
                            role="status"
                            aria-live="polite"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-start gap-2">
                                    <span className="mt-1.5 h-2 w-2 shrink-0 animate-pulse rounded-full bg-blue-600" />
                                    <p className="max-w-2xl text-xs text-blue-900">
                                        <strong className="font-semibold">
                                            Pemindaian sedang berjalan di antrean.
                                        </strong>{" "}
                                        Setiap perangkat diperiksa satu per satu, jadi status dan metrik di bawah
                                        terisi bertahap — halaman ini memuat hasil terbaru dengan sendirinya.
                                    </p>
                                </div>
                                <button
                                    onClick={refreshResultsNow}
                                    className="shrink-0 rounded-lg border border-blue-300 bg-white px-3 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                                >
                                    Muat hasil sekarang
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            }
        >
            <Head title="Live Monitoring" />

            <div className="text-slate-900">
                {/* Ringkasan: hanya perangkat yang benar-benar pernah dicek yang dihitung. */}
                <div className="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-2xl border border-brand-border bg-brand-card p-6">
                        <div className="text-xs font-semibold uppercase tracking-wider text-brand-textSecondary">
                            Online Availability
                        </div>
                        <div className="mt-2 flex items-baseline">
                            <span className="text-4xl font-extrabold tabular-nums text-brand-primary">
                                {onlinePercent}%
                            </span>
                            <span className="ml-2 text-xs text-brand-textMuted">dari {total} perangkat</span>
                        </div>
                        <div className="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-brand-bgSecondary ring-1 ring-brand-border">
                            <div
                                className="h-1.5 rounded-full bg-brand-primary"
                                style={{ width: `${Math.min(100, Math.max(0, onlinePercent))}%` }}
                            />
                        </div>
                        <div className="mt-3 text-xs text-brand-textMuted">
                            {unknown > 0
                                ? `Cakupan pindai baru ${checked}/${total} perangkat — sisanya belum diketahui.`
                                : "Seluruh perangkat sudah pernah dipindai."}
                        </div>
                    </div>

                    <SummaryCard
                        label="Terkonfirmasi Online"
                        value={online}
                        unit={`/ ${total} perangkat`}
                        tone="primary"
                        caption="ICMP menjawab dan metrik berhasil dibaca."
                    />

                    <SummaryCard
                        label="Perlu Perhatian"
                        value={needsAttention}
                        tone={needsAttention > 0 ? "danger" : "muted"}
                        caption={`${unreachable} unreachable · ${monitoringError} monitoring error · ${degraded} degraded`}
                    />

                    <SummaryCard
                        label="Belum Ada Data"
                        value={unknown}
                        tone={unknown > 0 ? "warning" : "muted"}
                        caption={
                            unknown > 0
                                ? "Belum pernah dipindai — status tidak diasumsikan online."
                                : "Semua perangkat punya hasil pindai."
                        }
                    />
                </div>

                {/* Tabel telemetri (2 kolom) + widget kontrol (1 kolom) */}
                <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
                    <div className="rounded-2xl border border-brand-border bg-brand-card p-6 lg:col-span-2">
                        <div className="mb-6 flex flex-wrap items-baseline justify-between gap-2">
                            <h3 className="text-lg font-bold text-slate-900">Device Node Telemetry</h3>
                            <p className="text-xs text-brand-textMuted">
                                Nilai kosong (—) berarti metrik tidak berhasil diambil, bukan nol.
                            </p>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <caption className="sr-only">
                                    Status dan metrik perangkat dari hasil pemindaian jaringan terakhir
                                </caption>
                                <thead>
                                    <tr>
                                        <th scope="col" className="py-3 text-xs font-bold text-brand-textSecondary">
                                            Device
                                        </th>
                                        <th scope="col" className="px-3 py-3 text-xs font-bold text-brand-textSecondary">
                                            Status
                                        </th>
                                        <th scope="col" className="px-3 py-3 text-xs font-bold text-brand-textSecondary">
                                            Latency
                                        </th>
                                        <th scope="col" className="px-3 py-3 text-xs font-bold text-brand-textSecondary">
                                            CPU Load
                                        </th>
                                        <th scope="col" className="px-3 py-3 text-xs font-bold text-brand-textSecondary">
                                            RAM Usage
                                        </th>
                                        <th scope="col" className="py-3 text-right text-xs font-bold text-brand-textSecondary">
                                            Logs
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {devices.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="py-8 text-center text-sm text-brand-textSecondary">
                                                Belum ada perangkat di inventaris untuk dipantau.
                                            </td>
                                        </tr>
                                    )}

                                    {devices.map((device) => {
                                        const m = device.metrics;
                                        const statusKey = statusKeyOf(device);
                                        const status = MONITORING_STATUS[statusKey];
                                        const latency = m?.last_ping_latency_ms;
                                        const packetLoss = m?.last_packet_loss_percent;
                                        const cpu = m?.last_cpu_usage_percent;
                                        const ram = m?.last_ram_usage_percent;

                                        return (
                                            <tr key={device.id} className="transition hover:bg-brand-bgSecondary/30">
                                                <td className="py-4 pr-3 text-sm">
                                                    <div className="font-bold text-slate-900">{device.name}</div>
                                                    <div className="mt-0.5 font-mono text-xs text-brand-textSecondary">
                                                        {device.ip_address || "IP belum diisi"}
                                                    </div>
                                                    <div className="mt-0.5 text-[10px] text-brand-textMuted">
                                                        {formatCheckedAt(m?.last_checked_at)}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span
                                                        className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-[11px] font-bold ${status.chip}`}
                                                    >
                                                        <span className={`h-2 w-2 shrink-0 rounded-full ${status.dot}`} />
                                                        {status.label}
                                                    </span>
                                                    {hasValue(packetLoss) && packetLoss > 0 && (
                                                        <div className="mt-1 text-[10px] font-semibold text-rose-700">
                                                            Packet loss {packetLoss}%
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 font-mono text-sm text-brand-textSecondary">
                                                    {hasValue(latency) ? `${latency} ms` : <NoData />}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    {hasValue(cpu) ? (
                                                        <div className="flex items-center space-x-2">
                                                            <span
                                                                className={`font-mono text-xs font-semibold ${
                                                                    cpu > 80
                                                                        ? "text-rose-700"
                                                                        : cpu > 50
                                                                          ? "text-amber-700"
                                                                          : "text-emerald-700"
                                                                }`}
                                                            >
                                                                {cpu}%
                                                            </span>
                                                            <div className="h-1 w-12 overflow-hidden rounded-full bg-brand-bgSecondary ring-1 ring-brand-border">
                                                                <div
                                                                    className={`h-1 rounded-full ${
                                                                        cpu > 80
                                                                            ? "bg-rose-500"
                                                                            : cpu > 50
                                                                              ? "bg-amber-500"
                                                                              : "bg-brand-primary"
                                                                    }`}
                                                                    style={{ width: `${Math.min(100, cpu)}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <NoData />
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    {hasValue(ram) ? (
                                                        <div className="flex items-center space-x-2">
                                                            <span className="font-mono text-xs font-semibold text-brand-textSecondary">
                                                                {ram}%
                                                            </span>
                                                            <div className="h-1 w-12 overflow-hidden rounded-full bg-brand-bgSecondary ring-1 ring-brand-border">
                                                                <div
                                                                    className={`h-1 rounded-full ${ram > 85 ? "bg-rose-500" : "bg-brand-primary"}`}
                                                                    style={{ width: `${Math.min(100, ram)}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <NoData />
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap py-4 text-right text-sm">
                                                    <button
                                                        onClick={() => router.get(route("monitoring.show", device.id))}
                                                        className="rounded-lg border border-brand-primary/20 bg-brand-primary/10 px-3 py-1 text-xs font-bold text-brand-primary transition hover:bg-brand-primary hover:text-white"
                                                    >
                                                        Graphs
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Widget kontrol di kolom kanan */}
                    <div className="space-y-8">
                        {/* Uji kecepatan gateway ISP — hasil hanya tersimpan bila pengukuran berhasil. */}
                        <div className="rounded-2xl border border-brand-border bg-brand-card p-6">
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-bold text-slate-900">ISP Gateway Bandwidth</h3>
                                    <p className="text-[10px] text-brand-textSecondary">
                                        Pengukuran nyata ke gateway internet kampus.
                                    </p>
                                </div>
                                <button
                                    onClick={handleRunSpeedtest}
                                    disabled={testingSpeed}
                                    className={`rounded-lg px-2.5 py-1 text-xs font-bold text-white transition duration-150 ${
                                        testingSpeed
                                            ? "cursor-not-allowed bg-brand-primary/40"
                                            : "bg-brand-primary hover:bg-brand-primaryHover"
                                    }`}
                                >
                                    {testingSpeed ? "Testing..." : "Test Speed"}
                                </button>
                            </div>

                            {testingSpeed ? (
                                <div className="flex flex-col items-center justify-center space-y-3 py-8 text-center">
                                    <div className="h-10 w-10 animate-spin rounded-full border-4 border-brand-primary/20 border-t-brand-primary" />
                                    <div className="text-xs font-semibold text-slate-900">Running Speedtest...</div>
                                    <div className="text-[9px] text-brand-textMuted">
                                        Mengukur throughput download dan upload.
                                    </div>
                                </div>
                            ) : latestSpeedtest ? (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="rounded-xl border border-brand-border bg-brand-bgSecondary/60 p-3 text-center">
                                            <div className="text-[10px] uppercase text-brand-textSecondary">Download</div>
                                            <div className="mt-1 font-mono text-xl font-black text-brand-primary">
                                                {latestSpeedtest.download_speed_mbps}{" "}
                                                <span className="text-[10px] font-normal text-slate-900">Mbps</span>
                                            </div>
                                        </div>
                                        <div className="rounded-xl border border-brand-border bg-brand-bgSecondary/60 p-3 text-center">
                                            <div className="text-[10px] uppercase text-brand-textSecondary">Upload</div>
                                            <div className="mt-1 font-mono text-xl font-black text-slate-900">
                                                {latestSpeedtest.upload_speed_mbps}{" "}
                                                <span className="text-[10px] font-normal text-brand-textSecondary">
                                                    Mbps
                                                </span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-between border-t border-brand-border/40 pt-3 text-[10px] text-brand-textSecondary">
                                        <span>
                                            Ping:{" "}
                                            <strong className="font-mono text-slate-900">
                                                {latestSpeedtest.ping_ms} ms
                                            </strong>
                                        </span>
                                        {/* Nama ISP hanya ada bila dilaporkan sumber pengukuran. */}
                                        <span className="max-w-[120px] truncate" title={latestSpeedtest.isp ?? undefined}>
                                            ISP:{" "}
                                            <strong className="text-slate-900">
                                                {latestSpeedtest.isp || "tidak terdeteksi"}
                                            </strong>
                                        </span>
                                    </div>

                                    <p className="text-[10px] text-brand-textMuted">
                                        {formatCheckedAt(latestSpeedtest.created_at)}
                                    </p>
                                </div>
                            ) : (
                                <div className="rounded-xl border border-brand-border/40 bg-brand-bgSecondary/30 py-6 text-center">
                                    <p className="text-xs text-brand-textSecondary">
                                        Belum ada hasil uji kecepatan yang tersimpan.
                                    </p>
                                    <button
                                        onClick={handleRunSpeedtest}
                                        className="mt-2 text-xs font-semibold text-brand-primary hover:underline"
                                    >
                                        Jalankan Uji Pertama →
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* Konsol alert: isinya hanya kegagalan monitoring yang benar-benar tercatat. */}
                        <div className="flex flex-col rounded-2xl border border-brand-border bg-brand-card p-6">
                            <h3 className="mb-1 text-sm font-bold text-slate-900">Live Alert Console</h3>
                            <p className="mb-4 text-[10px] text-brand-textSecondary">
                                Notifikasi dari hasil pemindaian terakhir.
                            </p>

                            <div className="max-h-[300px] space-y-3 overflow-y-auto pr-1">
                                {alerts.length > 0 ? (
                                    alerts.map((alert, idx) => (
                                        <div
                                            key={`${alert.device_id}-${idx}`}
                                            className={`flex flex-col rounded-xl border p-3 ${
                                                alert.type === "critical"
                                                    ? "border-rose-200 bg-rose-50"
                                                    : "border-amber-200 bg-amber-50"
                                            }`}
                                        >
                                            <div className="flex items-center justify-between gap-2">
                                                <span
                                                    className={`text-[9px] font-bold uppercase tracking-wider ${
                                                        alert.type === "critical" ? "text-rose-700" : "text-amber-700"
                                                    }`}
                                                >
                                                    {alert.type}
                                                </span>
                                                <span className="text-[9px] text-brand-textMuted">{alert.timestamp}</span>
                                            </div>
                                            <div className="mt-1 text-xs font-bold text-slate-900">
                                                {alert.device_name}
                                            </div>
                                            <div className="mt-0.5 text-xs text-brand-textSecondary">
                                                {alert.message}
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-6 text-center">
                                        {checked === 0 ? (
                                            <>
                                                <div className="mb-2 flex h-6 w-6 items-center justify-center rounded-full border border-slate-200 bg-slate-100 text-xs text-slate-500">
                                                    ?
                                                </div>
                                                <div className="text-xs font-bold text-slate-900">Belum ada data monitoring</div>
                                                <div className="mt-0.5 text-[10px] text-brand-textSecondary">
                                                    Jalankan “Pindai Sekarang” untuk mendapatkan status nyata perangkat.
                                                </div>
                                            </>
                                        ) : (
                                            <>
                                                <div className="mb-2 flex h-6 w-6 items-center justify-center rounded-full border border-brand-primary/25 bg-brand-primary/10 text-xs text-brand-primary">
                                                    ✓
                                                </div>
                                                <div className="text-xs font-bold text-slate-900">
                                                    Tidak ada alert pada pindai terakhir
                                                </div>
                                                <div className="mt-0.5 text-[10px] text-brand-textSecondary">
                                                    {unknown > 0
                                                        ? `${unknown} perangkat belum tercakup pemindaian.`
                                                        : `${checked} perangkat tercakup pemindaian.`}
                                                </div>
                                            </>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CimsLayout>
    );
}
