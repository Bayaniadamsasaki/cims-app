import { STATUS_META, fmt } from "../constants";

/**
 * Summary card ringkasan kondisi jaringan.
 * Angka dihitung dari seluruh data yang lolos filter, bukan hanya halaman aktif.
 */
export default function SummaryCards({ summary }) {
    const { total = 0, statusCounts = {}, avgDownload = 0, avgUpload = 0, avgPing = 0 } = summary ?? {};

    const averages = [
        { label: "Rata-rata download", value: fmt(avgDownload), unit: "Mbps" },
        { label: "Rata-rata upload", value: fmt(avgUpload), unit: "Mbps" },
        { label: "Rata-rata ping", value: fmt(avgPing), unit: "ms" },
    ];

    return (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {/* Hero figure — satu-satunya angka display pada halaman ini */}
            <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                <div className="text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">
                    Total pengujian
                </div>
                <div className="mt-1 text-5xl font-extrabold leading-none text-white">{total}</div>
                <p className="mt-2 text-[11px] text-brand-textMuted">Sesuai filter yang sedang aktif.</p>
            </div>

            {Object.entries(STATUS_META).map(([key, meta]) => {
                const count = statusCounts[key] ?? 0;
                const percent = total > 0 ? (count / total) * 100 : 0;
                return (
                    <div key={key} className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                        <div className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke={meta.color} aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={meta.icon} />
                            </svg>
                            {meta.label}
                        </div>
                        <div className="mt-1 text-3xl font-extrabold text-white">{count}</div>
                        <div className="mt-1 text-[11px] text-brand-textMuted tabular-nums">
                            {fmt(percent, 1)}% dari total
                        </div>
                    </div>
                );
            })}

            {averages.map((item) => (
                <div key={item.label} className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                    <div className="text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">
                        {item.label}
                    </div>
                    <div className="mt-1 text-3xl font-extrabold text-white">
                        {item.value}
                        <span className="ml-1 text-sm font-bold text-brand-textMuted">{item.unit}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}
