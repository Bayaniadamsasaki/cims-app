import { router } from "@inertiajs/react";
import { ACTION_META, STATUS_META, fmt, inputClass } from "../constants";

const SORTABLE = {
    tested_at: "Tanggal Action",
    download_mbps: "Download",
    upload_mbps: "Upload",
    ping_ms: "Ping",
};

/** Tabel laporan: sorting, pagination, dan aksi per baris. */
export default function ReportTable({
    reports,
    filters,
    sort,
    perPage,
    perPageOptions,
    onDetail,
    onEdit,
    onDelete,
    onPreview,
}) {
    const rows = reports?.data ?? [];

    const visit = (overrides) => {
        router.get(
            route("speedtest-reports.index"),
            { ...filters, per_page: perPage, sort: sort.column, direction: sort.direction, ...overrides },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    };

    const toggleSort = (column) => {
        const direction = sort.column === column && sort.direction === "desc" ? "asc" : "desc";
        visit({ sort: column, direction, page: 1 });
    };

    const SortHeader = ({ column, className = "" }) => {
        const active = sort.column === column;
        return (
            <th scope="col" className={`px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary ${className}`}>
                <button
                    type="button"
                    onClick={() => toggleSort(column)}
                    className={`inline-flex items-center gap-1 transition hover:text-white ${active ? "text-brand-primary" : ""}`}
                    aria-label={`Urutkan berdasarkan ${SORTABLE[column]}`}
                >
                    {SORTABLE[column]}
                    <svg className="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth="2"
                            d={active && sort.direction === "asc" ? "M5 15l7-7 7 7" : "M19 9l-7 7-7-7"}
                        />
                    </svg>
                </button>
            </th>
        );
    };

    return (
        <section className="rounded-2xl bg-brand-card border border-brand-border shadow-lg overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-brand-border px-5 py-4">
                <h3 className="text-sm font-bold text-white">
                    Tabel Laporan
                    <span className="ml-2 text-[11px] font-normal text-brand-textSecondary">
                        Menampilkan {reports.from ?? 0}–{reports.to ?? 0} dari {reports.total ?? 0} laporan
                    </span>
                </h3>
                <label className="flex items-center gap-2 text-[11px] font-bold uppercase text-brand-textSecondary">
                    Data per halaman
                    <select
                        value={perPage}
                        onChange={(e) => visit({ per_page: e.target.value, page: 1 })}
                        className={`${inputClass} w-auto py-1.5`}
                    >
                        {perPageOptions.map((option) => (
                            <option key={option} value={option}>{option}</option>
                        ))}
                    </select>
                </label>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-brand-border text-left">
                    <thead className="bg-brand-bgSecondary/40">
                        <tr>
                            <th scope="col" className="py-3.5 pl-6 pr-3 text-xs font-bold uppercase text-brand-textSecondary">No</th>
                            <SortHeader column="tested_at" />
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Lokasi / SSID</th>
                            <SortHeader column="download_mbps" className="text-right" />
                            <SortHeader column="upload_mbps" className="text-right" />
                            <SortHeader column="ping_ms" className="text-right" />
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Status</th>
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Perangkat</th>
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Penguji</th>
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Tindakan</th>
                            <th scope="col" className="px-3 py-3.5 text-xs font-bold uppercase text-brand-textSecondary">Bukti</th>
                            <th scope="col" className="py-3.5 pr-6 text-right text-xs font-bold uppercase text-brand-textSecondary">Aksi</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-brand-border/60">
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan="12" className="py-12 text-center text-sm text-brand-textSecondary">
                                    Belum ada laporan speedtest yang cocok dengan filter ini.
                                </td>
                            </tr>
                        )}

                        {rows.map((report, index) => {
                            const status = STATUS_META[report.status] ?? {};
                            const action = ACTION_META[report.action] ?? {};
                            return (
                                <tr key={report.id} className="transition hover:bg-brand-bgSecondary/30">
                                    <td className="py-4 pl-6 pr-3 text-sm font-bold text-brand-textMuted tabular-nums">
                                        {(reports.from ?? 1) + index}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                        {report.tested_at_display}
                                    </td>
                                    <td className="px-3 py-4 text-sm">
                                        <div className="font-bold text-white">{report.location}</div>
                                        <div className="text-xs text-brand-textSecondary">{report.ssid}</div>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-right text-sm font-bold text-white tabular-nums">
                                        {fmt(report.download_mbps)}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-right text-sm font-bold text-white tabular-nums">
                                        {fmt(report.upload_mbps)}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-right text-sm font-bold text-white tabular-nums">
                                        {fmt(report.ping_ms)}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4">
                                        <span
                                            className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-bold"
                                            style={{
                                                color: status.color,
                                                backgroundColor: `${status.color}1A`,
                                                borderColor: `${status.color}40`,
                                            }}
                                        >
                                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={status.icon} />
                                            </svg>
                                            {status.label}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                        {report.device_type_label}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4 text-sm font-semibold text-brand-textSecondary">
                                        {report.tester?.name ?? "-"}
                                    </td>
                                    <td className="whitespace-nowrap px-3 py-4">
                                        <span className={`inline-flex rounded-md border px-2.5 py-1 text-xs font-bold ${action.tone}`}>
                                            {action.label}
                                        </span>
                                    </td>
                                    <td className="px-3 py-4">
                                        {report.screenshot_url ? (
                                            <button
                                                type="button"
                                                onClick={() => onPreview(report)}
                                                title={`Preview ${report.screenshot_name}`}
                                                className="block h-10 w-16 overflow-hidden rounded-lg border border-brand-border transition hover:border-brand-primary"
                                            >
                                                <img
                                                    src={report.screenshot_url}
                                                    alt={`Bukti screenshot ${report.location} — ${report.ssid}`}
                                                    className="h-full w-full object-cover"
                                                />
                                            </button>
                                        ) : (
                                            <span className="text-xs text-brand-textMuted">Tidak ada</span>
                                        )}
                                    </td>
                                    <td className="whitespace-nowrap py-4 pr-6 text-right text-xs font-bold">
                                        <button onClick={() => onDetail(report)} className="text-sky-400 hover:underline">Detail</button>
                                        <button onClick={() => onEdit(report)} className="ml-3 text-brand-primary hover:underline">Edit</button>
                                        <button onClick={() => onDelete(report)} className="ml-3 text-rose-400 hover:underline">Hapus</button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {reports.last_page > 1 && (
                <nav className="flex flex-wrap items-center justify-center gap-1 border-t border-brand-border px-5 py-4" aria-label="Navigasi halaman">
                    {reports.links.map((link, index) => (
                        <button
                            key={index}
                            type="button"
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                            className={`min-w-[36px] rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                link.active
                                    ? "bg-brand-primary text-slate-950"
                                    : link.url
                                      ? "text-brand-textSecondary hover:bg-brand-bgSecondary hover:text-white"
                                      : "cursor-not-allowed text-brand-textMuted/40"
                            }`}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </nav>
            )}
        </section>
    );
}
