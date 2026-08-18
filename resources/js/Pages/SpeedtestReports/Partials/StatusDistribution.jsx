import { useState } from "react";
import { STATUS_META, fmt } from "../constants";

/**
 * Distribusi status jaringan sebagai bar horizontal.
 * Identitas status dibawa oleh ikon + label, bukan warna saja.
 */
export default function StatusDistribution({ statusCounts = {}, total = 0 }) {
    const [hovered, setHovered] = useState(null);
    const max = Math.max(...Object.values(statusCounts), 1);

    return (
        <figure className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
            <figcaption>
                <h3 className="text-sm font-bold text-slate-900">Distribusi Status Jaringan</h3>
                <p className="text-[11px] text-brand-textSecondary mt-0.5">
                    Sebaran kondisi jaringan dari {total} pengujian pada rentang filter aktif.
                </p>
            </figcaption>

            <ul className="mt-4 space-y-3">
                {Object.entries(STATUS_META).map(([key, meta]) => {
                    const count = statusCounts[key] ?? 0;
                    const percent = total > 0 ? (count / total) * 100 : 0;
                    return (
                        <li
                            key={key}
                            className="relative"
                            onMouseEnter={() => setHovered(key)}
                            onMouseLeave={() => setHovered(null)}
                        >
                            <div className="flex items-center justify-between text-[11px] font-semibold">
                                <span className="flex items-center gap-1.5 text-brand-textSecondary">
                                    <svg
                                        className="h-3.5 w-3.5"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke={meta.color}
                                        aria-hidden="true"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={meta.icon} />
                                    </svg>
                                    {meta.label}
                                </span>
                                <span className="text-slate-900 tabular-nums">
                                    {count} <span className="text-brand-textMuted font-normal">({fmt(percent, 1)}%)</span>
                                </span>
                            </div>

                            <div className="mt-1.5 h-2.5 w-full rounded-l-sm bg-brand-bgSecondary">
                                <div
                                    className="h-2.5 rounded-l-sm rounded-r-[4px] transition-[width] duration-300"
                                    style={{
                                        width: `${(count / max) * 100}%`,
                                        backgroundColor: meta.color,
                                        opacity: hovered && hovered !== key ? 0.45 : 1,
                                    }}
                                />
                            </div>

                            {hovered === key && (
                                <div className="pointer-events-none absolute right-0 -top-1 z-10 translate-y-[-100%] rounded-xl border border-brand-border bg-brand-cardElevated px-3 py-2 text-[11px] shadow-2xl">
                                    <span className="font-bold text-slate-900">{meta.label}</span>
                                    <span className="text-brand-textSecondary">
                                        {" "}
                                        — {count} pengujian ({fmt(percent, 1)}% dari total)
                                    </span>
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </figure>
    );
}
