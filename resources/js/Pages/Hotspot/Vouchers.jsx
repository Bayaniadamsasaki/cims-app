import CimsLayout from '@/Layouts/CimsLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

/** Padanan status voucher → label & warna badge. */
const STATUS_META = {
    pending: { label: 'Pending', className: 'bg-amber-50 text-amber-700 border-amber-200' },
    synced: { label: 'Aktif di RADIUS', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    failed: { label: 'Gagal Terapkan', className: 'bg-rose-50 text-rose-700 border-rose-200' },
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

/** Detik → "2j 15m" / "15m" / "40s". Uptime RouterOS sudah berupa teks, radacct tidak. */
const formatDuration = (seconds) => {
    if (seconds === null || seconds === undefined) return '-';

    const total = Math.max(Math.round(seconds), 0);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (hours > 0) return `${hours}j ${minutes}m`;
    if (minutes > 0) return `${minutes}m`;

    return `${total}s`;
};

const formatMb = (bytes) => `${Math.round((bytes ?? 0) / 1048576)} MB`;

/**
 * Badge keadaan satu sesi RADIUS.
 *
 * Tiga keadaan, bukan dua, karena "tidak terlihat di router" dan "belum bisa
 * dipastikan" adalah hal yang sangat berbeda bagi operator: yang pertama boleh
 * ditindak, yang kedua tidak boleh — routernya sendiri sedang tidak menjawab.
 */
const sessionState = (session) => {
    if (session.stale) {
        return { label: 'Basi', className: 'bg-amber-100 text-amber-800 border-amber-300' };
    }

    if (session.on_router === false) {
        return { label: 'Tak ada di router', className: 'bg-rose-50 text-rose-700 border-rose-200' };
    }

    if (session.on_router === true) {
        return { label: 'Terkonfirmasi', className: 'bg-emerald-50 text-emerald-700 border-emerald-200' };
    }

    return { label: 'Aktif', className: 'bg-slate-100 text-slate-600 border-slate-300' };
};

/**
 * Voucher WiFi Mahasiswa — daftar NIM + password yang diterapkan ke database
 * FreeRADIUS. Satu voucher berlaku di semua router hotspot kampus: yang menjawab
 * Access-Request untuk semuanya cuma satu server RADIUS.
 *
 * Alurnya sengaja tetap dua tahap. Data tersimpan di CIMS lebih dulu (pending),
 * baru ditulis ke RADIUS lewat tombol "Terapkan ke RADIUS" — satu klik di situ
 * memengaruhi WiFi seluruh kampus, jadi tidak boleh terjadi sebagai efek samping
 * sebuah unggahan Excel.
 *
 * Pemilih router di kanan atas tidak lagi menentukan siapa yang boleh login. Ia
 * hanya menentukan router mana yang ditanyai soal sesi yang sedang berjalan dan
 * mana yang menerima perintah Kick — dua hal yang memang cuma diketahui router.
 */
export default function Vouchers({
    vouchers = { data: [], links: [] },
    filters = {},
    routerHost,
    routers = [],
    batches = [],
    stats = {},
    disabledReasons = {},
    connection,
    radiusGroups,
    groupsWithoutPolicy,
    routerConnection,
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
    // 'radius' = semua router (sumber yang menegakkan batas login), 'router' =
    // hanya router terpilih (sumber yang tahu keadaan sebenarnya).
    const [sessionSource, setSessionSource] = useState('radius');

    // Identitas hotspot (SSID, portal, paket) selalu datang dari HOTSPOT_* di
    // .env lewat props — jangan tulis nilai kampus sebagai literal di file ini.
    // Voucher baru ikut group RADIUS kampus supaya rate limit & batas sesinya
    // benar, bukan group "default" yang biasanya tanpa policy sama sekali.
    const profileDefault = hotspot.default_profile ?? '';

    // Contoh isiannya pun mengikuti .env, bukan nama group yang ditulis tangan.
    const profilePlaceholder = profileDefault ? `mis. ${profileDefault}` : 'mis. nama group di RADIUS';

    // Group RADIUS datang sebagai deferred prop: undefined berarti masih dimuat,
    // array kosong berarti RADIUS terbaca tapi belum punya satu group pun.
    const groups = Array.isArray(radiusGroups) ? radiusGroups : [];

    // Group yang ada tapi kosong. Dipisah dari `groups` karena artinya berbeda:
    // yang ini terdaftar dan dipakai, tapi tidak memberi batas apa pun.
    const emptyGroups = Array.isArray(groupsWithoutPolicy) ? groupsWithoutPolicy : [];

    // Nama profile yang ada di router dipakai sebagai petunjuk lunak saja. Yang
    // menentukan paket adalah policy group di radgroupreply, dan group boleh
    // memakai Mikrotik-Rate-Limit tanpa user-profile senama di router.
    const routerProfileNames = Array.isArray(hotspotProfiles) ? hotspotProfiles.map((p) => p.name) : [];

    /** Dropdown group RADIUS yang tetap menampilkan nilai lama bila sudah tak terdaftar. */
    const groupOptions = (value) => {
        const list = [...groups];

        if (value && !list.includes(value)) {
            list.unshift(value);
        }

        return list;
    };

    /** Catatan kecil di bawah pilihan paket: apakah group ini punya padanan di router. */
    const groupHint = (value) => {
        if (!value) return null;
        if (radiusGroups !== undefined && !groups.includes(value)) {
            return `Group "${value}" belum ada di RADIUS — mahasiswanya tetap bisa login, tapi tanpa batas apa pun sampai groupnya dibuat.`;
        }
        // Group yang terdaftar tapi kosong sama akibatnya dengan group yang tidak
        // ada, dan justru lebih menipu: namanya muncul di dropdown seolah beres.
        if (emptyGroups.includes(value)) {
            return `Group "${value}" ada di RADIUS tapi belum punya batas kecepatan sama sekali — isi dulu di halaman Paket Hotspot, kalau tidak mahasiswanya login tanpa batas.`;
        }
        if (routerProfileNames.length > 0 && !routerProfileNames.includes(value)) {
            return `Tidak ada user-profile bernama "${value}" di router. Wajar bila policy groupnya memakai Mikrotik-Rate-Limit di radgroupreply.`;
        }
        return null;
    };

    const form = useForm({ ...EMPTY_FORM, profile: profileDefault });
    const importForm = useForm({ file: null, profile: profileDefault, server: '', batch_label: '', valid_until: '' });
    const syncForm = useForm({ profile: profileDefault, batch_label: '' });

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

    /**
     * Tarik daftar mahasiswa dari SISKA. Passwordnya tanggal lahir; yang tanggal
     * lahirnya belum terisi di SISKA dapat password NIM, dan jumlahnya disebut
     * di pesan hasil. Baris yang sudah ada ikut diperbarui, bukan diduplikat.
     *
     * Tarikan ini juga MENUTUP akses NIM yang sudah tidak ada di SISKA, jadi
     * konfirmasinya menyebutkan itu terang-terangan — bukan sebagai catatan kecil.
     */
    const syncFromSiska = () => {
        confirmAction({
            title: 'Tarik Mahasiswa dari SISKA',
            message:
                'Ambil daftar mahasiswa dari SISKA dan buat vouchernya? Password memakai tanggal lahir; mahasiswa ' +
                'yang tanggal lahirnya belum ada di SISKA passwordnya NIM. Voucher yang sudah ada ikut diperbarui, ' +
                'dan yang baru berstatus pending sampai diterapkan ke RADIUS.\n\n' +
                'Perhatian: NIM yang sudah tidak ada lagi di SISKA langsung DITOLAK di RADIUS — mahasiswanya tidak ' +
                'bisa login sampai muncul kembali di SISKA. Voucher manual dan hasil import (dosen, staf, tamu) ' +
                'tidak pernah ikut ditutup.',
            confirmLabel: 'Tarik Sekarang',
            cancelLabel: 'Batal',
            onConfirm: () => {
                syncForm.transform((data) => ({ ...data, router_host: routerHost }));
                syncForm.post(route('hotspot.vouchers.sync-pmb'), { preserveScroll: true });
            },
        });
    };

    const pushBatch = () => {
        confirmAction({
            title: 'Terapkan Voucher ke RADIUS',
            message:
                (selected.length > 0
                    ? `Tulis ${selected.length} voucher terpilih ke database RADIUS?`
                    : `Tulis semua voucher pending dan gagal (${pendingTotal}) ke database RADIUS?`) +
                ' Setelah ini mereka bisa login di seluruh SSID hotspot kampus, bukan hanya di satu router.',
            confirmLabel: 'Terapkan Sekarang',
            cancelLabel: 'Batal',
            onConfirm: () =>
                router.post(
                    route('hotspot.vouchers.push'),
                    { router_host: routerHost, ids: selected },
                    { preserveScroll: true, onSuccess: () => setSelected([]) },
                ),
        });
    };

    const removeVoucher = (voucher) =>
        confirmAction({
            title: 'Hapus Voucher',
            message: `Hapus voucher NIM ${voucher.nim}? Kredensialnya juga dicabut dari RADIUS, jadi NIM ini langsung tidak bisa login lagi.`,
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => router.delete(route('hotspot.vouchers.destroy', voucher.id), { preserveScroll: true }),
        });

    /**
     * Ambil sesi yang sedang berjalan dari dua sumber sekaligus.
     *
     * Kalau RADIUS belum bisa dibaca, tampilannya jatuh ke tab router supaya
     * panelnya tetap berguna — bukan menampilkan tabel kosong yang seolah-olah
     * berarti tidak ada yang online.
     */
    const loadSessions = async () => {
        setLoadingSessions(true);
        try {
            const response = await fetch(route('hotspot.vouchers.active', { host: routerHost }), {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            setSessions(data);
            setSessionSource(data?.radius?.error || !data?.radius?.configured ? 'router' : 'radius');
        } catch (error) {
            setSessions({
                radius: { error: 'Gagal mengambil data sesi aktif.', sessions: [], shared: [], total: 0 },
                router: { total: 0, sessions: [] },
            });
            setSessionSource('router');
        } finally {
            setLoadingSessions(false);
        }
    };

    const radiusInfo = sessions?.radius ?? {};
    const routerInfo = sessions?.router ?? {};
    const radiusRows = radiusInfo.sessions ?? [];
    const routerRows = routerInfo.sessions ?? [];
    const sharedRows = radiusInfo.shared ?? [];

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">Voucher WiFi Mahasiswa</h2>
                        <p className="text-sm text-slate-500">
                            Satu NIM = satu akun hotspot untuk seluruh kampus. Simpan di CIMS dulu, lalu terapkan ke{' '}
                            <span className="font-semibold text-slate-700">server RADIUS</span> yang menjawab login di semua
                            router hotspot.
                        </p>
                    </div>

                    <div className="flex flex-col items-end gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                value={routerHost ?? ''}
                                onChange={(e) => router.get(route('hotspot.vouchers.index'), { host: e.target.value })}
                                className="rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700"
                                aria-label="Router untuk panel sesi aktif dan tombol Kick"
                            >
                                {/* Host yang benar-benar ditanyai belum tentu ada di Device
                                    Inventory: HOTSPOT_ROUTER_HOST bisa menunjuk alamat yang
                                    sudah tidak dipakai. Tanpa option-nya sendiri, <select>
                                    menampilkan pilihan pertama — dan dropdown ini berbohong
                                    soal router mana yang sesi aktifnya sedang dibaca. */}
                                {routerHost && ! routers.some((r) => r.ip === routerHost) && (
                                    <option value={routerHost}>{routerHost} — tidak ada di inventaris</option>
                                )}
                                {routers.length === 0 && ! routerHost && <option value="">Tanpa router</option>}
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
                            {hotspot.pmb_configured && (
                                <button
                                    onClick={syncFromSiska}
                                    disabled={syncForm.processing}
                                    className="inline-flex items-center rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-semibold text-indigo-700 transition hover:bg-indigo-600 hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {syncForm.processing ? 'Menarik dari SISKA...' : 'Tarik dari SISKA'}
                                </button>
                            )}
                            <button
                                onClick={openCreate}
                                className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                            >
                                Tambah Manual
                            </button>
                            <button
                                onClick={pushBatch}
                                disabled={(pendingTotal === 0 && selected.length === 0) || hotspot.radius_configured === false}
                                title={
                                    hotspot.radius_configured === false
                                        ? 'RADIUS_DB_* belum diisi di .env, jadi belum ada tujuan yang bisa ditulis.'
                                        : undefined
                                }
                                className="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                            >
                                Terapkan ke RADIUS{' '}
                                {selected.length > 0 ? `(${selected.length})` : pendingTotal > 0 ? `(${pendingTotal})` : ''}
                            </button>
                        </div>
                        <span className="text-[11px] leading-tight text-slate-400">
                            Pemilih router hanya mengatur panel sesi aktif &amp; tombol Kick — daftar voucher tidak lagi
                            disaring per router.
                        </span>
                    </div>
                </div>
            }
        >
            <Head title="Voucher WiFi Mahasiswa" />

            {/* Status server RADIUS — inilah yang benar-benar menentukan siapa yang
                boleh login. Deferred prop: undefined selama masih dimuat. Status
                router tidak lagi di sini; ia pindah ke panel sesi aktif, satu-satunya
                tempat yang memang membutuhkannya. */}
            <div className="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm">
                {hotspot.radius_configured === false ? (
                    <span className="text-amber-700">
                        Koneksi RADIUS belum diatur, jadi belum ada tujuan yang bisa ditulis. Isi <code>RADIUS_DB_HOST</code>,{' '}
                        <code>RADIUS_DB_DATABASE</code>, <code>RADIUS_DB_USERNAME</code>, dan <code>RADIUS_DB_PASSWORD</code> di{' '}
                        <code>.env</code>, lalu jalankan <code>php artisan radius:doctor</code>. Voucher tetap bisa disimpan.
                    </span>
                ) : connection === undefined ? (
                    <span className="text-slate-500">Menghubungi server RADIUS…</span>
                ) : connection?.success ? (
                    <span className="text-slate-600">
                        Server RADIUS terhubung — database{' '}
                        <strong className="font-mono text-slate-900">{connection.database || '(tanpa nama)'}</strong>
                        {connection.server ? ` · ${connection.server}` : ''}, berisi{' '}
                        <strong className="text-slate-900">{connection.users ?? 0}</strong> username.
                    </span>
                ) : (
                    <span className="text-rose-700">
                        Server RADIUS tidak bisa dihubungi: {connection?.error ?? 'tidak diketahui'}. Voucher tetap bisa
                        disimpan, tapi <strong>Terapkan ke RADIUS</strong> akan ditolak sampai koneksinya pulih.
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
                        ['Paket default', hotspot.default_profile, 'HOTSPOT_RADIUS_DEFAULT_GROUP'],
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

            {/* Paket default yang kosong memengaruhi setiap voucher sekaligus: semuanya
                mengarah ke group ini, dan group tanpa policy dijawab Access-Accept
                tanpa atribut — mahasiswanya masuk tanpa batas kecepatan, tanpa satu
                pun pesan gagal yang menandakannya. */}
            {profileDefault && emptyGroups.includes(profileDefault) && (
                <div className="mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                    <strong>
                        Paket default <code>{profileDefault}</code> belum punya batas kecepatan.
                    </strong>{' '}
                    Voucher yang diterapkan tetap bisa login, tapi tanpa batas apa pun — dan RADIUS tidak melaporkannya
                    sebagai kegagalan.{' '}
                    <a
                        href={route('hotspot.packages.index')}
                        className="font-semibold underline decoration-amber-400 underline-offset-2 hover:text-amber-950"
                    >
                        Isi di halaman Paket Hotspot
                    </a>
                    .
                </div>
            )}

            {/* Voucher yang benar di RADIUS tetap tidak bisa dipakai di router yang
                tidak menjalankan hotspot sama sekali. Tanpa peringatan ini, semua
                terlihat "berhasil" padahal mahasiswa tidak pernah melihat portal. */}
            {routerConnection?.success && Array.isArray(hotspotServers) && hotspotServers.length === 0 && (
                <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm text-rose-800">
                    <strong>Router ini tidak menjalankan hotspot.</strong> Daftar <code>/ip/hotspot</code> di{' '}
                    {routerConnection.identity} ({routerHost}) kosong, jadi tidak ada portal login di sini dan tidak ada
                    Access-Request yang dikirim ke RADIUS — panel sesi aktif pun akan selalu kosong. Pilih router yang
                    benar-benar melayani SSID hotspot, atau ubah <code>HOTSPOT_ROUTER_HOST</code> di <code>.env</code>.
                </div>
            )}

            {/* Ringkasan status. Labelnya mengikuti tujuan yang sebenarnya: 'synced'
                berarti barisnya sudah ada di RADIUS, bukan di router. */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
                {[
                    ['Total Voucher', stats.total ?? 0, 'text-slate-900', 'tercatat di CIMS'],
                    ['Pending', stats.pending ?? 0, 'text-amber-600', 'belum ditulis ke RADIUS'],
                    ['Aktif di RADIUS', stats.synced ?? 0, 'text-emerald-600', 'bisa login di seluruh kampus'],
                    ['Gagal Terapkan', stats.failed ?? 0, 'text-rose-600', 'penulisan terakhir ditolak'],
                    ['Nonaktif', stats.disabled ?? 0, 'text-slate-500', 'ditolak di RADIUS'],
                ].map(([label, value, tone, hint]) => (
                    <div key={label} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-xs font-semibold text-slate-500">{label}</p>
                        <p className={`mt-1 text-2xl font-bold ${tone}`}>{value}</p>
                        <p className="text-[11px] leading-tight text-slate-400">{hint}</p>
                    </div>
                ))}
            </div>

            {/* Sebab nonaktifnya dipisah karena penanganannya berbeda: "tidak ada di
                PMB" selesai dengan memperbaiki data di SISKA, sedangkan blokir
                operator adalah keputusan manusia yang tidak dibatalkan sinkronisasi. */}
            {Object.keys(disabledReasons).length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-xs text-slate-500">
                    <span className="font-semibold text-slate-600">Sebab nonaktif:</span>
                    {Object.entries(disabledReasons).map(([reason, total]) => (
                        <span key={reason} className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1">
                            {reason} · <strong className="text-slate-700">{total}</strong>
                        </span>
                    ))}
                    <button
                        onClick={() => applyFilters({ status: 'disabled', page: 1 })}
                        className="ml-auto font-semibold text-blue-700 hover:underline"
                    >
                        Lihat daftarnya
                    </button>
                </div>
            )}

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
                    {/* Status router menempel di tombolnya, bukan di banner halaman:
                        sesi aktif dan Kick adalah satu-satunya yang masih memerlukan
                        API RouterOS, dan hanya di sinilah kabarnya berguna. */}
                    <div className="flex flex-col items-end">
                        <button
                            onClick={loadSessions}
                            className="rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-600 hover:text-white"
                        >
                            {loadingSessions ? 'Memuat…' : 'Lihat Yang Online'}
                        </button>
                        <span
                            className={`mt-1 text-[11px] leading-tight ${
                                routerConnection && !routerConnection.success ? 'text-rose-600' : 'text-slate-400'
                            }`}
                        >
                            {routerConnection === undefined
                                ? 'Memeriksa router…'
                                : routerConnection?.success
                                  ? `Router ${routerConnection.identity ?? routerHost} terhubung`
                                  : 'Router tidak terhubung'}
                        </span>
                    </div>
                </div>
            </div>

            {/* Panel monitoring sesi hotspot yang sedang berjalan.

                Sumber utamanya sekarang radacct di RADIUS, bukan satu router: satu
                akun yang dipakai bersamaan di dua router hanya terlihat dari sana,
                dan angka itulah yang akan dipakai FreeRADIUS kalau batas sesi
                bersamaan dinyalakan. Tampilan router tetap ada karena selisih
                keduanya adalah satu-satunya cara melihat sesi basi sebelum ia
                menolak login mahasiswa. */}
            {sessions && (
                <div className="mb-4 rounded-2xl border border-blue-200 bg-blue-50/50 px-5 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 className="text-sm font-bold text-slate-900">
                                Sedang Online di Hotspot — {radiusInfo.total ?? 0} sesi di seluruh router
                                {sessions.fetched_at && (
                                    <span className="ml-2 text-xs font-normal text-slate-500">per {sessions.fetched_at}</span>
                                )}
                            </h3>
                            <p className="mt-1 max-w-2xl text-xs text-slate-500">
                                Hitungan utamanya dibaca dari <span className="font-mono text-slate-700">radacct</span> di server
                                RADIUS, jadi mencakup semua router hotspot — bukan hanya{' '}
                                <span className="font-mono text-slate-700">{routerHost}</span>.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <button onClick={loadSessions} className="text-xs font-semibold text-blue-700 hover:underline">
                                {loadingSessions ? 'Memuat…' : 'Refresh'}
                            </button>
                            <button onClick={() => setSessions(null)} className="text-xs font-semibold text-slate-500 hover:underline">
                                Tutup
                            </button>
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        {[
                            { key: 'radius', label: `RADIUS · semua router (${radiusInfo.shown ?? 0})` },
                            { key: 'router', label: `Router ini (${routerInfo.total ?? 0})` },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                onClick={() => setSessionSource(tab.key)}
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold transition ${
                                    sessionSource === tab.key
                                        ? 'border-blue-600 bg-blue-600 text-white'
                                        : 'border-blue-200 bg-white text-blue-700 hover:bg-blue-100'
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                        {(radiusInfo.stale ?? 0) > 0 && (
                            <span className="rounded-lg border border-amber-300 bg-amber-100 px-2 py-1 text-[11px] font-semibold text-amber-800">
                                {radiusInfo.stale} sesi basi
                            </span>
                        )}
                        {sharedRows.length > 0 && (
                            <span className="rounded-lg border border-rose-300 bg-rose-100 px-2 py-1 text-[11px] font-semibold text-rose-800">
                                {sharedRows.length} NIM dipakai bersamaan
                            </span>
                        )}
                    </div>

                    {radiusInfo.error && (
                        <p className="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700">
                            RADIUS: {radiusInfo.error}
                        </p>
                    )}

                    {/* Peringatan ini dipasang sebelum tabelnya, bukan sesudah: angka
                        "sesi basi" adalah alasan utama batas sesi bersamaan belum
                        boleh dinyalakan, dan operator harus membacanya lebih dulu. */}
                    {(radiusInfo.stale ?? 0) > 0 && (
                        <div className="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                            <span className="font-semibold">{radiusInfo.stale} sesi</span> tidak melaporkan diri lebih dari{' '}
                            {radiusInfo.stale_after_minutes ?? 15} menit. Baris seperti ini umumnya sisa perangkat yang mati atau
                            router yang restart — bukan mahasiswa yang benar-benar online. Selama barisnya masih terbuka, ia akan
                            menolak login NIM tersebut begitu batas satu sesi per mahasiswa dinyalakan. Pasang{' '}
                            <span className="font-mono">Acct-Interim-Interval</span> di paket dan penutup sesi yatim di server
                            RADIUS lebih dulu.
                        </div>
                    )}

                    {sharedRows.length > 0 && (
                        <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900">
                            <p className="font-semibold">NIM dengan lebih dari satu sesi terbuka:</p>
                            <div className="mt-1 flex flex-wrap gap-1.5">
                                {sharedRows.map((row) => (
                                    <span
                                        key={row.username}
                                        className="rounded-md border border-rose-300 bg-white px-2 py-0.5 font-mono text-[11px] text-rose-800"
                                    >
                                        {row.username} · {row.sessions}×{row.student_name ? ` · ${row.student_name}` : ''}
                                    </span>
                                ))}
                            </div>
                            <p className="mt-1.5 text-rose-800">
                                Periksa kolom keadaan dulu — sesi basi juga terhitung ganda padahal orangnya cuma satu.
                            </p>
                        </div>
                    )}

                    {sessionSource === 'radius' ? (
                        <div className="mt-3 overflow-x-auto">
                            <table className="min-w-full text-left text-xs">
                                <thead className="text-slate-500">
                                    <tr>
                                        <th className="py-2 pr-4 font-bold">NIM / Nama</th>
                                        <th className="py-2 pr-4 font-bold">Router / IP</th>
                                        <th className="py-2 pr-4 font-bold">MAC</th>
                                        <th className="py-2 pr-4 font-bold">Uptime</th>
                                        <th className="py-2 pr-4 font-bold">Terakhir lapor</th>
                                        <th className="py-2 pr-4 font-bold">Download / Upload</th>
                                        <th className="py-2 pr-4 font-bold">Keadaan</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-blue-100">
                                    {radiusRows.map((s, index) => {
                                        const state = sessionState(s);

                                        return (
                                            <tr key={s.session_id ?? `${s.username}-${index}`}>
                                                <td className="py-2 pr-4">
                                                    <span className="block font-mono font-semibold text-slate-800">{s.username || '-'}</span>
                                                    <span className="block text-slate-500">
                                                        {s.student_name ?? (s.registered ? '-' : 'bukan voucher CIMS')}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-4 font-mono text-slate-600">
                                                    <span className="block">{s.nas_ip ?? '-'}</span>
                                                    <span className="block text-slate-400">{s.ip ?? '-'}</span>
                                                </td>
                                                <td className="py-2 pr-4 font-mono text-slate-500">{s.mac ?? '-'}</td>
                                                <td className="py-2 pr-4 text-slate-600">{formatDuration(s.uptime_seconds)}</td>
                                                <td className="py-2 pr-4 text-slate-600">
                                                    {s.reported ? `${formatDuration(s.silent_for)} lalu` : 'belum pernah'}
                                                </td>
                                                <td className="py-2 pr-4 text-slate-600">
                                                    {formatMb(s.bytes_out)} / {formatMb(s.bytes_in)}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    <span className={`rounded-md border px-2 py-0.5 text-[11px] font-semibold ${state.className}`}>
                                                        {state.label}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {radiusRows.length === 0 && (
                                        <tr>
                                            <td colSpan="7" className="py-4 text-center text-slate-500">
                                                {radiusInfo.configured === false
                                                    ? 'Koneksi RADIUS belum diatur, jadi sesi seluruh router belum bisa dibaca.'
                                                    : 'Tidak ada sesi terbuka di radacct saat ini.'}
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                            {radiusInfo.truncated && (
                                <p className="mt-2 text-[11px] text-slate-500">
                                    Menampilkan {radiusInfo.shown} dari {radiusInfo.total} sesi, diurutkan dari yang paling lama
                                    tidak melapor — yang berpotensi bermasalah selalu ikut tampil.
                                </p>
                            )}
                        </div>
                    ) : (
                        <>
                            {/* Status router hidup di sini, bukan di banner halaman:
                                hanya tampilan ini dan tombol Kick yang memerlukan API
                                RouterOS, jadi hanya di sini kegagalannya berarti. */}
                            <p className="mt-3 text-xs text-slate-500">
                                Dibaca langsung dari <span className="font-mono text-slate-700">{routerHost}</span> lewat API
                                RouterOS
                                {routerConnection === undefined
                                    ? ' — koneksi router masih diperiksa…'
                                    : routerConnection?.success
                                      ? ` — ${routerConnection.identity ?? routerHost} (${routerConnection.board} · RouterOS ${routerConnection.version}).`
                                      : ` — router tidak bisa dihubungi: ${routerConnection?.error ?? 'tidak diketahui'}. Tombol Kick belum bisa dipakai sampai koneksinya pulih; izin login mahasiswa tidak terpengaruh.`}
                            </p>

                            <div className="mt-2 overflow-x-auto">
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
                                        {routerRows.map((s, index) => (
                                            <tr key={`${s.user}-${index}`}>
                                                <td className="py-2 pr-4 font-mono font-semibold text-slate-800">{s.user ?? '-'}</td>
                                                <td className="py-2 pr-4 text-slate-600">
                                                    {s.student_name ?? (s.registered ? '-' : 'bukan voucher CIMS')}
                                                </td>
                                                <td className="py-2 pr-4 font-mono text-slate-600">{s.address ?? '-'}</td>
                                                <td className="py-2 pr-4 font-mono text-slate-500">{s.mac ?? '-'}</td>
                                                <td className="py-2 pr-4 text-slate-600">{s.uptime ?? '-'}</td>
                                                <td className="py-2 pr-4 text-slate-600">
                                                    {formatMb(s.bytes_out)} / {formatMb(s.bytes_in)}
                                                </td>
                                            </tr>
                                        ))}
                                        {routerRows.length === 0 && (
                                            <tr>
                                                <td colSpan="6" className="py-4 text-center text-slate-500">
                                                    {sessions.router_ok === false
                                                        ? 'Router tidak menjawab, jadi isi sesinya belum bisa dipastikan.'
                                                        : 'Belum ada mahasiswa yang login lewat router ini saat ini.'}
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}
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
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Paket (group RADIUS)</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Status</th>
                                <th className="px-3 py-4 text-xs font-bold text-slate-600">Asal / Batch</th>
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
                                            {voucher.profile || <span className="text-slate-400">group default</span>}
                                        </td>
                                        <td className="px-3 py-4 text-sm">
                                            <span className={`inline-flex rounded-lg border px-2 py-1 text-xs font-bold ${meta.className}`}>
                                                {meta.label}
                                            </span>
                                            {/* Alasan nonaktif ikut ditampilkan: tanpa itu operator tidak
                                                bisa membedakan blokirnya sendiri dari hasil sinkronisasi. */}
                                            {voucher.status === 'disabled' && (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {voucher.disabled_reason || 'diblokir operator'}
                                                </p>
                                            )}
                                            {voucher.last_error && (
                                                <p className="mt-1 max-w-[220px] text-xs text-rose-600" title={voucher.last_error}>
                                                    {voucher.last_error.slice(0, 60)}
                                                </p>
                                            )}
                                        </td>
                                        <td className="whitespace-nowrap px-3 py-4 text-xs text-slate-500">
                                            <p>{voucher.batch_label || '-'}</p>
                                            {/* Asal baris menentukan apakah sinkronisasi PMB boleh
                                                menutupnya sendiri — hanya 'pmb' yang boleh. */}
                                            <p className="mt-0.5 text-[11px] text-slate-400">
                                                {voucher.source === 'pmb'
                                                    ? 'dari SISKA'
                                                    : voucher.source === 'import'
                                                      ? 'import Excel'
                                                      : 'manual'}
                                            </p>
                                        </td>
                                        <td className="whitespace-nowrap py-4 pl-3 pr-5 text-right text-sm">
                                            <div className="flex flex-wrap justify-end gap-1.5">
                                                <button
                                                    onClick={() =>
                                                        router.post(route('hotspot.vouchers.push-one', voucher.id), {}, { preserveScroll: true })
                                                    }
                                                    className="rounded-lg border border-slate-900 bg-slate-900 px-2.5 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700"
                                                    title="Tulis ulang kredensial NIM ini ke RADIUS"
                                                >
                                                    Terapkan
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
                                                    title={`Putuskan sesi hotspot yang sedang aktif di ${routerHost}`}
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
                                        Belum ada voucher. Mulai dengan <strong>Tarik dari SISKA</strong>,{' '}
                                        <strong>Import Excel</strong>, atau <strong>Tambah Manual</strong>.
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
                                    Berlaku di semua router hotspot kampus · cukup isi NIM, password otomatis sama dengan NIM
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
                                        Paket:{' '}
                                        <strong className="font-semibold text-slate-700">
                                            {form.data.profile || 'group default RADIUS'}
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
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Paket (group RADIUS)</label>
                                {groups.length > 0 ? (
                                    <select
                                        value={form.data.profile}
                                        onChange={(e) => form.setData('profile', e.target.value)}
                                        className={INPUT_CLASS}
                                    >
                                        <option value="">(group default: {profileDefault || 'belum diatur'})</option>
                                        {groupOptions(form.data.profile).map((name) => (
                                            <option key={name} value={name}>
                                                {name}
                                                {groups.includes(name) ? '' : ' · belum ada di RADIUS'}
                                            </option>
                                        ))}
                                    </select>
                                ) : (
                                    <input
                                        type="text"
                                        value={form.data.profile}
                                        onChange={(e) => form.setData('profile', e.target.value)}
                                        placeholder={radiusGroups === undefined ? 'Memuat group dari RADIUS…' : profilePlaceholder}
                                        className={INPUT_CLASS}
                                    />
                                )}
                                {groupHint(form.data.profile) && (
                                    <span className="mt-1 block text-[11px] leading-tight text-amber-700">
                                        {groupHint(form.data.profile)}
                                    </span>
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Hotspot Server (catatan)</label>
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
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Nama hotspot server RouterOS. Tidak punya padanan di RADIUS, jadi ini hanya catatan —
                                    izin login tidak dibatasi per server.
                                </span>
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
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Dikirim ke RADIUS sebagai Session-Timeout, hanya untuk NIM ini — batas paket tetap dari
                                    policy groupnya.
                                </span>
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Berlaku Sampai</label>
                                <input
                                    type="date"
                                    value={form.data.valid_until}
                                    onChange={(e) => form.setData('valid_until', e.target.value)}
                                    className={INPUT_CLASS}
                                />
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Catatan administratif saja — belum dipaksakan di RADIUS, jadi tanggal ini tidak
                                    menghentikan login dengan sendirinya.
                                </span>
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
                                <p className="text-xs text-slate-500">Excel/CSV → voucher pending, berlaku di seluruh kampus</p>
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
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">Paket (group RADIUS)</label>
                                    {groups.length > 0 ? (
                                        <select
                                            value={importForm.data.profile}
                                            onChange={(e) => importForm.setData('profile', e.target.value)}
                                            className={INPUT_CLASS}
                                        >
                                            <option value="">— group default: {profileDefault || 'belum diatur'} —</option>
                                            {groupOptions(importForm.data.profile).map((name) => (
                                                <option key={name} value={name}>
                                                    {name}
                                                    {groups.includes(name) ? '' : ' · belum ada di RADIUS'}
                                                </option>
                                            ))}
                                        </select>
                                    ) : (
                                        <input
                                            type="text"
                                            value={importForm.data.profile}
                                            onChange={(e) => importForm.setData('profile', e.target.value)}
                                            className={INPUT_CLASS}
                                            placeholder={radiusGroups === undefined ? 'Memuat group dari RADIUS…' : profilePlaceholder}
                                        />
                                    )}
                                    {groupHint(importForm.data.profile) && (
                                        <span className="mt-1 block text-[11px] leading-tight text-amber-700">
                                            {groupHint(importForm.data.profile)}
                                        </span>
                                    )}
                                </div>
                                <div>
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">
                                        Hotspot Server (catatan)
                                    </label>
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
                                Data disimpan sebagai <strong>pending</strong> dulu. Database RADIUS belum tersentuh sampai kamu
                                klik tombol <strong>Terapkan ke RADIUS</strong>, jadi file yang salah tidak langsung mengubah
                                siapa yang boleh login.
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
