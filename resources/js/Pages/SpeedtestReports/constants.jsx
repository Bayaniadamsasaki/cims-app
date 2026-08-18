/**
 * Konstanta tampilan untuk modul Laporan Speedtest Jaringan Bulanan.
 *
 * Palet warna grafik diambil dari palet data-viz tervalidasi (mode gelap)
 * dan sudah lolos pemeriksaan lightness band, chroma, separasi CVD, serta
 * kontras terhadap permukaan kartu (#161A18).
 */

// Slot kategorikal — dipakai untuk seri grafik.
export const SERIES = {
    download: "#3987e5", // slot 1 (blue)
    upload: "#d95926", // slot 2 (orange)
    ping: "#199e70", // slot 3 (aqua)
};

// Warna chrome grafik (mode gelap).
export const CHROME = {
    surface: "#161A18",
    grid: "#2c2c2a",
    axis: "#383835",
    muted: "#898781",
};

// Palet status bersifat tetap dan selalu tampil bersama ikon + label,
// sehingga makna status tidak pernah bergantung pada warna saja.
export const STATUS_META = {
    lancar: {
        label: "Lancar",
        color: "#0ca30c",
        icon: "M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z",
    },
    sedang: {
        label: "Sedang",
        color: "#fab219",
        icon: "M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z",
    },
    lambat: {
        label: "Lambat",
        color: "#ec835a",
        icon: "M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z",
    },
    terputus: {
        label: "Terputus",
        color: "#d03b3b",
        icon: "M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z",
    },
};

export const ACTION_META = {
    maintenance: { label: "Maintenance", tone: "text-amber-300 border-amber-500/30 bg-amber-500/10" },
    selesai: { label: "Selesai", tone: "text-emerald-300 border-emerald-500/30 bg-emerald-500/10" },
    monitoring_traffic: { label: "Monitoring Traffic", tone: "text-sky-300 border-sky-500/30 bg-sky-500/10" },
};

/** Format angka desimal dengan pemisah ribuan Indonesia. */
export const fmt = (value, digits = 2) =>
    Number(value ?? 0).toLocaleString("id-ID", {
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    });

/** Format tanggal singkat dari string "YYYY-MM-DD" tanpa konversi zona waktu. */
export const fmtDateShort = (value) => {
    const [year, month, day] = String(value).slice(0, 10).split("-").map(Number);
    return new Date(year, month - 1, day).toLocaleDateString("id-ID", {
        day: "numeric",
        month: "short",
    });
};

/** Kelas input yang dipakai seragam pada form & filter modul ini. */
export const inputClass =
    "w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white text-sm py-2 px-3 focus:outline-none focus:border-brand-primary placeholder:text-brand-textMuted";

export const labelClass =
    "block text-[11px] font-bold text-brand-textSecondary uppercase tracking-wide mb-1";
