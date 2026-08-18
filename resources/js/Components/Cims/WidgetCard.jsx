import { CARD } from "./theme";

/**
 * Shell kartu widget: judul + subjudul + slot aksi kanan atas.
 * Body dibiarkan tanpa padding supaya tabel/list bisa menempel penuh ke tepi
 * kartu; pemanggil mengatur padding sendiri lewat `bodyClassName`.
 */
export default function WidgetCard({
    title,
    subtitle,
    action,
    className = "",
    bodyClassName = "",
    children,
}) {
    return (
        <section className={`${CARD} ${className}`}>
            <header className="flex items-start justify-between gap-4 px-6 pt-6">
                <div className="min-w-0">
                    <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                    {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </header>
            <div className={bodyClassName}>{children}</div>
        </section>
    );
}
