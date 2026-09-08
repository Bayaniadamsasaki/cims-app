import { CARD, TREND_TONE } from "./theme";
import { IconTrendDown, IconTrendUp } from "./icons";

/**
 * Metric Card ringkasan: label + angka besar sebagai fokus, ikon netral kecil,
 * lalu badge tren di kaki kartu. Tidak lagi memakai lingkaran ikon berwarna —
 * ikon berwarna di tiap kartu adalah pola template dashboard, bukan sistem
 * operasional (cims-design.md §12, §20).
 */
export default function MetricCard({
    title,
    value,
    unit,
    icon: Icon,
    trend,
    caption,
}) {
    const tone = trend?.tone ?? "neutral";
    const TrendIcon = tone === "negative" ? IconTrendDown : IconTrendUp;

    return (
        <article className={`${CARD} p-5`}>
            <div className="flex items-center justify-between gap-3">
                <h3 className="text-sm font-medium text-slate-500">{title}</h3>
                {Icon && <Icon className="h-4 w-4 shrink-0 text-slate-300" aria-hidden="true" />}
            </div>

            <p className="mt-3 text-3xl font-bold tracking-tight text-slate-900 tabular-nums">
                {value}
                {unit && <span className="ml-1 text-base font-semibold text-slate-400">{unit}</span>}
            </p>

            {(trend || caption) && (
                <div className="mt-4 flex flex-wrap items-center gap-x-2 gap-y-1">
                    {trend && (
                        <span
                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${TREND_TONE[tone]}`}
                        >
                            {tone !== "neutral" && <TrendIcon className="h-3.5 w-3.5" />}
                            {trend.label}
                        </span>
                    )}
                    {caption && <span className="text-xs text-slate-500">{caption}</span>}
                </div>
            )}
        </article>
    );
}
