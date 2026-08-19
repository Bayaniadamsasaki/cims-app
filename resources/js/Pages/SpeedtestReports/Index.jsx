import { Head, router } from "@inertiajs/react";
import { useState } from "react";
import CimsLayout from "@/Layouts/CimsLayout";
import { useConfirmation } from "@/Components/ConfirmationModal";
import { SERIES } from "./constants";
import SummaryCards from "./Partials/SummaryCards";
import FilterBar from "./Partials/FilterBar";
import LineTrendChart from "./Partials/LineTrendChart";
import StatusDistribution from "./Partials/StatusDistribution";
import ReportTable from "./Partials/ReportTable";
import ReportFormModal from "./Partials/ReportFormModal";
import DetailModal from "./Partials/DetailModal";
import ScreenshotLightbox from "./Partials/ScreenshotLightbox";
import TesterManagerModal from "./Partials/TesterManagerModal";

/**
 * Halaman "Laporan Speedtest Jaringan Bulanan".
 * Menyusun ringkasan, grafik tren, filter, tabel, dan seluruh modal aksi.
 */
export default function Index({
    reports,
    summary,
    trend,
    testers,
    locationOptions,
    ssidOptions,
    options,
    filters,
    sort,
    perPage,
}) {
    const { confirmAction } = useConfirmation();
    const [form, setForm] = useState(null); // { mode, report }
    const [detail, setDetail] = useState(null);
    const [lightbox, setLightbox] = useState(null);
    const [testerManager, setTesterManager] = useState(false);

    const askDelete = async (report) => {
        const confirmed = await confirmAction({
            title: "Hapus Laporan Speedtest",
            message: `Hapus laporan ${report.location} — ${report.ssid} (${report.tested_at_display})? Bukti screenshot ikut terhapus dan tindakan ini tidak dapat dibatalkan.`,
            confirmLabel: "Hapus Laporan",
            cancelLabel: "Batal",
            variant: "danger",
        });
        if (!confirmed) return;
        router.delete(route("speedtest-reports.destroy", report.id), { preserveScroll: true, preserveState: true });
    };

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-bold text-slate-900">Laporan Speedtest Jaringan Bulanan</h2>
                        <p className="mt-0.5 text-xs text-slate-500">
                            Pencatatan, monitoring, dan dokumentasi kualitas jaringan per lokasi &amp; SSID.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setForm({ mode: "create", report: null })}
                        className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-xs font-semibold text-white transition hover:bg-blue-700"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Laporan Speedtest
                    </button>
                </div>
            }
        >
            <Head title="Laporan Speedtest Jaringan Bulanan" />

            {/* Saran isian lokasi & SSID untuk form tambah/edit */}
            <datalist id="speedtest-location-options">
                {locationOptions.map((location) => (
                    <option key={location} value={location} />
                ))}
            </datalist>
            <datalist id="speedtest-ssid-options">
                {ssidOptions.map((ssid) => (
                    <option key={ssid} value={ssid} />
                ))}
            </datalist>

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <SummaryCards summary={summary} />

                <FilterBar
                    filters={filters}
                    options={options}
                    testers={testers}
                    locationOptions={locationOptions}
                    ssidOptions={ssidOptions}
                    perPage={perPage}
                    sort={sort}
                />

                {/* <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    <div className="xl:col-span-2">
                        <LineTrendChart
                            title="Tren kecepatan download & upload"
                            subtitle="Rata-rata per tanggal pengujian, satuan Mbps."
                            unit="Mbps"
                            data={trend}
                            series={[
                                { key: "avgDownload", label: "Download", color: SERIES.download },
                                { key: "avgUpload", label: "Upload", color: SERIES.upload },
                            ]}
                        />
                    </div>
                    <StatusDistribution statusCounts={summary.statusCounts} total={summary.total} />
                </div> */}

                {/* <LineTrendChart
                    title="Tren latensi (ping)"
                    subtitle="Rata-rata ping per tanggal pengujian — semakin rendah semakin baik."
                    unit="ms"
                    data={trend}
                    series={[{ key: "avgPing", label: "Ping", color: SERIES.ping }]}
                /> */}

                <ReportTable
                    reports={reports}
                    filters={filters}
                    sort={sort}
                    perPage={perPage}
                    perPageOptions={options.perPage}
                    onDetail={setDetail}
                    onEdit={(report) => {
                        setDetail(null);
                        setForm({ mode: "edit", report });
                    }}
                    onDelete={askDelete}
                    onPreview={setLightbox}
                />
            </div>

            {form && (
                <ReportFormModal
                    mode={form.mode}
                    report={form.report}
                    testers={testers}
                    options={options}
                    onClose={() => setForm(null)}
                    onManageTesters={() => setTesterManager(true)}
                />
            )}

            {detail && (
                <DetailModal
                    report={detail}
                    onClose={() => setDetail(null)}
                    onEdit={(report) => {
                        setDetail(null);
                        setForm({ mode: "edit", report });
                    }}
                    onPreview={setLightbox}
                />
            )}

            {testerManager && (
                <TesterManagerModal
                    testers={testers}
                    onClose={() => setTesterManager(false)}
                    onConfirm={confirmAction}
                />
            )}

            {lightbox && <ScreenshotLightbox report={lightbox} onClose={() => setLightbox(null)} />}
        </CimsLayout>
    );
}
