import { router } from "@inertiajs/react";
import { useEffect, useRef, useState } from "react";
import { STATUS_META, inputClass, labelClass } from "../constants";

/**
 * Baris filter tunggal di atas grafik & tabel.
 * Semua perubahan dikirim sebagai query string sehingga filter ikut terbawa
 * saat pagination, sorting, maupun export.
 */
export default function FilterBar({ filters, options, testers, locationOptions, ssidOptions, perPage, sort }) {
    const [form, setForm] = useState({
        search: filters.search ?? "",
        month: filters.month ?? "",
        date_from: filters.date_from ?? "",
        date_to: filters.date_to ?? "",
        location: filters.location ?? "",
        ssid: filters.ssid ?? "",
        status: filters.status ?? "",
        tester_id: filters.tester_id ?? "",
        action: filters.action ?? "",
    });
    const isFirstRender = useRef(true);

    const visit = (payload) => {
        router.get(route("speedtest-reports.index"), payload, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    // Debounce agar pencarian tidak memicu request pada setiap ketikan.
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        const timer = setTimeout(() => {
            visit({ ...form, per_page: perPage, sort: sort.column, direction: sort.direction });
        }, 350);
        return () => clearTimeout(timer);
    }, [form]);

    const setField = (key, value) => setForm((prev) => ({ ...prev, [key]: value }));

    const activeCount = Object.values(form).filter((value) => value !== "").length;

    const exportUrl = `${route("speedtest-reports.export")}?${new URLSearchParams(
        Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ""))
    ).toString()}`;

    return (
        <section className="rounded-2xl bg-brand-card border border-brand-border p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-slate-900">
                    Filter &amp; Pencarian
                    {activeCount > 0 && (
                        <span className="ml-2 rounded-md border border-brand-primary/30 bg-brand-primary/10 px-2 py-0.5 text-[10px] font-bold text-brand-primary">
                            {activeCount} aktif
                        </span>
                    )}
                </h3>
                <div className="flex items-center gap-2">
                    <a
                        href={exportUrl}
                        className="inline-flex items-center gap-1.5 rounded-xl border border-brand-border bg-brand-bgSecondary px-3 py-2 text-xs font-bold text-slate-900 transition hover:border-brand-primary/50"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                        </svg>
                        Export CSV
                    </a>
                    <button
                        type="button"
                        onClick={() =>
                            setForm({
                                search: "",
                                month: "",
                                date_from: "",
                                date_to: "",
                                location: "",
                                ssid: "",
                                status: "",
                                tester_id: "",
                                action: "",
                            })
                        }
                        className="rounded-xl px-3 py-2 text-xs font-bold text-brand-textSecondary transition hover:text-slate-900"
                    >
                        Reset Filter
                    </button>
                </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                <div className="xl:col-span-2">
                    <label className={labelClass} htmlFor="filter-search">Pencarian</label>
                    <input
                        id="filter-search"
                        type="search"
                        value={form.search}
                        onChange={(e) => setField("search", e.target.value)}
                        placeholder="Cari lokasi, SSID, atau nama penguji..."
                        className={inputClass}
                    />
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-month">Bulan Laporan</label>
                    <input
                        id="filter-month"
                        type="month"
                        value={form.month}
                        onChange={(e) => setField("month", e.target.value)}
                        className={inputClass}
                    />
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-from">Tanggal Dari</label>
                    <input
                        id="filter-from"
                        type="date"
                        value={form.date_from}
                        onChange={(e) => setField("date_from", e.target.value)}
                        className={inputClass}
                    />
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-to">Tanggal Sampai</label>
                    <input
                        id="filter-to"
                        type="date"
                        value={form.date_to}
                        onChange={(e) => setField("date_to", e.target.value)}
                        className={inputClass}
                    />
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-location">Lokasi</label>
                    <select
                        id="filter-location"
                        value={form.location}
                        onChange={(e) => setField("location", e.target.value)}
                        className={inputClass}
                    >
                        <option value="">Semua Lokasi</option>
                        {locationOptions.map((location) => (
                            <option key={location} value={location}>{location}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-ssid">SSID</label>
                    <select
                        id="filter-ssid"
                        value={form.ssid}
                        onChange={(e) => setField("ssid", e.target.value)}
                        className={inputClass}
                    >
                        <option value="">Semua SSID</option>
                        {ssidOptions.map((ssid) => (
                            <option key={ssid} value={ssid}>{ssid}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-status">Status Action</label>
                    <select
                        id="filter-status"
                        value={form.status}
                        onChange={(e) => setField("status", e.target.value)}
                        className={inputClass}
                    >
                        <option value="">Semua Status</option>
                        {Object.entries(STATUS_META).map(([key, meta]) => (
                            <option key={key} value={key}>{meta.label}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-tester">Penguji</label>
                    <select
                        id="filter-tester"
                        value={form.tester_id}
                        onChange={(e) => setField("tester_id", e.target.value)}
                        className={inputClass}
                    >
                        <option value="">Semua Penguji</option>
                        {testers.map((tester) => (
                            <option key={tester.id} value={tester.id}>{tester.name}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label className={labelClass} htmlFor="filter-action">Tindakan</label>
                    <select
                        id="filter-action"
                        value={form.action}
                        onChange={(e) => setField("action", e.target.value)}
                        className={inputClass}
                    >
                        <option value="">Semua Tindakan</option>
                        {Object.entries(options.actions).map(([key, label]) => (
                            <option key={key} value={key}>{label}</option>
                        ))}
                    </select>
                </div>
            </div>
        </section>
    );
}
