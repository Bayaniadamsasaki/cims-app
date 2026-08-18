import { ACTION_META, STATUS_META, fmt } from "../constants";

/** Detail lengkap satu laporan speedtest. */
export default function DetailModal({ report, onClose, onEdit, onPreview }) {
    const status = STATUS_META[report.status] ?? {};
    const action = ACTION_META[report.action] ?? {};

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/70 p-4 backdrop-blur-sm">
            <div className="my-8 w-full max-w-2xl rounded-2xl border border-brand-border bg-brand-card p-6 shadow-2xl">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h3 className="text-lg font-bold text-white">Detail Laporan Speedtest</h3>
                        <p className="mt-0.5 text-xs text-brand-textSecondary">
                            {report.location} &middot; {report.ssid} &middot; {report.tested_at_display}
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-brand-textSecondary transition hover:text-white" aria-label="Tutup detail">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Metrik utama */}
                <div className="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Metric label="Download" value={fmt(report.download_mbps)} unit="Mbps" />
                    <Metric label="Upload" value={fmt(report.upload_mbps)} unit="Mbps" />
                    <Metric label="Ping" value={fmt(report.ping_ms)} unit="ms" />
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <Row label="Tanggal Action" value={report.tested_at_display} />
                    <Row label="Lokasi" value={report.location} />
                    <Row label="SSID" value={report.ssid} />
                    <Row label="Perangkat Uji Coba" value={report.device_type_label} />
                    <Row label="Penguji" value={report.tester?.name ?? "-"} />
                    <Row label="Status Action">
                        <span
                            className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-bold"
                            style={{ color: status.color, backgroundColor: `${status.color}1A`, borderColor: `${status.color}40` }}
                        >
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={status.icon} />
                            </svg>
                            {status.label}
                        </span>
                    </Row>
                    <Row label="Tindakan / Action">
                        <span className={`inline-flex rounded-md border px-2.5 py-1 text-xs font-bold ${action.tone}`}>{action.label}</span>
                    </Row>
                </dl>

                {/* Bukti screenshot */}
                <div className="mt-5 rounded-xl border border-brand-border bg-brand-bgSecondary/40 p-4">
                    <span className="mb-2 block text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">Bukti Screenshot</span>
                    {report.screenshot_url ? (
                        <div className="flex flex-wrap items-start gap-4">
                            <button
                                type="button"
                                onClick={() => onPreview(report)}
                                className="block h-24 w-40 shrink-0 overflow-hidden rounded-lg border border-brand-border transition hover:border-brand-primary"
                            >
                                <img
                                    src={report.screenshot_url}
                                    alt={`Bukti screenshot ${report.location} — ${report.ssid}`}
                                    className="h-full w-full object-cover"
                                />
                            </button>
                            <div className="min-w-[200px] flex-1 space-y-2">
                                <p className="truncate text-[11px] font-semibold text-white">{report.screenshot_name}</p>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => onPreview(report)}
                                        className="rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-sky-400 transition hover:border-sky-500/50"
                                    >
                                        Preview
                                    </button>
                                    <a
                                        href={report.screenshot_url}
                                        download={report.screenshot_name}
                                        className="rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-brand-primary transition hover:border-brand-primary/50"
                                    >
                                        Download
                                    </a>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="text-xs text-brand-textMuted">Tidak ada bukti screenshot pada laporan ini.</p>
                    )}
                </div>

                <div className="mt-5 flex items-center justify-end gap-2 border-t border-brand-border pt-4">
                    <button type="button" onClick={onClose} className="px-4 py-2 text-xs font-bold text-brand-textSecondary transition hover:text-white">
                        Tutup
                    </button>
                    <button
                        type="button"
                        onClick={() => onEdit(report)}
                        className="rounded-xl bg-brand-primary px-5 py-2.5 text-xs font-bold text-slate-950 transition hover:bg-brand-primaryHover"
                    >
                        Edit Laporan
                    </button>
                </div>
            </div>
        </div>
    );
}

function Metric({ label, value, unit }) {
    return (
        <div className="rounded-xl border border-brand-border bg-brand-bgSecondary/40 p-4">
            <p className="text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">{label}</p>
            <p className="mt-1 text-2xl font-extrabold text-white tabular-nums">
                {value}
                <span className="ml-1 text-xs font-bold text-brand-textMuted">{unit}</span>
            </p>
        </div>
    );
}

function Row({ label, value, children }) {
    return (
        <div>
            <dt className="text-[11px] font-bold uppercase tracking-wide text-brand-textSecondary">{label}</dt>
            <dd className="mt-1 text-sm font-semibold text-white">{children ?? value}</dd>
        </div>
    );
}
