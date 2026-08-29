/**
 * Token warna dashboard CIMS (tema terang, primer biru) — turunan dari
 * `Docs/design_cims_dashboard.md` §3.
 *
 * Catatan kontras: dokumen menyebut pasangan `text-*-600 bg-*-50` untuk badge
 * status. Pada teks kecil pasangan itu masih di bawah WCAG AA 4.5:1
 * (emerald 3.6:1, amber 3.1:1), sehingga teks badge dinaikkan ke shade 700
 * sedangkan titik/ikon tetap shade 500/600 (elemen grafis cukup 3:1).
 */

/**
 * Status perangkat & alert. `label` wajib ikut dirender — warna saja tidak cukup.
 *
 * Kosakata status mengikuti hasil monitoring nyata: `online` (ICMP + metrik
 * terbaca), `degraded` (ICMP jawab, metrik gagal), `unreachable` (tidak ada
 * balasan ICMP), `error` (pemindaian tidak bisa dijalankan), dan `unknown`
 * (belum pernah dipindai). Tidak ada status hasil simulasi.
 */
export const STATUS = {
    online: {
        label: "Online",
        dot: "bg-emerald-500",
        text: "text-emerald-700",
        chip: "bg-emerald-50 text-emerald-700",
        soft: "bg-emerald-50 text-emerald-600",
    },
    degraded: {
        label: "Degraded",
        dot: "bg-amber-500",
        text: "text-amber-700",
        chip: "bg-amber-50 text-amber-700",
        soft: "bg-amber-50 text-amber-600",
    },
    unreachable: {
        label: "Unreachable",
        dot: "bg-red-500",
        text: "text-red-700",
        chip: "bg-red-50 text-red-700",
        soft: "bg-red-50 text-red-600",
    },
    error: {
        label: "Monitoring Error",
        dot: "bg-rose-600",
        text: "text-rose-700",
        chip: "bg-rose-50 text-rose-700",
        soft: "bg-rose-50 text-rose-600",
    },
    offline: {
        label: "Offline",
        dot: "bg-red-500",
        text: "text-red-700",
        chip: "bg-red-50 text-red-700",
        soft: "bg-red-50 text-red-600",
    },
    warning: {
        label: "Warning",
        dot: "bg-amber-500",
        text: "text-amber-700",
        chip: "bg-amber-50 text-amber-700",
        soft: "bg-amber-50 text-amber-600",
    },
    maintenance: {
        label: "Maintenance",
        dot: "bg-amber-500",
        text: "text-amber-700",
        chip: "bg-amber-50 text-amber-700",
        soft: "bg-amber-50 text-amber-600",
    },
    unknown: {
        label: "No Data",
        dot: "bg-slate-400",
        text: "text-slate-600",
        chip: "bg-slate-100 text-slate-600",
        soft: "bg-slate-100 text-slate-600",
    },
};

/**
 * Status yang tidak dikenal dipetakan ke `unknown`, bukan ke `warning` — tanpa
 * data monitoring nyata kita tidak boleh mengarang peringatan maupun status hijau.
 */
export const statusOf = (key) => STATUS[key] ?? STATUS.unknown;

/** Tint lingkaran ikon pada Metric Card (§6A). */
export const ICON_TINT = {
    blue: "bg-blue-50 text-blue-600",
    emerald: "bg-emerald-50 text-emerald-600",
    red: "bg-red-50 text-red-600",
    amber: "bg-amber-50 text-amber-600",
};

/** Badge tren kecil di kaki Metric Card (§6A). */
export const TREND_TONE = {
    positive: "bg-emerald-50 text-emerald-700",
    negative: "bg-red-50 text-red-700",
    neutral: "bg-slate-100 text-slate-600",
};

/** Variasi biru untuk seri chart (§6B) + warna chrome sumbu/grid. */
export const CHART = {
    series: {
        inbound: "#2563EB", // blue-600
        outbound: "#93C5FD", // blue-300
    },
    grid: "#F1F5F9", // slate-100
    axis: "#E2E8F0", // slate-200
    label: "#64748B", // slate-500
};

/** Shell kartu standar (§6A): putih, sudut 2xl, border tipis, shadow lembut. */
export const CARD = "bg-white rounded-2xl border border-slate-100 shadow-sm";

/** Seri chart trafik — datanya berasal dari log monitoring nyata. */
export const TRAFFIC_SERIES = [
    { key: "inbound", label: "Inbound", color: CHART.series.inbound },
    { key: "outbound", label: "Outbound", color: CHART.series.outbound },
];
