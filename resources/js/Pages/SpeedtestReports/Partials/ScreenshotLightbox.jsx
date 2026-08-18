import { useEffect } from "react";

/** Lightbox bukti screenshot; ditutup dengan tombol, klik latar, atau Escape. */
export default function ScreenshotLightbox({ report, onClose }) {
    useEffect(() => {
        const onKey = (event) => event.key === "Escape" && onClose();
        window.addEventListener("keydown", onKey);
        return () => window.removeEventListener("keydown", onKey);
    }, [onClose]);

    if (!report?.screenshot_url) return null;

    return (
        <div
            className="fixed inset-0 z-[60] flex flex-col items-center justify-center bg-slate-950/90 p-4 backdrop-blur-sm"
            onClick={onClose}
            role="dialog"
            aria-modal="true"
            aria-label="Pratinjau bukti screenshot"
        >
            <div className="flex w-full max-w-5xl items-start justify-between gap-4" onClick={(e) => e.stopPropagation()}>
                <div className="min-w-0">
                    <p className="truncate text-sm font-bold text-white">{report.screenshot_name}</p>
                    <p className="mt-0.5 text-xs text-brand-textSecondary">
                        {report.location} &middot; {report.ssid} &middot; {report.tested_at_display}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <a
                        href={report.screenshot_url}
                        download={report.screenshot_name}
                        className="rounded-lg border border-brand-border bg-brand-card px-3 py-1.5 text-[11px] font-bold text-brand-primary transition hover:border-brand-primary/50"
                    >
                        Download
                    </a>
                    <a
                        href={report.screenshot_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="rounded-lg border border-brand-border bg-brand-card px-3 py-1.5 text-[11px] font-bold text-sky-400 transition hover:border-sky-500/50"
                    >
                        Buka Tab Baru
                    </a>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg border border-brand-border bg-brand-card p-1.5 text-brand-textSecondary transition hover:text-white"
                        aria-label="Tutup pratinjau"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            <img
                src={report.screenshot_url}
                alt={`Bukti screenshot ${report.location} — ${report.ssid}`}
                onClick={(e) => e.stopPropagation()}
                className="mt-3 max-h-[80vh] w-auto max-w-full rounded-xl border border-brand-border object-contain shadow-2xl"
            />
        </div>
    );
}
