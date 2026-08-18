import { useMemo, useRef, useState } from "react";
import { CHROME, fmt, fmtDateShort } from "../constants";

const VIEW = { w: 760, h: 240 };
const PAD = { top: 18, right: 68, bottom: 30, left: 50 };
const INNER_W = VIEW.w - PAD.left - PAD.right;
const INNER_H = VIEW.h - PAD.top - PAD.bottom;

/** Bulatkan skala Y ke angka bersih agar tick sumbu mudah dibaca. */
const niceScale = (max) => {
    if (!(max > 0)) return { top: 10, ticks: [0, 2.5, 5, 7.5, 10] };
    const raw = max / 4;
    const mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw) ?? 10 * mag;
    const top = Math.ceil(max / step) * step;
    const ticks = [];
    for (let v = 0; v <= top + step / 1000; v += step) ticks.push(v);
    return { top, ticks };
};

/**
 * Grafik tren garis untuk 1–2 seri dengan satu sumbu Y (satuan sama).
 * Menyediakan crosshair + tooltip sebagai lapisan interaksi bawaan.
 */
export default function LineTrendChart({ title, subtitle, unit, data = [], series = [] }) {
    const svgRef = useRef(null);
    const [hover, setHover] = useState(null);

    const geometry = useMemo(() => {
        const values = data.flatMap((row) => series.map((s) => Number(row[s.key] ?? 0)));
        const { top, ticks } = niceScale(Math.max(...values, 0));
        const n = data.length;
        const xAt = (i) => (n <= 1 ? PAD.left + INNER_W / 2 : PAD.left + (i / (n - 1)) * INNER_W);
        const yAt = (v) => PAD.top + INNER_H - (Number(v ?? 0) / top) * INNER_H;
        return { top, ticks, xAt, yAt };
    }, [data, series]);

    if (!data.length) {
        return (
            <figure className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                <ChartHeading title={title} subtitle={subtitle} series={series} />
                <div className="flex h-40 items-center justify-center text-xs text-brand-textSecondary">
                    Belum ada data pengujian untuk rentang filter ini.
                </div>
            </figure>
        );
    }

    const { top, ticks, xAt, yAt } = geometry;
    const lastIndex = data.length - 1;
    const endLabelsFit =
        series.length < 2 ||
        Math.abs(yAt(data[lastIndex][series[0].key]) - yAt(data[lastIndex][series[1].key])) >= 16;

    const handleMove = (event) => {
        const rect = svgRef.current.getBoundingClientRect();
        const x = ((event.clientX - rect.left) / rect.width) * VIEW.w;
        const ratio = (x - PAD.left) / INNER_W;
        const index = Math.min(lastIndex, Math.max(0, Math.round(ratio * lastIndex)));
        setHover(index);
    };

    return (
        <figure className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
            <ChartHeading title={title} subtitle={subtitle} series={series} />

            <div className="relative mt-3">
                <svg
                    ref={svgRef}
                    viewBox={`0 0 ${VIEW.w} ${VIEW.h}`}
                    className="w-full h-auto"
                    role="img"
                    aria-label={`${title}. Nilai lengkap tersedia pada tabel laporan di bawah.`}
                    onMouseMove={handleMove}
                    onMouseLeave={() => setHover(null)}
                >
                    {/* Gridline hairline + tick sumbu Y */}
                    {ticks.map((tick) => (
                        <g key={tick}>
                            <line
                                x1={PAD.left}
                                x2={PAD.left + INNER_W}
                                y1={yAt(tick)}
                                y2={yAt(tick)}
                                stroke={tick === 0 ? CHROME.axis : CHROME.grid}
                                strokeWidth="1"
                            />
                            <text
                                x={PAD.left - 8}
                                y={yAt(tick) + 4}
                                textAnchor="end"
                                fill={CHROME.muted}
                                fontSize="11"
                                style={{ fontVariantNumeric: "tabular-nums" }}
                            >
                                {tick >= 1000 ? `${tick / 1000}rb` : fmt(tick, 0)}
                            </text>
                        </g>
                    ))}

                    {/* Label sumbu X — hanya sebagian agar tidak bertumpuk */}
                    {data.map((row, index) => {
                        const stride = Math.ceil(data.length / 7);
                        if (index % stride !== 0 && index !== lastIndex) return null;
                        return (
                            <text
                                key={row.date}
                                x={xAt(index)}
                                y={VIEW.h - 10}
                                textAnchor="middle"
                                fill={CHROME.muted}
                                fontSize="11"
                            >
                                {fmtDateShort(row.date)}
                            </text>
                        );
                    })}

                    {/* Crosshair */}
                    {hover !== null && (
                        <line
                            x1={xAt(hover)}
                            x2={xAt(hover)}
                            y1={PAD.top}
                            y2={PAD.top + INNER_H}
                            stroke={CHROME.muted}
                            strokeWidth="1"
                        />
                    )}

                    {series.map((s) => {
                        const line = data.map((row, i) => `${i === 0 ? "M" : "L"}${xAt(i)},${yAt(row[s.key])}`).join(" ");
                        const area = `${line} L${xAt(lastIndex)},${yAt(0)} L${xAt(0)},${yAt(0)} Z`;
                        return (
                            <g key={s.key}>
                                {data.length > 1 && <path d={area} fill={s.color} opacity="0.1" />}
                                {data.length > 1 && (
                                    <path
                                        d={line}
                                        fill="none"
                                        stroke={s.color}
                                        strokeWidth="2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    />
                                )}
                                {/* Marker titik akhir dengan ring warna permukaan */}
                                <circle
                                    cx={xAt(lastIndex)}
                                    cy={yAt(data[lastIndex][s.key])}
                                    r="4"
                                    fill={s.color}
                                    stroke={CHROME.surface}
                                    strokeWidth="2"
                                />
                                {endLabelsFit && (
                                    <text
                                        x={xAt(lastIndex) + 10}
                                        y={yAt(data[lastIndex][s.key]) + 4}
                                        fill="#c3c2b7"
                                        fontSize="11"
                                        fontWeight="700"
                                        style={{ fontVariantNumeric: "tabular-nums" }}
                                    >
                                        {fmt(data[lastIndex][s.key])}
                                    </text>
                                )}
                                {hover !== null && (
                                    <circle
                                        cx={xAt(hover)}
                                        cy={yAt(data[hover][s.key])}
                                        r="4"
                                        fill={s.color}
                                        stroke={CHROME.surface}
                                        strokeWidth="2"
                                    />
                                )}
                            </g>
                        );
                    })}
                </svg>

                {hover !== null && (
                    <div
                        className="pointer-events-none absolute top-0 z-10 min-w-[150px] -translate-x-1/2 rounded-xl border border-brand-border bg-brand-cardElevated p-3 shadow-2xl"
                        style={{
                            left: `${Math.min(88, Math.max(12, (xAt(hover) / VIEW.w) * 100))}%`,
                        }}
                    >
                        <div className="text-[11px] font-bold text-white">{fmtDateShort(data[hover].date)}</div>
                        <div className="mt-1 space-y-1">
                            {series.map((s) => (
                                <div key={s.key} className="flex items-center justify-between gap-3 text-[11px]">
                                    <span className="flex items-center gap-1.5 text-brand-textSecondary">
                                        <span className="h-0.5 w-3 rounded-full" style={{ backgroundColor: s.color }} />
                                        {s.label}
                                    </span>
                                    <span className="font-bold text-white tabular-nums">
                                        {fmt(data[hover][s.key])} {unit}
                                    </span>
                                </div>
                            ))}
                            <div className="flex items-center justify-between gap-3 border-t border-brand-border pt-1 text-[11px] text-brand-textMuted">
                                <span>Jumlah pengujian</span>
                                <span className="font-bold tabular-nums">{data[hover].total}</span>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </figure>
    );
}

function ChartHeading({ title, subtitle, series }) {
    return (
        <figcaption className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 className="text-sm font-bold text-white">{title}</h3>
                {subtitle && <p className="text-[11px] text-brand-textSecondary mt-0.5">{subtitle}</p>}
            </div>
            {/* Legend wajib untuk 2 seri atau lebih; satu seri cukup dijelaskan judul */}
            {series.length >= 2 && (
                <ul className="flex items-center gap-4">
                    {series.map((s) => (
                        <li key={s.key} className="flex items-center gap-1.5 text-[11px] font-semibold text-brand-textSecondary">
                            <span className="h-0.5 w-4 rounded-full" style={{ backgroundColor: s.color }} />
                            {s.label}
                        </li>
                    ))}
                </ul>
            )}
        </figcaption>
    );
}
