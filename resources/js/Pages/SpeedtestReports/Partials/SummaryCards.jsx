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
            <div className="rounded-2xl bg-white border border-slate-200 p-5">
                <div className="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                    Total pengujian
                </div>
                <div className="mt-1 text-5xl font-extrabold leading-none text-slate-900">{total}</div>
                <p className="mt-2 text-[11px] text-slate-400">Sesuai filter yang sedang aktif.</p>
            </div>

            {Object.entries(STATUS_META).map(([key, meta]) => {
                const count = statusCounts[key] ?? 0;
                const percent = total > 0 ? (count / total) * 100 : 0;
                return (
                    <div key={key} className="rounded-2xl bg-white border border-slate-200 p-5">
                        <div className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-500">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke={meta.color} aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={meta.icon} />
                            </svg>
                            {meta.label}
                        </div>
                        <div className="mt-1 text-3xl font-extrabold text-slate-900">{count}</div>
                        <div className="mt-1 text-[11px] text-slate-400 tabular-nums">
                            {fmt(percent, 1)}% dari total
                        </div>
                    </div>
                );
            })}

            {averages.map((item) => (
                <div key={item.label} className="rounded-2xl bg-white border border-slate-200 p-5">
                    <div className="text-[11px] font-bold uppercase tracking-wide text-slate-500">
                        {item.label}
                    </div>
                    <div className="mt-1 text-3xl font-extrabold text-slate-900">
                        {item.value}
                        <span className="ml-1 text-sm font-bold text-slate-400">{item.unit}</span>
                    </div>
                </div>
            ))}
        </div>
    );
}
