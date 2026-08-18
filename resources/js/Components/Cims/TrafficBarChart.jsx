import { useMemo, useRef, useState } from "react";
import { CARD, CHART } from "./theme";

const VIEW = { w: 720, h: 260 };
const PAD = { top: 16, right: 14, bottom: 34, left: 48 };
const INNER_W = VIEW.w - PAD.left - PAD.right;
const INNER_H = VIEW.h - PAD.top - PAD.bottom;
const BAR_GAP = 4;

/** Bulatkan batas atas sumbu Y ke angka bersih agar tick mudah dibaca. */
const niceScale = (max) => {
    if (!(max > 0)) return { top: 10, ticks: [0, 5, 10] };
    const raw = max / 5;
    const mag = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? 10 * mag;
    const top = Math.ceil(max / step) * step;
    const ticks = [];
    for (let v = 0; v <= top + step / 1000; v += step) ticks.push(v);
    return { top, ticks };
};

/** Path batang dengan dua sudut atas melengkung — §6B. */
const barPath = (x, y, w, h, r) => {
    const radius = Math.max(0, Math.min(r, w / 2, h));
    return [
        `M${x},${y + h}`,
        `L${x},${y + radius}`,
        `Q${x},${y} ${x + radius},${y}`,
        `L${x + w - radius},${y}`,
        `Q${x + w},${y} ${x + w},${y + radius}`,
        `L${x + w},${y + h}Z`,
    ].join("");
};

/**
 * Bar chart Network Traffic (§6B) — SVG murni, tanpa dependensi chart library.
 * Menyediakan legend dan tooltip hover sesuai aturan widget chart.
 *
 * @param {Array<Object>} data baris data, tiap baris punya `label` + key seri
 * @param {Array<{key: string, label: string, color: string}>} series
 */
export default function TrafficBarChart({ title, subtitle, unit = "Mbps", data = [], series = [], action }) {
    const svgRef = useRef(null);
    const [hover, setHover] = useState(null);

    const geometry = useMemo(() => {
        const values = data.flatMap((row) => series.map((s) => Number(row[s.key] ?? 0)));
        const { top, ticks } = niceScale(Math.max(...values, 0));
        const slotW = data.length ? INNER_W / data.length : INNER_W;
        const barW = Math.max(4, Math.min(16, (slotW * 0.64 - BAR_GAP * (series.length - 1)) / series.length));
        const groupW = barW * series.length + BAR_GAP * (series.length - 1);
        return {
            top,
            ticks,
            barW,
            slotCenter: (i) => PAD.left + i * slotW + slotW / 2,
            groupX: (i) => PAD.left + i * slotW + (slotW - groupW) / 2,
            yAt: (v) => PAD.top + INNER_H - (Number(v ?? 0) / top) * INNER_H,
        };
    }, [data, series]);

    if (!data.length) {
        return (
            <figure className={`${CARD} flex h-full flex-col p-6`}>
                <Heading title={title} subtitle={subtitle} series={series} action={action} />
                <div className="flex flex-1 items-center justify-center py-16 text-xs text-slate-500">
                    Belum ada data trafik untuk rentang waktu ini.
                </div>
            </figure>
        );
    }

    const { top, ticks, barW, slotCenter, groupX, yAt } = geometry;
    const stride = Math.ceil(data.length / 8);
    const peak = data.reduce((a, b) => (sum(b, series) > sum(a, series) ? b : a), data[0]);

    const handleMove = (event) => {
        const rect = svgRef.current.getBoundingClientRect();
        const ratio = ((event.clientX - rect.left) / rect.width) * VIEW.w - PAD.left;
        const index = Math.floor((ratio / INNER_W) * data.length);
        setHover(index >= 0 && index < data.length ? index : null);
    };

    return (
        <figure className={`${CARD} flex h-full flex-col p-6`}>
            <Heading title={title} subtitle={subtitle} series={series} action={action} />

            {/* Di layar sempit chart digeser horizontal agar label sumbu tetap terbaca. */}
            <div className="mt-5 flex-1 overflow-x-auto">
                <div className="relative min-w-[600px]">
                    <svg
                        ref={svgRef}
                        viewBox={`0 0 ${VIEW.w} ${VIEW.h}`}
                        className="h-auto w-full"
                        role="img"
                        aria-label={`${title}. Puncak trafik pada ${peak.label} sebesar ${sum(peak, series)} ${unit} gabungan.`}
                        onMouseMove={handleMove}
                        onMouseLeave={() => setHover(null)}
                    >
                        {ticks.map((tick) => (
                            <g key={tick}>
                                <line
                                    x1={PAD.left}
                                    x2={PAD.left + INNER_W}
                                    y1={yAt(tick)}
                                    y2={yAt(tick)}
                                    stroke={tick === 0 ? CHART.axis : CHART.grid}
                                />
                                <text
                                    x={PAD.left - 10}
                                    y={yAt(tick) + 4}
                                    textAnchor="end"
                                    fill={CHART.label}
                                    fontSize="11"
                                    style={{ fontVariantNumeric: "tabular-nums" }}
                                >
                                    {tick >= 1000 ? `${tick / 1000}rb` : tick}
                                </text>
                            </g>
                        ))}

                        {data.map((row, i) => (
                            <g key={row.label}>
                                {hover === i && (
                                    <rect
                                        x={PAD.left + (i * INNER_W) / data.length}
                                        y={PAD.top}
                                        width={INNER_W / data.length}
                                        height={INNER_H}
                                        fill={CHART.grid}
                                        opacity="0.7"
                                    />
                                )}
                                {series.map((s, si) => {
                                    const y = yAt(row[s.key]);
                                    return (
                                        <path
                                            key={s.key}
                                            d={barPath(groupX(i) + si * (barW + BAR_GAP), y, barW, PAD.top + INNER_H - y, 4)}
                                            fill={s.color}
                                            opacity={hover === null || hover === i ? 1 : 0.45}
                                            className="transition-opacity duration-150 motion-reduce:transition-none"
                                        />
                                    );
                                })}
                                {(i % stride === 0 || i === data.length - 1) && (
                                    <text
                                        x={slotCenter(i)}
                                        y={VIEW.h - 12}
                                        textAnchor="middle"
                                        fill={CHART.label}
                                        fontSize="11"
                                    >
                                        {row.label}
                                    </text>
                                )}
                            </g>
                        ))}
                    </svg>

                    {hover !== null && (
                        <Tooltip row={data[hover]} series={series} unit={unit} x={slotCenter(hover) / VIEW.w} />
                    )}
                </div>
            </div>
        </figure>
    );
}

const sum = (row, series) => series.reduce((total, s) => total + Number(row[s.key] ?? 0), 0);

function Heading({ title, subtitle, series, action }) {
    return (
        <figcaption className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 className="text-base font-semibold text-slate-900">{title}</h2>
                {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
            </div>
            <div className="flex items-center gap-4">
                <ul className="flex items-center gap-4">
                    {series.map((s) => (
                        <li key={s.key} className="flex items-center gap-1.5 text-xs font-medium text-slate-500">
                            <span className="h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: s.color }} aria-hidden="true" />
                            {s.label}
                        </li>
                    ))}
                </ul>
                {action}
            </div>
        </figcaption>
    );
}

function Tooltip({ row, series, unit, x }) {
    return (
        <div
            className="pointer-events-none absolute top-1 z-10 min-w-[150px] -translate-x-1/2 rounded-xl border border-slate-100 bg-white p-3 shadow-lg"
            style={{ left: `${Math.min(86, Math.max(14, x * 100))}%` }}
        >
            <div className="text-xs font-semibold text-slate-900">{row.label}</div>
            <dl className="mt-1.5 space-y-1">
                {series.map((s) => (
                    <div key={s.key} className="flex items-center justify-between gap-4 text-xs">
                        <dt className="flex items-center gap-1.5 text-slate-500">
                            <span className="h-2 w-2 rounded-sm" style={{ backgroundColor: s.color }} aria-hidden="true" />
                            {s.label}
                        </dt>
                        <dd className="font-semibold tabular-nums text-slate-900">
                            {row[s.key]} {unit}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}
