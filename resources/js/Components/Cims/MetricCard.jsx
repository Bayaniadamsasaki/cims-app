import { CARD, ICON_TINT, TREND_TONE } from "./theme";
import { IconTrendDown, IconTrendUp } from "./icons";

/**
 * Metric Card ringkasan (§6A): ikon dalam lingkaran tint + judul, angka metrik
 * besar, lalu badge tren kecil di kaki kartu.
 *
 * @param {{tone: "positive"|"negative"|"neutral", label: string}} [trend]
 */
export default function MetricCard({
    title,
    value,
    unit,
    icon: Icon,
    tint = "blue",
    trend,
    caption,
}) {
    const tone = trend?.tone ?? "neutral";
    const TrendIcon = tone === "negative" ? IconTrendDown : IconTrendUp;

    return (
        <article className={`${CARD} p-6`}>
            <div className="flex items-center gap-3">
                <span
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${ICON_TINT[tint]}`}
                >
                    <Icon className="h-5 w-5" />
                </span>
                <h3 className="text-sm font-medium text-slate-500">{title}</h3>
            </div>

            <p className="mt-5 text-3xl font-bold tracking-tight text-slate-900 tabular-nums">
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
