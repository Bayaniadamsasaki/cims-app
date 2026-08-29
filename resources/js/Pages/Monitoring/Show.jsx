import StatusBadge from '@/Components/Cims/StatusBadge';
import CimsLayout from '@/Layouts/CimsLayout';
import { Head, Link } from '@inertiajs/react';

/**
 * Detail telemetri satu perangkat.
 *
 * Semua angka di halaman ini berasal dari pembacaan nyata yang tersimpan: ICMP
 * untuk jangkauan, RouterOS API atau SNMP untuk metrik perangkat. Sampel yang
 * tidak terukur dibiarkan kosong — tidak pernah digambar sebagai nol dan tidak
 * pernah diganti angka karangan. Status di header pun diambil dari hasil pindai
 * terakhir, bukan dari kolom status inventaris, supaya kegagalan monitoring tidak
 * tertutup badge hijau.
 */

/** Metrik hanya dirender bila benar-benar terukur; nol yang terukur tetap nol. */
const hasValue = (value) => value !== null && value !== undefined;

/** Penanda seragam untuk data yang tidak tersedia. */
const NoData = () => <span className="text-brand-textMuted">—</span>;

const formatUptime = (seconds) => {
    if (!hasValue(seconds)) return null;

    const total = Number(seconds);

    if (!Number.isFinite(total)) return null;

    const days = Math.floor(total / 86400);
    const hours = Math.floor((total % 86400) / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (days > 0) return `${days} hari ${hours} jam`;
    if (hours > 0) return `${hours} jam ${minutes} mnt`;

    return `${minutes} mnt`;
};

const formatDateTime = (value) => {
    if (!value) return null;

    const at = new Date(value);

    return Number.isNaN(at.getTime())
        ? null
        : at.toLocaleString('id-ID', { dateStyle: 'short', timeStyle: 'short' });
};

const formatMbps = (bps) => (hasValue(bps) ? `${(Number(bps) / 1000000).toFixed(1)}M` : null);
const CHART_WIDTH = 500;
const CHART_HEIGHT = 150;
const CHART_PADDING = 20;

/**
 * Titik grafik dibangun hanya dari sampel yang benar-benar terukur.
 *
 * Siklus pemindaian yang gagal membaca metrik disimpan sebagai NULL, dan sengaja
 * tidak digambar: menjadikannya nol berarti mengarang pembacaan yang tidak pernah
 * ada dan membuat grafik terlihat seperti perangkat yang jatuh ke 0%. Posisi X
 * tetap memakai indeks aslinya supaya jeda pengukuran terlihat sebagai celah.
 */
const buildSeries = (logs, key, baseMax) => {
    const measured = logs
        .map((log, index) => ({ index, value: Number(log[key]) }))
        .filter(({ index, value }) => hasValue(logs[index][key]) && Number.isFinite(value));

    const summary = { points: '', pointCount: measured.length, total: logs.length, max: baseMax };

    if (measured.length === 0) return summary;

    const max = Math.max(baseMax, ...measured.map(({ value }) => value)) || 1;
    const span = Math.max(1, logs.length - 1);
    const usableWidth = CHART_WIDTH - CHART_PADDING * 2;
    const usableHeight = CHART_HEIGHT - CHART_PADDING * 2;

    const points = measured
        .map(({ index, value }) => {
            const x = CHART_PADDING + (index / span) * usableWidth;
            const y = CHART_HEIGHT - CHART_PADDING - (value / max) * usableHeight;

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');

    return { ...summary, points, max };
};

/** Satu sampel tidak bisa membentuk garis, jadi digambar sebagai titik. */
const SeriesShape = ({ series, color, dashed = false }) => {
    if (series.pointCount === 0) return null;

    if (series.pointCount === 1) {
        const [x, y] = series.points.split(',');

        return <circle cx={x} cy={y} r="3.5" fill={color} />;
    }

    return (
        <polyline
            fill="none"
            stroke={color}
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeDasharray={dashed ? '5 4' : undefined}
            points={series.points}
        />
    );
};

const TrendChart = ({ title, subtitle, lines, emptyLabel, scaleLabel }) => {
    const measuredPoints = Math.max(...lines.map(({ series }) => series.pointCount), 0);
    const totalSamples = lines[0]?.series.total ?? 0;

    return (
        <div className="rounded-2xl border border-brand-border bg-white p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-brand-text">{title}</h3>
                    <p className="mt-0.5 text-xs text-brand-textMuted">{subtitle}</p>
                </div>
                <div className="flex flex-wrap gap-3 text-xs">
                    {lines.map(({ label, color }) => (
                        <span key={label} className="inline-flex items-center gap-1.5 text-brand-textMuted">
                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: color }} />
                            {label}
                        </span>
                    ))}
                </div>
            </div>
            {measuredPoints === 0 ? (
                <div className="mt-4 flex h-[150px] items-center justify-center rounded-xl border border-dashed border-brand-border bg-brand-surface px-4 text-center text-xs text-brand-textMuted">
                    {emptyLabel}
                </div>
            ) : (
                <>
                    <svg
                        viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`}
                        className="mt-4 h-[150px] w-full"
                        preserveAspectRatio="none"
                        role="img"
                        aria-label={`${title}: ${measuredPoints} dari ${totalSamples} siklus terukur`}
                    >
                        {[0, 0.5, 1].map((ratio) => {
                            const y = CHART_PADDING + ratio * (CHART_HEIGHT - CHART_PADDING * 2);

                            return (
                                <line
                                    key={ratio}
                                    x1={CHART_PADDING}
                                    y1={y}
                                    x2={CHART_WIDTH - CHART_PADDING}
                                    y2={y}
                                    stroke="#E4E4E7"
                                    strokeWidth="1"
                                />
                            );
                        })}
                        {lines.map(({ label, color, dashed, series }) => (
                            <SeriesShape key={label} series={series} color={color} dashed={dashed} />
                        ))}
                    </svg>
                    <p className="mt-2 text-[11px] text-brand-textMuted">
                        {measuredPoints} dari {totalSamples} siklus pindai terukur · skala puncak {scaleLabel}
                        {measuredPoints < totalSamples
                            ? ' · celah = metrik gagal dibaca pada siklus itu'
                            : ''}
                    </p>
                </>
            )}
        </div>
    );
};

const SpecCard = ({ label, value, hint }) => (
    <div className="rounded-2xl border border-brand-border bg-white p-4">
        <p className="text-[11px] font-medium uppercase tracking-wide text-brand-textMuted">{label}</p>
        <p className="mt-1 truncate text-lg font-semibold text-brand-text" title={typeof value === 'string' ? value : undefined}>
            {hasValue(value) && value !== '' ? value : <NoData />}
        </p>
        {hint ? <p className="mt-1 text-xs text-brand-textMuted">{hint}</p> : null}
    </div>
);

/**
 * Penjelasan kondisi pemindaian terakhir. Tujuannya agar angka lama tidak dibaca
 * sebagai hasil pengukuran terbaru ketika pemindaian terakhir sebenarnya gagal.
 */
const SCAN_NOTICE = {
    degraded: {
        tone: 'warning',
        title: 'Perangkat menjawab ICMP, metrik gagal dibaca',
        body: 'RouterOS API/SNMP tidak mengembalikan data pada pemindaian terakhir. Nilai CPU, RAM, dan uptime di bawah adalah pembacaan valid terakhir — bukan hasil siklus terbaru.',
    },
    unreachable: {
        tone: 'critical',
        title: 'Tidak ada balasan ICMP pada pemindaian terakhir',
        body: 'Perangkat tidak terjangkau dari server monitoring. Metrik di bawah adalah pembacaan valid terakhir sebelum perangkat berhenti menjawab.',
    },
    offline: {
        tone: 'critical',
        title: 'Pemindaian terakhir tidak menemukan perangkat',
        body: 'Metrik di bawah adalah pembacaan valid terakhir yang tersimpan, bukan hasil siklus terbaru.',
    },
    error: {
        tone: 'critical',
        title: 'Monitoring gagal dijalankan',
        body: 'Pemeriksaan tidak dapat diselesaikan — periksa alamat monitoring, kredensial, atau ketersediaan protokol pada perangkat ini. Tidak ada pengukuran baru yang tersimpan pada siklus terakhir.',
    },
    unknown: {
        tone: 'muted',
        title: 'Belum pernah dipindai',
        body: 'Belum ada pembacaan yang tersimpan untuk perangkat ini. Kolom kosong berarti belum ada data, bukan berarti perangkat sehat.',
    },
};

const NOTICE_TONE = {
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
    critical: 'border-red-200 bg-red-50 text-red-900',
    muted: 'border-brand-border bg-brand-surface text-brand-text',
};
export default function Show({ device = {}, historyLogs = [] }) {
    const metrics = device.metrics ?? {};

    // Badge status memakai hasil pindai terakhir; kolom `device.status` hanyalah
    // niat administratif (active/maintenance/inactive) dan tidak pernah menjadi
    // bukti bahwa perangkat sedang hidup.
    const pingStatus = metrics.last_ping_status ?? 'unknown';
    const isMaintenance = device.status === 'maintenance';
    const notice = SCAN_NOTICE[pingStatus] ?? null;
    const lastChecked = formatDateTime(metrics.last_checked_at);

    // CPU dan RAM berbagi satu skala; batas bawahnya 100% supaya beban rendah
    // tidak terlihat mepet puncak grafik.
    const percentValues = historyLogs
        .flatMap((log) => [log.cpu_usage_percent, log.ram_usage_percent])
        .filter(hasValue)
        .map(Number)
        .filter((value) => Number.isFinite(value));
    const percentPeak = Math.max(100, ...percentValues);
    const cpuSeries = buildSeries(historyLogs, 'cpu_usage_percent', percentPeak);
    const ramSeries = buildSeries(historyLogs, 'ram_usage_percent', percentPeak);

    // Rx dan Tx memakai satu skala puncak yang sama agar bisa dibandingkan.
    const trafficValues = historyLogs
        .flatMap((log) => [log.bandwidth_rx_bps, log.bandwidth_tx_bps])
        .filter(hasValue)
        .map(Number)
        .filter((value) => Number.isFinite(value));
    const trafficPeak = trafficValues.length > 0 ? Math.max(...trafficValues) : 1;
    const rxSeries = buildSeries(historyLogs, 'bandwidth_rx_bps', trafficPeak);
    const txSeries = buildSeries(historyLogs, 'bandwidth_tx_bps', trafficPeak);

    const sampleRange =
        historyLogs.length > 0
            ? [
                  formatDateTime(historyLogs[0].checked_at),
                  formatDateTime(historyLogs[historyLogs.length - 1].checked_at),
              ]
                  .filter(Boolean)
                  .join(' → ')
            : null;

    const newestFirst = historyLogs.slice().reverse();
    const uptimeText = formatUptime(metrics.last_uptime_seconds);
    const historySubtitle = sampleRange
        ? `${historyLogs.length} siklus pindai terakhir · ${sampleRange}`
        : 'Belum ada riwayat pindai untuk perangkat ini';

    return (
        <CimsLayout
            header={
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <Link
                            href={route('monitoring.index')}
                            className="text-xs font-medium text-brand-primary hover:underline"
                        >
                            ← Kembali ke Pemantauan
                        </Link>
                        <h2 className="mt-1 text-2xl font-bold tracking-tight text-brand-text">
                            {device.name ?? 'Perangkat'}
                        </h2>
                        <p className="text-sm text-brand-textMuted">
                            Riwayat pengukuran nyata perangkat ini — jangkauan ICMP, ditambah metrik dari
                            RouterOS API atau SNMP bila protokolnya tersedia.
                        </p>
                    </div>
                    <div className="flex flex-col items-start gap-2 sm:items-end">
                        <div className="flex flex-wrap gap-2">
                            {isMaintenance ? <StatusBadge status="maintenance" /> : null}
                            <StatusBadge status={pingStatus} />
                        </div>
                        <p className="text-xs text-brand-textMuted">
                            {lastChecked ? `Pindai terakhir ${lastChecked}` : 'Belum pernah dipindai'}
                        </p>
                    </div>
                </div>
            }
        >
            <Head title={`Monitoring · ${device.name ?? 'Perangkat'}`} />

            <div className="space-y-6 text-brand-text">
                {notice ? (
                    <div className={`rounded-2xl border p-4 ${NOTICE_TONE[notice.tone]}`} role="status">
                        <p className="text-sm font-semibold">{notice.title}</p>
                        <p className="mt-1 text-xs leading-relaxed">{notice.body}</p>
                    </div>
                ) : null}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SpecCard
                        label="Alamat Monitoring"
                        value={device.ip_address}
                        hint={
                            device.ip_address
                                ? device.hostname || 'Target ICMP & API'
                                : 'Belum diisi — monitoring tidak dapat dijalankan'
                        }
                    />
                    <SpecCard
                        label="Perangkat"
                        value={device.model}
                        hint={[device.vendor?.name, device.category?.name].filter(Boolean).join(' · ') || null}
                    />
                    <SpecCard
                        label="Lokasi"
                        value={device.building?.name}
                        hint={
                            [device.floor?.name, device.room?.name, device.rack?.name]
                                .filter(Boolean)
                                .join(' · ') || null
                        }
                    />
                    <SpecCard
                        label="Uptime Terakhir Terbaca"
                        value={uptimeText}
                        hint={
                            uptimeText
                                ? lastChecked && pingStatus === 'online'
                                    ? `Terbaca ${lastChecked}`
                                    : 'Pembacaan valid terakhir, bukan siklus terbaru'
                                : 'Perangkat tidak melaporkan uptime'
                        }
                    />
                </div>
                {/* Pembacaan terakhir. Kolom yang tidak terukur tampil sebagai "—", bukan 0. */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                    <SpecCard
                        label="Latensi ICMP"
                        value={hasValue(metrics.last_ping_latency_ms) ? `${metrics.last_ping_latency_ms} ms` : null}
                    />
                    <SpecCard
                        label="Packet Loss"
                        value={
                            hasValue(metrics.last_packet_loss_percent)
                                ? `${metrics.last_packet_loss_percent}%`
                                : null
                        }
                    />
                    <SpecCard
                        label="CPU"
                        value={hasValue(metrics.last_cpu_usage_percent) ? `${metrics.last_cpu_usage_percent}%` : null}
                    />
                    <SpecCard
                        label="RAM"
                        value={hasValue(metrics.last_ram_usage_percent) ? `${metrics.last_ram_usage_percent}%` : null}
                    />
                    <SpecCard
                        label="Storage"
                        value={
                            hasValue(metrics.last_storage_usage_percent)
                                ? `${metrics.last_storage_usage_percent}%`
                                : null
                        }
                    />
                    <SpecCard
                        label="Suhu"
                        value={
                            hasValue(metrics.last_temperature_celsius)
                                ? `${metrics.last_temperature_celsius}°C`
                                : null
                        }
                    />
                </div>
                {Array.isArray(metrics.last_interface_status) && metrics.last_interface_status.length > 0 ? (
                    <div className="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 className="text-sm font-semibold text-brand-text">Interface Terakhir Dilaporkan</h3>
                        <p className="mt-0.5 text-xs text-brand-textMuted">
                            Daftar ini murni hasil pembacaan perangkat — tidak ada interface yang dibuatkan
                            sistem saat pembacaan gagal.
                        </p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {metrics.last_interface_status.map((iface, index) => (
                                <span
                                    key={`${iface?.name ?? 'iface'}-${index}`}
                                    className="inline-flex items-center gap-2 rounded-xl border border-brand-border bg-brand-surface px-3 py-1.5"
                                >
                                    <span className="text-xs font-medium text-brand-text">
                                        {iface?.name ?? '—'}
                                    </span>
                                    <StatusBadge
                                        status={iface?.status === 'up' ? 'online' : 'unreachable'}
                                        label={iface?.status ?? 'tidak dilaporkan'}
                                        variant="plain"
                                    />
                                </span>
                            ))}
                        </div>
                    </div>
                ) : null}
                <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <TrendChart
                        title="Beban CPU & RAM"
                        subtitle={historySubtitle}
                        scaleLabel={`${percentPeak}%`}
                        emptyLabel="Belum ada siklus pindai yang berhasil membaca CPU/RAM dari perangkat ini. Metrik perangkat butuh RouterOS API atau SNMP yang aktif dan kredensial yang valid."
                        lines={[
                            { label: 'CPU', color: '#2563EB', series: cpuSeries },
                            { label: 'RAM', color: '#7C3AED', dashed: true, series: ramSeries },
                        ]}
                    />
                    <TrendChart
                        title="Trafik Antarmuka"
                        subtitle={historySubtitle}
                        scaleLabel={formatMbps(trafficPeak) ?? '—'}
                        emptyLabel="Belum ada siklus pindai yang berhasil membaca counter trafik dari perangkat ini."
                        lines={[
                            { label: 'Rx', color: '#0891B2', series: rxSeries },
                            { label: 'Tx', color: '#F59E0B', dashed: true, series: txSeries },
                        ]}
                    />
                </div>
                <div className="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div className="border-b border-brand-border px-5 py-4">
                        <h3 className="text-sm font-semibold text-brand-text">Riwayat Pemindaian</h3>
                        <p className="mt-0.5 text-xs text-brand-textMuted">
                            Terbaru di atas. Sel "—" berarti nilai itu memang tidak terukur pada siklus
                            tersebut; nilai 0 yang tampil adalah hasil pengukuran sungguhan.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-brand-border text-sm">
                            <thead className="bg-brand-surface">
                                <tr className="text-left text-[11px] font-semibold uppercase tracking-wide text-brand-textMuted">
                                    <th scope="col" className="px-5 py-3">Waktu</th>
                                    <th scope="col" className="px-5 py-3">Status</th>
                                    <th scope="col" className="px-5 py-3">Latensi</th>
                                    <th scope="col" className="px-5 py-3">Loss</th>
                                    <th scope="col" className="px-5 py-3">CPU</th>
                                    <th scope="col" className="px-5 py-3">RAM</th>
                                    <th scope="col" className="px-5 py-3">Uptime</th>
                                    <th scope="col" className="px-5 py-3">Rx</th>
                                    <th scope="col" className="px-5 py-3">Tx</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-brand-border">
                                {newestFirst.length === 0 ? (
                                    <tr>
                                        <td colSpan={9} className="px-5 py-10 text-center text-xs text-brand-textMuted">
                                            Belum ada riwayat pemindaian yang tersimpan untuk perangkat ini.
                                        </td>
                                    </tr>
                                ) : (
                                    newestFirst.map((log) => (
                                        <tr key={log.id} className="text-brand-text">
                                            <td className="whitespace-nowrap px-5 py-3 text-xs text-brand-textMuted">
                                                {formatDateTime(log.checked_at) ?? <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                <StatusBadge status={log.status} variant="plain" />
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {hasValue(log.ping_latency_ms) ? `${log.ping_latency_ms} ms` : <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {hasValue(log.packet_loss_percent) ? `${log.packet_loss_percent}%` : <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {hasValue(log.cpu_usage_percent) ? `${log.cpu_usage_percent}%` : <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {hasValue(log.ram_usage_percent) ? `${log.ram_usage_percent}%` : <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3 text-xs">
                                                {formatUptime(log.uptime_seconds) ?? <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {formatMbps(log.bandwidth_rx_bps) ?? <NoData />}
                                            </td>
                                            <td className="whitespace-nowrap px-5 py-3">
                                                {formatMbps(log.bandwidth_tx_bps) ?? <NoData />}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </CimsLayout>
    );
}

