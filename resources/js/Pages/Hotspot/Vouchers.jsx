import CimsLayout from '@/Layouts/CimsLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

/** Padanan status voucher → label & warna badge. */
const STATUS_META = {
    pending: { label: 'Pending', className: 'bg-amber-50 text-amber-700 border-amber-200' },
    synced: { label: 'Aktif di Router', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    failed: { label: 'Gagal Push', className: 'bg-rose-50 text-rose-700 border-rose-200' },
    disabled: { label: 'Diblokir', className: 'bg-slate-100 text-slate-600 border-slate-300' },
};

const EMPTY_FORM = {
    nim: '',
    student_name: '',
    program: '',
    faculty: '',
    password: '',
    profile: '',
    server: '',
    limit_uptime: '',
    valid_until: '',
    batch_label: '',
    comment: '',
};

const INPUT_CLASS =
    'w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600';

/**
 * Voucher WiFi Mahasiswa — daftar NIM + password yang dipush ke /ip/hotspot/user
 * pada router MikroTik. Data tersimpan di CIMS lebih dulu (status pending), lalu
 * dikirim ke router lewat tombol Push.
 */
export default function Vouchers({
    vouchers = { data: [], links: [] },
    filters = {},
    routerHost,
    routers = [],
    batches = [],
    stats = {},
    connection,
    hotspotProfiles,
    hotspotServers,
    hotspot = {},
}) {
    const { confirmAction } = useConfirmation();
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState([]);
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [showImport, setShowImport] = useState(false);
    const [showDetail, setShowDetail] = useState(false);
    const [sessions, setSessions] = useState(null);
    const [loadingSessions, setLoadingSessions] = useState(false);

    // Identitas hotspot (SSID, portal, profile) selalu datang dari HOTSPOT_* di
    // .env lewat props — jangan tulis nilai kampus sebagai literal di file ini.
    // Voucher baru ikut profile kampus supaya limit bandwidth-nya benar, bukan
    // profile "default" milik router.
    const profileDefault = hotspot.default_profile ?? '';

    // Contoh isian profile pun mengikuti .env, bukan nama profile yang ditulis tangan.
    const profilePlaceholder = profileDefault ? `mis. ${profileDefault}` : 'mis. profile hotspot di router';

    const form = useForm({ ...EMPTY_FORM, profile: profileDefault });
    const importForm = useForm({ file: null, profile: profileDefault, server: '', batch_label: '', valid_until: '' });

    const rows = vouchers.data ?? [];
    const pendingTotal = (stats.pending ?? 0) + (stats.failed ?? 0);

    /** Navigasi dengan mempertahankan filter yang sedang aktif. */
    const applyFilters = (patch) => {
        router.get(
            route('hotspot.vouchers.index'),
            { host: routerHost, ...filters, search, ...patch },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const queryParams = (extra = {}) => ({
        host: routerHost,
        search: filters.search || undefined,
        status: filters.status || undefined,
        batch: filters.batch || undefined,
        ...extra,
    });

    const toggleRow = (id) =>
        setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));

    const toggleAll = () =>
        setSelected((current) => (current.length === rows.length ? [] : rows.map((row) => row.id)));

    const openCreate = () => {
        setEditing(null);
        form.setData({ ...EMPTY_FORM, profile: profileDefault, batch_label: filters.batch ?? '' });
        form.clearErrors();
        setShowDetail(false);
        setShowForm(true);
    };

    const openEdit = (voucher) => {
        setEditing(voucher);
        setShowDetail(true);
        form.setData({
            nim: voucher.nim ?? '',
            student_name: voucher.student_name ?? '',
            program: voucher.program ?? '',
            faculty: voucher.faculty ?? '',
            password: voucher.password ?? '',
            profile: voucher.profile ?? '',
            server: voucher.server ?? '',
            limit_uptime: voucher.limit_uptime ?? '',
            valid_until: voucher.valid_until ? String(voucher.valid_until).slice(0, 10) : '',
            batch_label: voucher.batch_label ?? '',
            comment: voucher.comment ?? '',
        });
        form.clearErrors();
        setShowForm(true);
    };

    const submitForm = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setShowForm(false);
                form.reset();
            },
        };

        if (editing) {
            form.transform((data) => ({ ...data, router_host: routerHost }));
            form.post(route('hotspot.vouchers.update', editing.id), options);
        } else {
            form.transform((data) => ({ ...data, router_host: routerHost }));
            form.post(route('hotspot.vouchers.store'), options);
        }
    };

    const submitImport = (e) => {
        e.preventDefault();
        if (!importForm.data.file) return;

        importForm.transform((data) => ({ ...data, router_host: routerHost }));
        importForm.post(route('hotspot.vouchers.import'), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setShowImport(false);
                importForm.reset();
            },
        });
    };

    const pushBatch = () => {
        const count = selected.length > 0 ? selected.length : pendingTotal;

        confirmAction({
            title: 'Push Voucher ke Router',
            message:
                selected.length > 0
                    ? `Kirim ${selected.length} voucher terpilih ke router ${routerHost}?`
                    : `Kirim semua voucher pending/gagal (${pendingTotal}) ke router ${routerHost}? Maksimal 300 per klik.`,
            confirmLabel: 'Push Sekarang',
            cancelLabel: 'Batal',
            onConfirm: () =>
                router.post(
                    route('hotspot.vouchers.push'),
                    { router_host: routerHost, ids: selected },
                    { preserveScroll: true, onSuccess: () => setSelected([]) },
                ),
        });

        return count;
    };

    const removeVoucher = (voucher) =>
        confirmAction({
            title: 'Hapus Voucher',
            message: `Hapus voucher NIM ${voucher.nim}? Entri user di router juga akan dihapus.`,
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => router.delete(route('hotspot.vouchers.destroy', voucher.id), { preserveScroll: true }),
        });

    const loadSessions = async () => {
        setLoadingSessions(true);
        try {
            const response = await fetch(route('hotspot.vouchers.active', { host: routerHost }), {
                headers: { Accept: 'application/json' },
            });
            setSessions(await response.json());
        } catch (error) {
            setSessions({ total: 0, sessions: [], error: 'Gagal mengambil data sesi aktif dari router.' });
        } finally {
            setLoadingSessions(false);
        }
    };

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">Voucher WiFi Mahasiswa</h2>
                        <p className="text-sm text-slate-500">
                            Generate akun hotspot per NIM, import dari Excel, lalu push ke MikroTik
                            <span className="font-mono text-slate-700"> {routerHost || '(router belum dipilih)'}</span>.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={routerHost ?? ''}
                            onChange={(e) => router.get(route('hotspot.vouchers.index'), { host: e.target.value })}
                            className="rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700"
                        >
                            {routers.length === 0 && <option value={routerHost ?? ''}>{routerHost ?? 'Tanpa router'}</option>}
                            {routers.map((r) => (
                                <option key={r.ip} value={r.ip}>
                                    {r.name} ({r.ip})
                                </option>
                            ))}
                        </select>

                        <button
                            onClick={() => setShowImport(true)}
                            className="inline-flex items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-600 hover:text-white"
                        >
                            Import Excel
                        </button>
                        <button
                            onClick={openCreate}
                            className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                        >
                            Tambah Manual
                        </button>
                        <button
                            onClick={pushBatch}
                            disabled={pendingTotal === 0 && selected.length === 0}
                            className="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                        >
                            Push ke Router {selected.length > 0 ? `(${selected.length})` : pendingTotal > 0 ? `(${pendingTotal})` : ''}
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Voucher WiFi Mahasiswa" />

            {/* Status koneksi router (deferred prop — undefined selama masih dimuat) */}
            <div className="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm">
                {connection === undefined ? (
                    <span className="text-slate-500">Menghubungi router {routerHost}…</span>
                ) : connection?.success ? (
                    <span className="text-slate-600">
                        Router <strong className="text-slate-900">{connection.identity}</strong> ({connection.board} · RouterOS{' '}
                        {connection.version}) terhubung.
                    </span>
                ) : (
                    <span className="text-rose-700">
                        Router {routerHost} tidak bisa dihubungi: {connection?.error ?? 'tidak diketahui'}. Voucher tetap bisa
                        disimpan, push akan gagal sampai koneksi API RouterOS pulih.
                    </span>
                )}

                {/* Identitas hotspot kampus — dibaca dari HOTSPOT_* di .env, satu-satunya
                    tempat nilai ini disimpan. Ditampilkan agar operator bisa memastikan
                    kartu voucher tercetak dengan SSID & portal yang benar. */}
                <dl className="mt-3 flex flex-wrap gap-x-6 gap-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500">
                    {[
                        ['SSID', hotspot.ssid, 'HOTSPOT_SSID'],
                        ['Portal login', hotspot.login_url, 'HOTSPOT_LOGIN_URL'],
                        ['Router hotspot', hotspot.router_host, 'HOTSPOT_ROUTER_HOST'],
                        ['Profile default', hotspot.default_profile, 'HOTSPOT_DEFAULT_PROFILE'],
                    ].map(([label, value, envKey]) => (
                        <div key={envKey} className="flex items-baseline gap-1.5">
                            <dt className="font-semibold">{label}:</dt>
                            <dd className={value ? 'font-mono text-slate-700' : 'italic text-slate-400'}>
                                {value || `belum diisi (${envKey})`}
                            </dd>
                        </div>
                    ))}
                </dl>
            </div>

            {/* Voucher hanya berguna bila router tujuan benar-benar menjalankan hotspot.
                Tanpa peringatan ini, push "berhasil" tapi mahasiswa tetap ditolak
                dengan pesan "username doesn't exist" di router yang sebenarnya. */}
            {connection?.success && Array.isArray(hotspotServers) && hotspotServers.length === 0 && (
                <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                    <strong>Router ini tidak menjalankan hotspot.</strong> Daftar <code>/ip/hotspot</code> di{' '}
                    {connection.identity} ({routerHost}) kosong, jadi voucher yang dipush ke sini tidak akan bisa dipakai login —
                    mahasiswa ditolak dengan pesan <em>username doesn&apos;t exist</em>. Pilih router yang benar-benar melayani
                    SSID hotspot, atau ubah <code>HOTSPOT_ROUTER_HOST</code> di <code>.env</code>.
                </div>
            )}

            {/* Ringkasan status */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                {[
                    ['Total Voucher', stats.total ?? 0, 'text-slate-900'],
                    ['Pending', stats.pending ?? 0, 'text-amber-600'],
                    ['Aktif di Router', stats.synced ?? 0, 'text-emerald-600'],
                    ['Gagal Push', stats.failed ?? 0, 'text-rose-600'],
                    ['Diblokir', stats.disabled ?? 0, 'text-slate-500'],
                ].map(([label, value, tone]) => (
                    <div key={label} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-xs font-semibold text-slate-500">{label}</p>
                        <p className={`mt-1 text-2xl font-bold ${tone}`}>{value}</p>
                    </div>
                ))}
            </div>

            {/* Filter & aksi data */}
            <div className="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && applyFilters({ page: 1 })}
                    placeholder="Cari NIM, nama, prodi…"
                    className="min-w-[220px] flex-1 rounded-xl border-slate-200 bg-slate-50 text-sm focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                />
                <select
                    value={filters.status ?? ''}
                    onChange={(e) => applyFilters({ status: e.target.value || undefined, page: 1 })}
                    className="rounded-xl border-slate-200 bg-slate-50 text-sm text-slate-700"
                >
                    <option value="">Semua status</option>
                    {Object.entries(STATUS_META).map(([value, meta]) => (
                        <option key={value} value={value}>
                            {meta.label}
                        </option>
                    ))}
                </select>
                <select
                    value={filters.batch ?? ''}
                    onChange={(e) => applyFilters({ batch: e.target.value || undefined, page: 1 })}
                    className="rounded-xl border-slate-200 bg-slate-50 text-sm text-slate-700"
                >
                    <option value="">Semua batch</option>
                    {batches.map((batch) => (
                        <option key={batch} value={batch}>
                            {batch}
                        </option>
                    ))}
                </select>

                <div className="ml-auto flex flex-wrap gap-2">
                    <a
                        href={route('hotspot.vouchers.print', queryParams({ ids: selected.join(',') || undefined }))}
                        target="_blank"
                        rel="noreferrer"
                        className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Cetak Kartu PDF {selected.length > 0 && `(${selected.length})`}
                    </a>
                    <a
                        href={route('hotspot.vouchers.export', queryParams({ ids: selected.join(',') || undefined }))}
                        className="rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Export Excel/CSV
                    </a>
                    <button
                        onClick={loadSessions}
                        className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white"
                    >
                        {loadingSessions ? 'Memuat…' : 'Lihat Yang Online'}
                    </button>
                </div>
            </div>

            {/* Panel monitoring sesi hotspot yang sedang aktif */}
            {sessions && (
                <div className="mb-4 rounded-2xl border border-blue-200 bg-blue-50/50 px-5 py-4">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-bold text-slate-900">
                            Sedang Online di Hotspot — {sessions.total ?? 0} sesi
                            {sessions.fetched_at && <span className="ml-2 text-xs font-normal text-slate-500">per {sessions.fetched_at}</span>}
                        </h3>
                        <div className="flex gap-2">
                            <button onClick={loadSessions} className="text-xs font-semibold text-blue-700 hover:underline">
                                Refresh
                            </button>
                            <button onClick={() => setSessions(null)} className="text-xs font-semibold text-slate-500 hover:underline">
                                Tutup
                            </button>
                        </div>
                    </div>

                    {sessions.error && <p className="mt-2 text-xs text-rose-700">{sessions.error}</p>}

                    <div className="mt-3 overflow-x-auto">
                        <table className="min-w-full text-left text-xs">
                            <thead className="text-slate-500">
                                <tr>
                                    <th className="py-2 pr-4 font-bold">NIM / User</th>
                                    <th className="py-2 pr-4 font-bold">Nama</th>
                                    <th className="py-2 pr-4 font-bold">IP</th>
                                    <th className="py-2 pr-4 font-bold">MAC</th>
                                    <th className="py-2 pr-4 font-bold">Uptime</th>
                                    <th className="py-2 pr-4 font-bold">Download / Upload</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-blue-100">
                                {(sessions.sessions ?? []).map((s, index) => (
                                    <tr key={`${s.user}-${index}`}>
                                        <td className="py-2 pr-4 font-mono font-semibold text-slate-800">{s.user ?? '-'}</td>
                                        <td className="py-2 pr-4 text-slate-600">
                                            {s.student_name ?? (s.registered ? '-' : 'bukan voucher CIMS')}
                                        </td>
                                        <td className="py-2 pr-4 font-mono text-slate-600">{s.address ?? '-'}</td>
                                        <td className="py-2 pr-4 font-mono text-slate-500">{s.mac ?? '-'}</td>
                                        <td className="py-2 pr-4 text-slate-600">{s.uptime ?? '-'}</td>
                                        <td className="py-2 pr-4 text-slate-600">
                                            {Math.round((s.bytes_out ?? 0) / 1048576)} MB / {Math.round((s.bytes_in ?? 0) / 1048576)} MB
                                        </td>
                                    </tr>
                                ))}
                                {(sessions.sessions ?? []).length === 0 && (
                                    <tr>
                                        <td colSpan="6" className="py-4 text-center text-slate-500">
                                            Belum ada mahasiswa yang login ke hotspot saat ini.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {/* Tabel voucher */}
            <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
                <div className="overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200 text-left">
                        <thead className="bg-slate-50">
                            <tr>
                                <th className="py-4 pl-5 pr-3">
                                    <input
                                        type="checkbox"
                                        checked={rows.length > 0 && selected.length === rows.length}
                                        onChange={toggleAll}
                                        className="rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                        aria-label="Pilih semua voucher di halaman ini"
                                    />
                                </th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">NIM (Username)</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Password</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Nama / Prodi</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Profile</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Status</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Batch</th>
                                <th className="py-4 pl-3 pr-5 text-right text-xs font-bold text-slate-600">Aksi</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((voucher) => {
                                const meta = STATUS_META[voucher.status] ?? STATUS_META.pending;

                                return (
                                    <tr key={voucher.id} className="transition hover:bg-slate-50/80">
                                        <td className="py-4 pl-5 pr-3">
                                            <input
                                                type="checkbox"
                                                checked={selected.includes(voucher.id)}
                                                onChange={() => toggleRow(voucher.id)}
                                                className="rounded border-slate-300 text-blue-600 focus:ring-blue-600"
                                                aria-label={`Pilih voucher ${voucher.nim}`}
                                            />
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-4 font-mono text-sm font-bold text-slate-900">
                                            {voucher.nim}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-4 font-mono text-sm text-slate-700">
                                            {voucher.password}
                                        </td>
                                        <td className="px-3 py-4 text-sm">
                                            <p className="font-semibold text-slate-800">{voucher.student_name || '-'}</p>
                                            <p className="text-xs text-slate-500">{voucher.program || voucher.faculty || '-'}</p>
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                            {voucher.profile || <span className="text-slate-400">default</span>}
                                        </td>
                                        <td className="px-3 py-4 text-sm">
                                            <span className={`inline-flex rounded-lg border px-2 py-1 text-xs font-bold ${meta.className}`}>
                                                {meta.label}
                                            </span>
                                            {voucher.last_error && (
                                                <p className="mt-1 max-w-[220px] text-xs text-rose-600" title={voucher.last_error}>
                                                    {voucher.last_error.slice(0, 60)}
                                                </p>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-4 text-xs text-slate-500">
                                            {voucher.batch_label || '-'}
                                        </td>
                                        <td className="whitespace-nowrap py-4 pl-3 pr-5 text-right text-sm">
                                            <div className="flex flex-wrap justify-end gap-1.5">
                                                <button
                                                    onClick={() =>
                                                        router.post(route('hotspot.vouchers.push-one', voucher.id), {}, { preserveScroll: true })
                                                    }
                                                    className="rounded-lg border border-slate-900 bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700"
                                                >
                                                    Push
                                                </button>
                                                {voucher.status === 'synced' || voucher.status === 'disabled' ? (
                                                    <button
                                                        onClick={() =>
                                                            router.post(route('hotspot.vouchers.toggle', voucher.id), {}, { preserveScroll: true })
                                                        }
                                                        className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs font-semibold text-amber-700 transition hover:bg-amber-500 hover:text-white"
                                                    >
                                                        {voucher.status === 'disabled' ? 'Aktifkan' : 'Blokir'}
                                                    </button>
                                                ) : null}
                                                <button
                                                    onClick={() =>
                                                        router.post(route('hotspot.vouchers.kick', voucher.id), {}, { preserveScroll: true })
                                                    }
                                                    className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-100"
                                                    title="Putuskan sesi hotspot yang sedang aktif"
                                                >
                                                    Kick
                                                </button>
                                                <button
                                                    onClick={() => openEdit(voucher)}
                                                    className="rounded-lg border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    onClick={() => removeVoucher(voucher)}
                                                    className="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-600 hover:text-white"
                                                >
                                                    Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan="8" className="py-10 text-center text-sm text-slate-500">
                                        Belum ada voucher untuk router ini. Mulai dengan <strong>Import Excel</strong> atau{' '}
                                        <strong>Tambah Manual</strong>.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Paginasi */}
                {(vouchers.links ?? []).length > 3 && (
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3">
                        <p className="text-xs text-slate-500">
                            Menampilkan {vouchers.from ?? 0}–{vouchers.to ?? 0} dari {vouchers.total ?? 0} voucher
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {vouchers.links.map((link, index) => (
                                <button
                                    key={index}
                                    disabled={!link.url}
                                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                                        link.active
                                            ? 'bg-blue-600 text-white'
                                            : link.url
                                              ? 'border border-slate-200 text-slate-600 hover:bg-slate-50'
                                              : 'cursor-not-allowed border border-slate-100 text-slate-300'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {/* Modal tambah / edit voucher */}
            {showForm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6">
                        <div className="mb-5 flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">
                                    {editing ? `Edit Voucher ${editing.nim}` : 'Tambah Voucher Manual'}
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Router tujuan: {routerHost} · cukup isi NIM, password otomatis sama dengan NIM
                                </p>
                            </div>
                            <button onClick={() => setShowForm(false)} className="text-slate-400 transition hover:text-slate-700">
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form onSubmit={submitForm} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">NIM (username hotspot)*</label>
                                <input
                                    type="text"
                                    required
                                    value={form.data.nim}
                                    onChange={(e) => form.setData('nim', e.target.value)}
                                    placeholder="2101001"
                                    className={`${INPUT_CLASS} font-mono`}
                                />
                                {form.errors.nim && <span className="mt-1 block text-xs text-rose-700">{form.errors.nim}</span>}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Password</label>
                                <input
                                    type="text"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    placeholder="Kosongkan = sama dengan NIM"
                                    className={`${INPUT_CLASS} font-mono`}
                                />
                                {form.errors.password && <span className="mt-1 block text-xs text-rose-700">{form.errors.password}</span>}
                            </div>

                            <div className="sm:col-span-2">
                                <button
                                    type="button"
                                    onClick={() => setShowDetail((v) => !v)}
                                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 transition hover:text-blue-900"
                                >
                                    <svg
                                        className={`h-4 w-4 transition ${showDetail ? 'rotate-180' : ''}`}
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                    {showDetail ? 'Sembunyikan detail tambahan' : 'Detail tambahan (opsional)'}
                                </button>
                                {!showDetail && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        User profile:{' '}
                                        <strong className="font-semibold text-slate-700">
                                            {form.data.profile || 'default router'}
                                        </strong>
                                        . Nama, prodi, batas uptime, dan masa berlaku bisa diisi di sini bila perlu.
                                    </p>
                                )}
                            </div>

                            {showDetail && (
                                <>
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Nama Mahasiswa</label>
                                <input
                                    type="text"
                                    value={form.data.student_name}
                                    onChange={(e) => form.setData('student_name', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Program Studi</label>
                                <input
                                    type="text"
                                    value={form.data.program}
                                    onChange={(e) => form.setData('program', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Fakultas / Unit</label>
                                <input
                                    type="text"
                                    value={form.data.faculty}
                                    onChange={(e) => form.setData('faculty', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">User Profile Hotspot</label>
                                {Array.isArray(hotspotProfiles) && hotspotProfiles.length > 0 ? (
                                    <select
                                        value={form.data.profile}
                                        onChange={(e) => form.setData('profile', e.target.value)}
                                        className={INPUT_CLASS}
                                    >
                                        <option value="">(default router)</option>
                                        {form.data.profile &&
                                            !hotspotProfiles.some((p) => p.name === form.data.profile) && (
                                                <option value={form.data.profile}>
                                                    {form.data.profile} · tidak ada di router
                                                </option>
                                            )}
                                        {hotspotProfiles.map((p) => (
                                            <option key={p.name} value={p.name}>
                                                {p.name} {p.rate_limit ? `· ${p.rate_limit}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <input
                                        type="text"
                                        value={form.data.profile}
                                        onChange={(e) => form.setData('profile', e.target.value)}
                                        placeholder={hotspotProfiles === undefined ? 'Memuat profile dari router…' : profilePlaceholder}
                                        className={INPUT_CLASS}
                                    />
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Hotspot Server</label>
                                {Array.isArray(hotspotServers) && hotspotServers.length > 0 ? (
                                    <select
                                        value={form.data.server}
                                        onChange={(e) => form.setData('server', e.target.value)}
                                        className={INPUT_CLASS}
                                    >
                                        <option value="">(semua server)</option>
                                        {hotspotServers.map((s) => (
                                            <option key={s.name} value={s.name}>
                                                {s.name} {s.interface ? `· ${s.interface}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <input
                                        type="text"
                                        value={form.data.server}
                                        onChange={(e) => form.setData('server', e.target.value)}
                                        className={INPUT_CLASS}
                                    />
                                )}
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Batas Uptime</label>
                                <input
                                    type="text"
                                    value={form.data.limit_uptime}
                                    onChange={(e) => form.setData('limit_uptime', e.target.value)}
                                    placeholder="mis. 4h atau 30d (kosongkan = tanpa batas)"
                                    className={INPUT_CLASS}
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Berlaku Sampai</label>
                                <input
                                    type="date"
                                    value={form.data.valid_until}
                                    onChange={(e) => form.setData('valid_until', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Batch / Angkatan</label>
                                <input
                                    type="text"
                                    value={form.data.batch_label}
                                    onChange={(e) => form.setData('batch_label', e.target.value)}
                                    placeholder="mis. Angkatan 2026"
                                    className={INPUT_CLASS}
                                />
                            </div>

                            <div className="sm:col-span-2">
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Keterangan</label>
                                <input
                                    type="text"
                                    value={form.data.comment}
                                    onChange={(e) => form.setData('comment', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                            </div>
                                </>
                            )}

                            <div className="flex justify-end gap-3 border-t border-slate-200 pt-4 sm:col-span-2">
                                <button
                                    type="button"
                                    onClick={() => setShowForm(false)}
                                    className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-blue-700 disabled:bg-slate-300"
                                >
                                    {editing ? 'Simpan Perubahan' : 'Simpan sebagai Pending'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            {/* Modal import Excel */}
            {showImport && (
                <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6">
                        <div className="mb-5 flex items-center justify-between border-b border-slate-200 pb-4">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">Import Daftar Mahasiswa</h3>
                                <p className="text-xs text-slate-500">Excel/CSV → voucher pending untuk {routerHost}</p>
                            </div>
                            <button onClick={() => setShowImport(false)} className="text-slate-400 transition hover:text-slate-700">
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div className="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
                            Kolom yang dikenali: <strong>NIM</strong> (wajib), Nama, Prodi, Fakultas, Password, Profile, Keterangan.
                            Nama kolom boleh berbeda urutan — cukup ada judulnya. Bila file tidak punya judul kolom, kolom A dianggap
                            NIM dan kolom B nama. Password kosong otomatis diisi sama dengan NIM.
                            <a
                                href={route('hotspot.vouchers.template')}
                                className="ml-1 font-semibold text-blue-700 hover:underline"
                            >
                                Unduh contoh format.
                            </a>
                        </div>

                        <form onSubmit={submitImport} className="space-y-4">
                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">File Excel / CSV*</label>
                                <input
                                    type="file"
                                    accept=".xlsx,.xls,.csv"
                                    onChange={(e) => importForm.setData('file', e.target.files[0])}
                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 text-sm text-slate-600 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-50 file:px-4 file:py-2.5 file:text-xs file:font-bold file:text-emerald-700 hover:file:bg-emerald-100"
                                />
                                {importForm.errors.file && (
                                    <span className="mt-1 block text-xs text-rose-700">{importForm.errors.file}</span>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">User Profile (default)</label>
                                    {hotspotProfiles?.length ? (
                                        <select
                                            value={importForm.data.profile}
                                            onChange={(e) => importForm.setData('profile', e.target.value)}
                                            className={INPUT_CLASS}
                                        >
                                            <option value="">— default router —</option>
                                            {importForm.data.profile &&
                                                !hotspotProfiles.some((p) => p.name === importForm.data.profile) && (
                                                    <option value={importForm.data.profile}>
                                                        {importForm.data.profile} · tidak ada di router
                                                    </option>
                                                )}
                                            {hotspotProfiles.map((p) => (
                                                <option key={p.name} value={p.name}>{p.name}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            type="text"
                                            value={importForm.data.profile}
                                            onChange={(e) => importForm.setData('profile', e.target.value)}
                                            className={INPUT_CLASS}
                                            placeholder={profilePlaceholder}
                                        />
                                    )}
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">Hotspot Server (default)</label>
                                    {hotspotServers?.length ? (
                                        <select
                                            value={importForm.data.server}
                                            onChange={(e) => importForm.setData('server', e.target.value)}
                                            className={INPUT_CLASS}
                                        >
                                            <option value="">all</option>
                                            {hotspotServers.map((s) => (
                                                <option key={s.name} value={s.name}>{s.name}</option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            type="text"
                                            value={importForm.data.server}
                                            onChange={(e) => importForm.setData('server', e.target.value)}
                                            className={INPUT_CLASS}
                                            placeholder="kosongkan = all"
                                        />
                                    )}
                                </div>
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">Label Batch</label>
                                    <input
                                        type="text"
                                        value={importForm.data.batch_label}
                                        onChange={(e) => importForm.setData('batch_label', e.target.value)}
                                        className={INPUT_CLASS}
                                        placeholder="mis. Angkatan 2026"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">Berlaku Sampai</label>
                                    <input
                                        type="date"
                                        value={importForm.data.valid_until}
                                        onChange={(e) => importForm.setData('valid_until', e.target.value)}
                                        className={INPUT_CLASS}
                                    />
                                </div>
                            </div>

                            <p className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                                Data disimpan sebagai <strong>pending</strong> dulu. Router MikroTik belum tersentuh sampai kamu klik
                                tombol <strong>Push ke Router</strong>.
                            </p>

                            <div className="flex justify-end gap-3 border-t border-slate-200 pt-4">
                                <button
                                    type="button"
                                    onClick={() => setShowImport(false)}
                                    className="rounded-xl border border-slate-200 px-5 py-2.5 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={importForm.processing || !importForm.data.file}
                                    className="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {importForm.processing ? 'Mengimpor…' : 'Import Sekarang'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CimsLayout>
    );
}
