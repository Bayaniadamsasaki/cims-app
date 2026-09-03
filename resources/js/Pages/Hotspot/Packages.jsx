import CimsLayout from '@/Layouts/CimsLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

const INPUT_CLASS =
    'w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600';

/** Isian awal paket baru. Angkanya contoh yang wajar, bukan ketentuan kampus. */
const EMPTY_FORM = {
    name: '',
    download: 8,
    upload: 2,
    session_timeout: '',
    idle_timeout: '',
    interim_interval: '',
    mikrotik_group: '',
    rate_limit_raw: '',
};

/**
 * Detik (satuan RADIUS) → kalimat pendek. Operator berpikir dalam menit dan jam,
 * radgroupreply hanya menerima detik — jadi penerjemahannya terjadi dua kali: di
 * sini untuk dibaca, dan di controller untuk disimpan.
 */
function humanSeconds(seconds) {
    if (!seconds) return null;

    const minutes = Math.round(seconds / 60);

    if (minutes < 60) return `${minutes} menit`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours} jam` : `${hours} jam ${rest} menit`;
}

/**
 * Mbps → token RouterOS, khusus pratinjau.
 *
 * Yang menentukan isi tersimpan adalah App\Support\MikrotikRateLimit di server.
 * Kembaran kecil di sini ada supaya operator melihat bentuk atributnya sebelum
 * menekan Simpan — terutama urutan rx/tx yang paling mudah tertukar.
 */
function rateToken(value) {
    const mbps = Number(value);

    if (!Number.isFinite(mbps) || mbps <= 0) return '?';

    return Number.isInteger(mbps) ? `${mbps}M` : `${Math.round(mbps * 1000)}k`;
}

/** Kecepatan paket sebagai teks, atau nilai mentahnya bila bentuknya lanjut. */
function speedText(pkg) {
    if (pkg.speed) return `${pkg.speed.download} Mbps unduh · ${pkg.speed.upload} Mbps unggah`;

    return pkg.rate_limit || null;
}

/**
 * Paket Hotspot — isi group RADIUS yang dipakai voucher mahasiswa.
 *
 * Halaman voucher menentukan siapa memakai paket mana. Halaman ini menentukan apa
 * isi paketnya: berapa Mbps, berapa lama sesi boleh berjalan, kapan dianggap diam.
 *
 * Satu hal yang wajib dimengerti sebelum memakai halaman ini, dan sebelum
 * mengubahnya: group tanpa policy TIDAK menolak siapa pun. FreeRADIUS menjawab
 * Access-Accept tanpa atribut, mahasiswanya tetap masuk, dan batas yang disangka
 * berlaku tidak pernah ada. Karena itu keadaan "belum punya batas" di halaman ini
 * ditonjolkan, bukan dilewatkan — ia satu-satunya kegagalan di alur RADIUS yang
 * tidak pernah memunculkan pesan error.
 *
 * Penyimpanannya radgroupreply itu sendiri; CIMS tidak menyimpan salinan. Yang
 * tampil di sini apa adanya isi server RADIUS saat halaman dibuka.
 */
export default function Packages({
    packages = [],
    defaultGroup = null,
    managedAttributes = [],
    radiusConfigured = true,
    routerHost,
    routers = [],
    canManage = false,
    connection,
    routerProfiles,
}) {
    const { confirmAction } = useConfirmation();

    // Nama group yang sedang diubah, atau null untuk paket baru. Nama dipakai
    // sebagai identitas karena di RADIUS memang tidak ada id paket — groupname
    // itulah kuncinya.
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [advanced, setAdvanced] = useState(false);

    const form = useForm(EMPTY_FORM);

    // Deferred prop: undefined = masih menunggu router, [] = router terbaca tapi
    // tidak punya user-profile hotspot sama sekali.
    const routerProfileNames = Array.isArray(routerProfiles) ? routerProfiles.map((p) => p.name) : [];

    const withoutPolicy = packages.filter((p) => !p.has_policy);
    const inUse = packages.filter((p) => p.members > 0);
    const defaultPackage = defaultGroup ? packages.find((p) => p.name === defaultGroup) : null;
    const defaultNeedsPolicy = Boolean(defaultGroup) && !defaultPackage?.has_policy;

    const openCreate = (name = '') => {
        setEditing(null);
        setAdvanced(false);
        form.clearErrors();
        form.setData({ ...EMPTY_FORM, name });
        setShowForm(true);
    };

    /**
     * Buka paket yang sudah ada.
     *
     * Nilai rate limit bentuk lanjut (burst, threshold, priority) tidak bisa
     * dijadikan dua angka. Formulir langsung membuka mode lanjut dan menampilkannya
     * apa adanya — menampilkannya sebagai "8 Mbps" lalu menyimpannya kembali akan
     * membuang bagian burst yang pernah disetel seseorang dengan sengaja.
     */
    const openEdit = (pkg) => {
        const raw = !pkg.speed && pkg.rate_limit ? pkg.rate_limit : '';

        setEditing(pkg.name);
        setAdvanced(Boolean(raw));
        form.clearErrors();
        form.setData({
            name: pkg.name,
            download: pkg.speed?.download ?? EMPTY_FORM.download,
            upload: pkg.speed?.upload ?? EMPTY_FORM.upload,
            session_timeout: pkg.session_timeout ? Math.round(pkg.session_timeout / 60) : '',
            idle_timeout: pkg.idle_timeout ? Math.round(pkg.idle_timeout / 60) : '',
            interim_interval: pkg.interim_interval ? Math.round(pkg.interim_interval / 60) : '',
            mikrotik_group: pkg.mikrotik_group ?? '',
            rate_limit_raw: raw,
        });
        setShowForm(true);
    };

    const submitForm = (e) => {
        e.preventDefault();

        // Mode lanjut yang ditutup berarti operator kembali ke dua angka Mbps.
        // Nilai mentahnya dikosongkan supaya controller memakai angka itu, bukan
        // sisa isian yang tidak lagi terlihat di layar.
        form.transform((data) => ({ ...data, rate_limit_raw: advanced ? data.rate_limit_raw : '' }));

        const options = { preserveScroll: true, onSuccess: () => setShowForm(false) };

        if (editing) {
            form.post(route('hotspot.packages.update', editing), options);
        } else {
            form.post(route('hotspot.packages.store'), options);
        }
    };

    const removePackage = (pkg) =>
        confirmAction({
            title: 'Hapus Paket',
            message:
                `Hapus policy paket "${pkg.name}"? Semua batas kecepatan dan batas sesinya hilang. ` +
                'Voucher tidak ikut terhapus — kalau masih ada yang memakai paket ini, mereka justru akan ' +
                'login tanpa batas apa pun, jadi pastikan tidak ada lagi anggotanya.',
            confirmLabel: 'Hapus Paket',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => router.delete(route('hotspot.packages.destroy', pkg.name), { preserveScroll: true }),
        });

    /** Pratinjau atribut yang akan tersimpan, dihitung ulang setiap ketikan. */
    const previewRate = advanced
        ? form.data.rate_limit_raw || '(kosong)'
        : `${rateToken(form.data.upload)}/${rateToken(form.data.download)}`;

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">Paket Hotspot</h2>
                        <p className="max-w-3xl text-sm text-slate-500">
                            Isi group RADIUS yang dipakai voucher: berapa Mbps, berapa lama sesi berjalan. Halaman{' '}
                            <span className="font-semibold text-slate-700">Voucher WiFi Mahasiswa</span> menentukan siapa
                            memakai paket mana — di sini isinya.
                        </p>
                    </div>

                    <div className="flex flex-col items-end gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            {routers.length > 0 && (
                                <select
                                    value={routerHost ?? ''}
                                    onChange={(e) => router.get(route('hotspot.packages.index'), { host: e.target.value })}
                                    className="rounded-xl border-slate-200 bg-white text-sm font-semibold text-slate-700"
                                    aria-label="Router pembanding nama user-profile"
                                >
                                    {routers.map((r) => (
                                        <option key={r.ip} value={r.ip}>
                                            {r.name} ({r.ip})
                                        </option>
                                    ))}
                                </select>
                            )}
                            {canManage && (
                                <button
                                    onClick={() => openCreate()}
                                    disabled={!radiusConfigured}
                                    title={
                                        radiusConfigured
                                            ? undefined
                                            : 'RADIUS_DB_* belum diisi di .env, jadi belum ada tujuan yang bisa ditulis.'
                                    }
                                    className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    Tambah Paket
                                </button>
                            )}
                        </div>
                        <span className="text-[11px] leading-tight text-slate-400">
                            Router hanya dipakai untuk membandingkan nama user-profile — paket tersimpan di RADIUS.
                        </span>
                    </div>
                </div>
            }
        >
            <Head title="Paket Hotspot" />

            {/* Status server RADIUS. Deferred prop: undefined selama masih dimuat.
                Tanpa koneksi, halaman ini tidak bisa membaca maupun menyimpan — jadi
                statusnya di paling atas, bukan di bawah daftar. */}
            <div className="mb-4 rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm">
                {!radiusConfigured ? (
                    <span className="text-amber-700">
                        Koneksi RADIUS belum diatur. Isi <code>RADIUS_DB_HOST</code>, <code>RADIUS_DB_DATABASE</code>,{' '}
                        <code>RADIUS_DB_USERNAME</code>, dan <code>RADIUS_DB_PASSWORD</code> di <code>.env</code>, lalu
                        jalankan <code>php artisan radius:doctor</code>.
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
                        Server RADIUS tidak bisa dihubungi: {connection?.error ?? 'tidak diketahui'}. Daftar paket di bawah
                        mungkin kosong bukan karena belum ada paket.
                    </span>
                )}
            </div>

            {/* Paket default yang belum punya policy adalah keadaan yang paling perlu
                diketahui: setiap voucher baru mengarah ke situ, jadi seluruh kampus
                login tanpa batas sementara halaman voucher tampak beres. */}
            {defaultNeedsPolicy && (
                <div className="mb-4 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-sm text-amber-900">
                    <strong className="block">
                        Paket default <code>{defaultGroup}</code>{' '}
                        {defaultPackage ? 'belum punya batas apa pun.' : 'belum ada di RADIUS.'}
                    </strong>
                    <p className="mt-1">
                        Semua voucher baru memakai paket ini (<code>HOTSPOT_RADIUS_DEFAULT_GROUP</code>). Selama isinya
                        kosong, mahasiswanya <strong>tetap bisa login</strong> — hanya tanpa batas kecepatan sama sekali,
                        dan tanpa satu pun pesan error yang menandakannya.
                        {defaultPackage?.members > 0 && ` Saat ini ${defaultPackage.members} voucher sudah mengarah ke sini.`}
                    </p>
                    {canManage && (
                        <button
                            onClick={() => (defaultPackage ? openEdit(defaultPackage) : openCreate(defaultGroup))}
                            className="mt-3 inline-flex items-center rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700"
                        >
                            Isi paket {defaultGroup} sekarang
                        </button>
                    )}
                </div>
            )}

            {/* Group lain yang dipakai voucher tapi masih kosong. Sebabnya biasanya
                salah ketik di kolom Paket, jadi yang ditawarkan mengisi policy-nya —
                bukan menghapus vouchernya. */}
            {withoutPolicy.filter((p) => p.name !== defaultGroup).length > 0 && (
                <div className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-4 text-sm text-slate-700">
                    <strong>Group tanpa batas:</strong>{' '}
                    {withoutPolicy
                        .filter((p) => p.name !== defaultGroup)
                        .map((p) => `${p.name} (${p.members} voucher)`)
                        .join(', ')}
                    . Nama ini dipakai voucher tapi belum punya satu pun atribut di RADIUS. Isi paketnya, atau perbaiki
                    kolom Paket voucher itu di halaman Voucher WiFi Mahasiswa.
                </div>
            )}

            {/* Ringkasan. 'Dipakai voucher' dihitung dari radusergroup, bukan dari
                tabel voucher CIMS: yang menentukan paket saat login adalah baris di
                RADIUS, dan selisih keduanya justru yang perlu terlihat. */}
            <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                {[
                    ['Paket', packages.length, 'text-slate-900', 'group terbaca di RADIUS'],
                    ['Sudah ada isinya', packages.length - withoutPolicy.length, 'text-emerald-600', 'punya batas kecepatan'],
                    ['Belum ada isinya', withoutPolicy.length, 'text-amber-600', 'login tanpa batas'],
                    ['Dipakai voucher', inUse.length, 'text-slate-900', 'ada anggotanya di radusergroup'],
                ].map(([label, value, tone, hint]) => (
                    <div key={label} className="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-xs font-semibold text-slate-500">{label}</p>
                        <p className={`mt-1 text-2xl font-bold ${tone}`}>{value}</p>
                        <p className="text-[11px] leading-tight text-slate-400">{hint}</p>
                    </div>
                ))}
            </div>

            {packages.length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-white px-6 py-12 text-center">
                    <p className="text-sm font-semibold text-slate-700">Belum ada paket di server RADIUS.</p>
                    <p className="mx-auto mt-1 max-w-xl text-sm text-slate-500">
                        Paket adalah group di <code>radgroupreply</code>. Selama belum ada, voucher yang diterapkan tetap
                        bisa login — tanpa batas kecepatan sama sekali.
                    </p>
                    {canManage && radiusConfigured && (
                        <button
                            onClick={() => openCreate(defaultGroup ?? '')}
                            className="mt-4 inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                        >
                            Buat paket {defaultGroup ? `"${defaultGroup}"` : 'pertama'}
                        </button>
                    )}
                </div>
            ) : (
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    {packages.map((pkg) => (
                        <PackageCard
                            key={pkg.name}
                            pkg={pkg}
                            isDefault={pkg.name === defaultGroup}
                            canManage={canManage}
                            routerProfileNames={routerProfileNames}
                            onEdit={() => openEdit(pkg)}
                            onDelete={() => removePackage(pkg)}
                        />
                    ))}
                </div>
            )}

            {/* Dua pertanyaan yang selalu muncul setelah menyimpan, dijawab di tempat
                yang terlihat: kapan berlakunya, dan apa yang tidak ikut berubah. */}
            <div className="mt-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-xs leading-relaxed text-slate-500">
                <p>
                    <strong className="text-slate-700">Kapan berlakunya:</strong> FreeRADIUS membaca policy setiap kali ada
                    Access-Request, jadi perubahan langsung berlaku pada login berikutnya — tanpa restart FreeRADIUS dan
                    tanpa menerapkan ulang voucher. Sesi yang sedang berjalan tetap memakai batas lamanya sampai mahasiswa
                    login lagi; putuskan dari panel sesi aktif di halaman voucher bila perlu segera.
                </p>
                <p className="mt-2">
                    <strong className="text-slate-700">Yang dikelola halaman ini:</strong>{' '}
                    {managedAttributes.length > 0 ? (
                        managedAttributes.map((attribute) => (
                            <code key={attribute} className="mr-1.5">
                                {attribute}
                            </code>
                        ))
                    ) : (
                        <span>—</span>
                    )}{' '}
                    di <code>radgroupreply</code>. Atribut lain di group yang sama ditampilkan sebagai keterangan dan tidak
                    pernah diubah, dan <code>radgroupcheck</code> hanya dibaca — izin CIMS di tabel itu memang cuma SELECT.
                </p>
            </div>

            {showForm && (
                <div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/40 p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-2xl rounded-2xl border border-slate-200 bg-white p-6">
                        <div className="mb-5 flex items-start justify-between border-b border-slate-200 pb-4">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">
                                    {editing ? `Ubah Paket ${editing}` : 'Tambah Paket Hotspot'}
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Tersimpan sebagai atribut group di RADIUS · berlaku untuk semua voucher yang memakai paket
                                    ini
                                </p>
                            </div>
                            <button
                                onClick={() => setShowForm(false)}
                                className="text-slate-400 transition hover:text-slate-700"
                                aria-label="Tutup"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form onSubmit={submitForm} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Nama paket*</label>
                                <input
                                    type="text"
                                    required
                                    readOnly={Boolean(editing)}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder="mahasiswa"
                                    className={`${INPUT_CLASS} font-mono ${editing ? 'cursor-not-allowed text-slate-500' : ''}`}
                                />
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    {editing
                                        ? 'Nama tidak bisa diubah di sini: mengganti nama group berarti memindahkan seluruh anggotanya juga. Ubah kolom Paket voucher di halaman Voucher WiFi Mahasiswa.'
                                        : 'Huruf, angka, titik, garis bawah, dan tanda hubung. Tanpa spasi — nama ini ikut muncul di konfigurasi router dan FreeRADIUS. Isi kolom Paket voucher dengan nama yang sama.'}
                                </span>
                                {form.errors.name && <span className="mt-1 block text-xs text-rose-700">{form.errors.name}</span>}
                            </div>

                            {/*
                              Batasnya disamakan dengan aturan server di
                              HotspotPackageWebController::validatePackage(): numeric,
                              min 0.064, max 10000.

                              step="any" disengaja, bukan kelalaian. HTML menghitung
                              langkah dari `min`, jadi step numerik berapa pun membuat
                              nilai sah menjadi 0.064, 0.164, 0.264 … dan MENOLAK angka
                              bulat — "2" ditolak dengan pesan "nilai terdekat 1.964 dan
                              2.064". Server sendiri tidak punya aturan langkah, dan
                              nilainya toh dibulatkan ke kelipatan 1k saat disimpan.
                            */}
                            {!advanced && (
                                <>
                                    <div>
                                        <label className="mb-1 block text-xs font-semibold text-slate-600">
                                            Kecepatan unduh (Mbps)*
                                        </label>
                                        <input
                                            type="number"
                                            required
                                            step="any"
                                            min="0.064"
                                            max="10000"
                                            value={form.data.download}
                                            onChange={(e) => form.setData('download', e.target.value)}
                                            className={INPUT_CLASS}
                                        />
                                        {form.errors.download && (
                                            <span className="mt-1 block text-xs text-rose-700">{form.errors.download}</span>
                                        )}
                                    </div>

                                    <div>
                                        <label className="mb-1 block text-xs font-semibold text-slate-600">
                                            Kecepatan unggah (Mbps)*
                                        </label>
                                        <input
                                            type="number"
                                            required
                                            step="any"
                                            min="0.064"
                                            max="10000"
                                            value={form.data.upload}
                                            onChange={(e) => form.setData('upload', e.target.value)}
                                            className={INPUT_CLASS}
                                        />
                                        {form.errors.upload && (
                                            <span className="mt-1 block text-xs text-rose-700">{form.errors.upload}</span>
                                        )}
                                    </div>

                                    <span className="sm:col-span-2 -mt-1 block text-[11px] leading-tight text-slate-400">
                                        Bilangan bulat maupun pecahan: <code>8</code> berarti 8M, <code>0.512</code>{' '}
                                        berarti 512k. Terkecil 0.064 (64k), terbesar 10000.
                                    </span>
                                </>
                            )}

                            {advanced && (
                                <div className="sm:col-span-2">
                                    <label className="mb-1 block text-xs font-semibold text-slate-600">
                                        Mikrotik-Rate-Limit (mentah)
                                    </label>
                                    <input
                                        type="text"
                                        value={form.data.rate_limit_raw}
                                        onChange={(e) => form.setData('rate_limit_raw', e.target.value)}
                                        placeholder="2M/8M 4M/16M 3M/12M 8 8"
                                        className={`${INPUT_CLASS} font-mono`}
                                    />
                                    <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                        Urutannya <code>rx/tx</code> dari sudut pandang router: <code>rx</code> = unggah
                                        mahasiswa, <code>tx</code> = unduh. Lanjutannya{' '}
                                        <code>burst rx/tx</code>, <code>threshold rx/tx</code>, <code>burst-time</code>,{' '}
                                        <code>priority</code>. Ditulis apa adanya, dan bila kolom ini terisi dua angka Mbps di
                                        atas diabaikan.
                                    </span>
                                    {form.errors.rate_limit_raw && (
                                        <span className="mt-1 block text-xs text-rose-700">{form.errors.rate_limit_raw}</span>
                                    )}
                                </div>
                            )}

                            <div className="sm:col-span-2 -mt-1 flex flex-wrap items-center justify-between gap-2">
                                <button
                                    type="button"
                                    onClick={() => setAdvanced((v) => !v)}
                                    className="text-xs font-semibold text-blue-700 transition hover:text-blue-900"
                                >
                                    {advanced ? 'Kembali ke dua angka Mbps' : 'Isi rate limit mentah (burst, priority)'}
                                </button>
                                <span className="rounded-lg bg-slate-100 px-2.5 py-1 font-mono text-xs text-slate-700">
                                    Mikrotik-Rate-Limit := {previewRate}
                                </span>
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Batas sesi (menit)</label>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.data.session_timeout}
                                    onChange={(e) => form.setData('session_timeout', e.target.value)}
                                    placeholder="Kosong = tanpa batas"
                                    className={INPUT_CLASS}
                                />
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Sesi diputus setelah selang ini, mahasiswa bisa login lagi. 480 = 8 jam.
                                </span>
                                {form.errors.session_timeout && (
                                    <span className="mt-1 block text-xs text-rose-700">{form.errors.session_timeout}</span>
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">Batas diam (menit)</label>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.data.idle_timeout}
                                    onChange={(e) => form.setData('idle_timeout', e.target.value)}
                                    placeholder="Kosong = tanpa batas"
                                    className={INPUT_CLASS}
                                />
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Melepas sesi yang tidak dipakai — mis. mahasiswa yang sudah pulang tanpa logout.
                                </span>
                                {form.errors.idle_timeout && (
                                    <span className="mt-1 block text-xs text-rose-700">{form.errors.idle_timeout}</span>
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">
                                    Interval laporan pemakaian (menit)
                                </label>
                                <input
                                    type="number"
                                    min="1"
                                    value={form.data.interim_interval}
                                    onChange={(e) => form.setData('interim_interval', e.target.value)}
                                    placeholder="Kosong = ikut pengaturan router"
                                    className={INPUT_CLASS}
                                />
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Seberapa sering router melaporkan pemakaian ke <code>radacct</code>. Terlalu kecil membuat
                                    tabel accounting tumbuh cepat; 5–15 menit wajar.
                                </span>
                                {form.errors.interim_interval && (
                                    <span className="mt-1 block text-xs text-rose-700">{form.errors.interim_interval}</span>
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold text-slate-600">
                                    User-profile router (opsional)
                                </label>
                                <input
                                    type="text"
                                    list="router-profiles"
                                    value={form.data.mikrotik_group}
                                    onChange={(e) => form.setData('mikrotik_group', e.target.value)}
                                    placeholder="Kosong = tidak dikirim"
                                    className={`${INPUT_CLASS} font-mono`}
                                />
                                <datalist id="router-profiles">
                                    {routerProfileNames.map((name) => (
                                        <option key={name} value={name} />
                                    ))}
                                </datalist>
                                <span className="mt-1 block text-[11px] leading-tight text-slate-400">
                                    Atribut <code>Mikrotik-Group</code>: memaksa router memakai user-profile bernama ini.
                                    Hanya perlu bila profile itu mengatur hal yang tidak bisa dikirim RADIUS — antrian induk,
                                    address-list. Isi hanya kalau nama itu benar-benar ada di router.
                                </span>
                                {form.errors.mikrotik_group && (
                                    <span className="mt-1 block text-xs text-rose-700">{form.errors.mikrotik_group}</span>
                                )}
                            </div>

                            {/* Nama user-profile yang tidak ada di router adalah kegagalan
                                senyap yang lain: MikroTik menolak sesinya, dan yang
                                terlihat operator cuma "mahasiswa tidak bisa login". */}
                            {form.data.mikrotik_group &&
                                routerProfileNames.length > 0 &&
                                !routerProfileNames.includes(form.data.mikrotik_group) && (
                                    <div className="sm:col-span-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                                        Tidak ada user-profile bernama <code>{form.data.mikrotik_group}</code> di router{' '}
                                        {routerHost}. Router bisa menolak sesinya. Kosongkan kolom ini bila batas kecepatan
                                        sudah diatur di paket ini.
                                    </div>
                                )}

                            <div className="sm:col-span-2 mt-1 flex items-center justify-end gap-2 border-t border-slate-200 pt-4">
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
                                    className="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                >
                                    {form.processing ? 'Menyimpan…' : editing ? 'Simpan Perubahan' : 'Simpan Paket'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CimsLayout>
    );
}

/**
 * Satu paket sebagai kartu.
 *
 * Yang ditonjolkan kecepatannya, karena itu yang ditanyakan orang. Yang dijaga
 * agar tidak hilang: keadaan "belum punya batas", jumlah anggota (angka itulah
 * yang menentukan seberapa berani mengubah paket ini), dan atribut yang berlaku di
 * group ini tapi bukan milik formulir.
 */
function PackageCard({ pkg, isDefault, canManage, routerProfileNames, onEdit, onDelete }) {
    const speed = speedText(pkg);
    const limits = [
        ['Batas sesi', humanSeconds(pkg.session_timeout)],
        ['Batas diam', humanSeconds(pkg.idle_timeout)],
        ['Lapor pemakaian', humanSeconds(pkg.interim_interval)],
    ].filter(([, value]) => value);

    const profileMissing =
        pkg.mikrotik_group && routerProfileNames.length > 0 && !routerProfileNames.includes(pkg.mikrotik_group);

    return (
        <div
            className={`flex flex-col rounded-2xl border bg-white p-5 ${
                pkg.has_policy ? 'border-slate-200' : 'border-amber-300 bg-amber-50/40'
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="truncate font-mono text-base font-bold text-slate-900">{pkg.name}</h3>
                        {isDefault && (
                            <span className="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">
                                Paket default
                            </span>
                        )}
                        {!pkg.has_policy && (
                            <span className="rounded-full border border-amber-300 bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                                Belum ada batas
                            </span>
                        )}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                        {pkg.members > 0 ? `${pkg.members} voucher memakai paket ini` : 'Belum dipakai voucher mana pun'}
                    </p>
                </div>

                {canManage && (
                    <div className="flex shrink-0 gap-1.5">
                        <button
                            onClick={onEdit}
                            className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            {pkg.has_policy ? 'Ubah' : 'Isi'}
                        </button>
                        <button
                            onClick={onDelete}
                            disabled={pkg.members > 0}
                            title={pkg.members > 0 ? 'Masih dipakai voucher — pindahkan dulu anggotanya.' : undefined}
                            className="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-300"
                        >
                            Hapus
                        </button>
                    </div>
                )}
            </div>

            <div className="mt-4 border-t border-slate-200 pt-4">
                {speed ? (
                    <>
                        <p className="text-lg font-bold text-slate-900">{speed}</p>
                        <p className="font-mono text-[11px] text-slate-400">Mikrotik-Rate-Limit := {pkg.rate_limit}</p>
                    </>
                ) : (
                    <p className="text-sm text-amber-800">
                        Tanpa batas kecepatan. Mahasiswa yang memakai paket ini tetap bisa login, hanya tanpa batas apa pun.
                    </p>
                )}
            </div>

            {limits.length > 0 && (
                <dl className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-500">
                    {limits.map(([label, value]) => (
                        <div key={label} className="flex items-baseline gap-1.5">
                            <dt className="font-semibold">{label}:</dt>
                            <dd className="text-slate-700">{value}</dd>
                        </div>
                    ))}
                </dl>
            )}

            {pkg.mikrotik_group && (
                <p className={`mt-3 text-xs ${profileMissing ? 'text-amber-800' : 'text-slate-500'}`}>
                    User-profile router: <code className="font-semibold">{pkg.mikrotik_group}</code>
                    {profileMissing && ' — nama ini tidak ada di router yang sedang dipilih.'}
                </p>
            )}

            {/* Atribut di luar jangkauan formulir. Ditampilkan supaya tidak ada yang
                menyangka paket ini cuma berisi kecepatan, dan diberi keterangan
                mengapa tidak bisa disunting dari sini. */}
            {(pkg.extra.length > 0 || pkg.check.length > 0) && (
                <div className="mt-4 rounded-xl bg-slate-50 px-3 py-2.5 text-[11px] leading-relaxed text-slate-500">
                    {pkg.extra.length > 0 && (
                        <p>
                            <span className="font-semibold text-slate-600">Atribut lain di group ini</span> (tidak diubah CIMS):{' '}
                            {pkg.extra.map((row) => (
                                <code key={`${row.attribute}${row.value}`} className="mr-2 font-mono">
                                    {row.attribute} {row.op} {row.value}
                                </code>
                            ))}
                        </p>
                    )}
                    {pkg.check.length > 0 && (
                        <p className={pkg.extra.length > 0 ? 'mt-1.5' : undefined}>
                            <span className="font-semibold text-slate-600">Syarat login</span> (radgroupcheck, hanya dibaca):{' '}
                            {pkg.check.map((row) => (
                                <code key={`${row.attribute}${row.value}`} className="mr-2 font-mono">
                                    {row.attribute} {row.op} {row.value}
                                </code>
                            ))}
                        </p>
                    )}
                </div>
            )}
        </div>
    );
}
