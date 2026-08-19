import { router, useForm } from "@inertiajs/react";
import { inputClass, labelClass } from "../constants";

/**
 * Pengelolaan daftar Penguji (master data dropdown "Penguji").
 * Penguji yang sudah dipakai laporan tidak dapat dihapus (FK restrictOnDelete).
 */
export default function TesterManagerModal({ testers = [], onClose, onConfirm }) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: "" });

    const submit = (event) => {
        event.preventDefault();
        post(route("speedtest-reports.testers.store"), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const remove = async (tester) => {
        const confirmed = await onConfirm({
            title: "Hapus Penguji",
            message: `Hapus "${tester.name}" dari daftar penguji? Tindakan ini tidak dapat dibatalkan.`,
            confirmLabel: "Hapus",
            cancelLabel: "Batal",
            variant: "danger",
        });
        if (!confirmed) return;
        router.delete(route("speedtest-reports.testers.destroy", tester.id), { preserveScroll: true });
    };

    return (
        <div className="fixed inset-0 z-[55] flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-sm">
            <div className="my-8 w-full max-w-lg rounded-2xl border border-brand-border bg-brand-card p-6">
                <div className="flex items-start justify-between">
                    <div>
                        <h3 className="text-lg font-bold text-slate-900">Kelola Penguji</h3>
                        <p className="mt-0.5 text-xs text-brand-textSecondary">
                            Nama yang ditambahkan di sini langsung tersedia pada dropdown Penguji.
                        </p>
                    </div>
                    <button type="button" onClick={onClose} className="rounded-lg p-1.5 text-brand-textSecondary transition hover:text-slate-900" aria-label="Tutup pengelolaan penguji">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form onSubmit={submit} className="mt-5">
                    <label className={labelClass} htmlFor="tester-name">Nama Penguji Baru</label>
                    <div className="flex gap-2">
                        <input
                            id="tester-name"
                            type="text"
                            value={data.name}
                            onChange={(e) => setData("name", e.target.value)}
                            placeholder="Contoh: Rama"
                            className={inputClass}
                            required
                        />
                        <button
                            type="submit"
                            disabled={processing}
                            className="shrink-0 rounded-xl bg-brand-primary px-4 py-2 text-xs font-bold text-white transition hover:bg-brand-primaryHover disabled:opacity-60"
                        >
                            Tambah
                        </button>
                    </div>
                    {errors.name && <p className="mt-1 text-[11px] font-semibold text-rose-700">{errors.name}</p>}
                </form>

                <ul className="mt-5 divide-y divide-brand-border/60 rounded-xl border border-brand-border">
                    {testers.length === 0 && (
                        <li className="px-4 py-6 text-center text-xs text-brand-textSecondary">Belum ada penguji terdaftar.</li>
                    )}
                    {testers.map((tester) => (
                        <li key={tester.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-bold text-slate-900">{tester.name}</p>
                                <p className="text-[11px] text-brand-textSecondary">
                                    {tester.reports_count ?? 0} laporan
                                </p>
                            </div>
                            {(tester.reports_count ?? 0) > 0 ? (
                                <span className="shrink-0 text-[11px] font-semibold text-brand-textMuted" title="Tidak dapat dihapus karena sudah dipakai laporan">
                                    Terpakai
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => remove(tester)}
                                    className="shrink-0 rounded-lg border border-brand-border px-3 py-1.5 text-[11px] font-bold text-rose-700 transition hover:border-rose-500/50"
                                >
                                    Hapus
                                </button>
                            )}
                        </li>
                    ))}
                </ul>

                <div className="mt-5 flex justify-end border-t border-brand-border pt-4">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-brand-border px-4 py-2 text-xs font-bold text-brand-textSecondary transition hover:text-slate-900"
                    >
                        Selesai
                    </button>
                </div>
            </div>
        </div>
    );
}
