import { useForm } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { STATUS_META, inputClass, labelClass } from "../constants";

const emptyValues = {
    tested_at: "",
    location: "",
    ssid: "",
    download_mbps: "",
    upload_mbps: "",
    ping_ms: "",
    status: "lancar",
    device_type: "laptop",
    tester_id: "",
    action: "monitoring_traffic",
    screenshot: null,
    remove_screenshot: false,
};

/**
 * Form tambah / edit laporan speedtest.
 * Update dikirim via POST karena membawa file upload (multipart/form-data).
 */
export default function ReportFormModal({ mode = "create", report = null, testers = [], options, onClose, onManageTesters }) {
    const isEdit = mode === "edit";
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm(
        isEdit
            ? {
                  tested_at: report.tested_at_input ?? "",
                  location: report.location ?? "",
                  ssid: report.ssid ?? "",
                  download_mbps: report.download_mbps ?? "",
                  upload_mbps: report.upload_mbps ?? "",
                  ping_ms: report.ping_ms ?? "",
                  status: report.status ?? "lancar",
                  device_type: report.device_type ?? "laptop",
                  tester_id: report.tester_id ?? "",
                  action: report.action ?? "monitoring_traffic",
                  screenshot: null,
                  remove_screenshot: false,
              }
            : { ...emptyValues, tester_id: testers[0]?.id ?? "" }
    );

    const [localPreview, setLocalPreview] = useState(null);

    // Bersihkan object URL supaya tidak menyisakan memori saat modal ditutup.
    useEffect(() => () => localPreview && URL.revokeObjectURL(localPreview), [localPreview]);

    const pickFile = (file) => {
        if (localPreview) URL.revokeObjectURL(localPreview);
        setLocalPreview(file ? URL.createObjectURL(file) : null);
        setData((previous) => ({ ...previous, screenshot: file ?? null, remove_screenshot: false }));
    };

    const removeExisting = () => {
        if (localPreview) URL.revokeObjectURL(localPreview);
        setLocalPreview(null);
        setData((previous) => ({ ...previous, screenshot: null, remove_screenshot: true }));
    };

    const submit = (event) => {
        event.preventDefault();
        clearErrors();
        const url = isEdit ? route("speedtest-reports.update", report.id) : route("speedtest-reports.store");
        post(url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const existingUrl = isEdit && !data.remove_screenshot ? report.screenshot_url : null;
    const previewUrl = localPreview ?? existingUrl;
    const previewName = data.screenshot?.name ?? (existingUrl ? report.screenshot_name : null);

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-sm">
            <div className="my-8 w-full max-w-3xl rounded-2xl border border-brand-border bg-brand-card p-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">
                            {isEdit ? "Edit Laporan Speedtest" : "Tambah Laporan Speedtest"}
                        </h3>
                        <p className="mt-0.5 text-xs text-brand-textSecondary">
                            Nomor laporan dibuat otomatis oleh sistem, tidak perlu diisi manual.
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-brand-textSecondary transition hover:text-slate-900" aria-label="Tutup form">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form onSubmit={submit} className="mt-5 space-y-4">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <Field label="Tanggal Action" error={errors.tested_at} htmlFor="tested_at">
                            <input
                                id="tested_at"
                                type="datetime-local"
                                value={data.tested_at}
                                onChange={(e) => setData("tested_at", e.target.value)}
                                className={inputClass}
                                required
                            />
                        </Field>

                        <Field label="Lokasi" error={errors.location} htmlFor="location">
                            <input
                                id="location"
                                type="text"
                                list="speedtest-location-options"
                                value={data.location}
                                onChange={(e) => setData("location", e.target.value)}
                                placeholder="Contoh: Gedung Rektorat Lt. 1"
                                className={inputClass}
                                required
                            />
                        </Field>

                        <Field label="SSID" error={errors.ssid} htmlFor="ssid">
                            <input
                                id="ssid"
                                type="text"
                                list="speedtest-ssid-options"
                                value={data.ssid}
                                onChange={(e) => setData("ssid", e.target.value)}
                                placeholder="Contoh: CIMS-Staff"
                                className={inputClass}
                                required
                            />
                        </Field>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <Field label="Download (Mbps)" error={errors.download_mbps} htmlFor="download_mbps">
                            <input
                                id="download_mbps"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={data.download_mbps}
                                onChange={(e) => setData("download_mbps", e.target.value)}
                                className={inputClass}
                                required
                            />
                        </Field>

                        <Field label="Upload (Mbps)" error={errors.upload_mbps} htmlFor="upload_mbps">
                            <input
                                id="upload_mbps"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={data.upload_mbps}
                                onChange={(e) => setData("upload_mbps", e.target.value)}
                                className={inputClass}
                                required
                            />
                        </Field>

                        <Field label="Ping (ms)" error={errors.ping_ms} htmlFor="ping_ms">
                            <input
                                id="ping_ms"
                                type="number"
                                step="0.01"
                                min="0"
                                inputMode="decimal"
                                value={data.ping_ms}
                                onChange={(e) => setData("ping_ms", e.target.value)}
                                className={inputClass}
                                required
                            />
                        </Field>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <Field label="Status Action" error={errors.status} htmlFor="status">
                            <select id="status" value={data.status} onChange={(e) => setData("status", e.target.value)} className={inputClass}>
                                {Object.entries(STATUS_META).map(([key, meta]) => (
                                    <option key={key} value={key}>{meta.label}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Perangkat Uji Coba" error={errors.device_type} htmlFor="device_type">
                            <select id="device_type" value={data.device_type} onChange={(e) => setData("device_type", e.target.value)} className={inputClass}>
                                {Object.entries(options.deviceTypes).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Penguji" error={errors.tester_id} htmlFor="tester_id">
                            <div className="flex gap-2">
                                <select id="tester_id" value={data.tester_id} onChange={(e) => setData("tester_id", e.target.value)} className={inputClass}>
                                    <option value="">Pilih penguji</option>
                                    {testers.map((tester) => (
                                        <option key={tester.id} value={tester.id}>{tester.name}</option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    onClick={onManageTesters}
                                    title="Kelola daftar penguji"
                                    className="shrink-0 rounded-xl border border-brand-border bg-brand-bgSecondary px-3 text-sm font-bold text-brand-primary transition hover:border-brand-primary/50"
                                >
                                    +
                                </button>
                            </div>
                        </Field>

                        <Field label="Tindakan / Action" error={errors.action} htmlFor="action">
                            <select id="action" value={data.action} onChange={(e) => setData("action", e.target.value)} className={inputClass}>
                                {Object.entries(options.actions).map(([key, label]) => (
                                    <option key={key} value={key}>{label}</option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    {/* Bukti Screenshot */}
                    <div className="rounded-xl border border-brand-border bg-brand-bgSecondary/40 p-4">
                        <span className={labelClass}>Bukti Screenshot</span>
                        <div className="flex flex-wrap items-start gap-4">
                            {previewUrl ? (
                                <a
                                    href={previewUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="block h-20 w-32 shrink-0 overflow-hidden rounded-lg border border-brand-border transition hover:border-brand-primary"
                                >
                                    <img src={previewUrl} alt="Pratinjau bukti screenshot" className="h-full w-full object-cover" />
                                </a>
                            ) : (
                                <div className="flex h-20 w-32 shrink-0 items-center justify-center rounded-lg border border-dashed border-brand-border text-[10px] text-brand-textMuted">
                                    Belum ada file
                                </div>
                            )}

                            <div className="min-w-[220px] flex-1 space-y-2">
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(e) => pickFile(e.target.files?.[0] ?? null)}
                                    className="w-full text-xs text-brand-textSecondary file:mr-3 file:rounded-xl file:border-0 file:bg-brand-primary file:px-4 file:py-2 file:text-xs file:font-bold file:text-white hover:file:bg-brand-primaryHover"
                                />
                                <p className="text-[10px] text-brand-textMuted">Format JPG, JPEG, PNG, atau WEBP. Maksimal 4 MB.</p>
                                {previewName && <p className="truncate text-[11px] font-semibold text-slate-900">{previewName}</p>}

                                <div className="flex flex-wrap gap-2">
                                    {previewUrl && (
                                        <a
                                            href={previewUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-sky-700 transition hover:border-sky-500/50"
                                        >
                                            Preview
                                        </a>
                                    )}
                                    {existingUrl && !localPreview && (
                                        <a
                                            href={existingUrl}
                                            download={report.screenshot_name}
                                            className="rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-brand-primary transition hover:border-brand-primary/50"
                                        >
                                            Download
                                        </a>
                                    )}
                                    {(existingUrl || localPreview) && (
                                        <button
                                            type="button"
                                            onClick={removeExisting}
                                            className="rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-rose-700 transition hover:border-rose-500/50"
                                        >
                                            Hapus / Ganti
                                        </button>
                                    )}
                                </div>
                                {errors.screenshot && <p className="text-[11px] font-semibold text-rose-700">{errors.screenshot}</p>}
                                {data.remove_screenshot && (
                                    <p className="text-[11px] font-semibold text-amber-700">
                                        Screenshot lama akan dihapus saat perubahan disimpan.
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-2 border-t border-brand-border pt-4">
                        <button type="button" onClick={onClose} className="px-4 py-2 text-xs font-bold text-brand-textSecondary transition hover:text-slate-900">
                            Batal
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-brand-primary px-5 py-2.5 text-xs font-bold text-white transition hover:bg-brand-primaryHover disabled:opacity-60"
                        >
                            {processing ? "Menyimpan..." : isEdit ? "Simpan Perubahan" : "Simpan Laporan"}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

function Field({ label, error, htmlFor, children }) {
    return (
        <div>
            <label className={labelClass} htmlFor={htmlFor}>{label}</label>
            {children}
            {error && <p className="mt-1 text-[11px] font-semibold text-rose-700">{error}</p>}
        </div>
    );
}
