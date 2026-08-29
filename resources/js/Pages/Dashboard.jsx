import { Head, Link, usePage } from "@inertiajs/react";
import CimsLayout from "@/Layouts/CimsLayout";
import MetricCard from "@/Components/Cims/MetricCard";
import TrafficBarChart from "@/Components/Cims/TrafficBarChart";
import DeviceStatusList from "@/Components/Cims/DeviceStatusList";
import RecentAlertsList from "@/Components/Cims/RecentAlertsList";
import { IconAlerts, IconInventory, IconMaintenance } from "@/Components/Cims/icons";
import { TRAFFIC_SERIES } from "@/Components/Cims/theme";

const VIEW_ALL =
    "text-xs font-semibold text-blue-600 transition hover:text-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 rounded";

/**
 * Dashboard CIMS — struktur dan warna mengikuti Docs/design_cims_dashboard.md.
 *
 * Seluruh angka berasal dari DashboardWebController, yaitu hasil monitoring yang
 * benar-benar tersimpan. Tidak ada mode demo dan tidak ada data contoh: bila
 * belum ada perangkat yang dipindai, widget menampilkan empty state.
 */
export default function Dashboard({ metrics = {}, traffic = [], devices = [], alerts = [] }) {
    const user = usePage().props.auth?.user;
    const stats = metrics;

    const newDevices = stats.newDevices ?? 0;
    const criticalAlerts = stats.criticalAlerts ?? 0;
    const totalDevices = stats.totalDevices ?? 0;
    const unknownDevices = stats.unknownDevices ?? 0;
    const attention =
        (stats.degradedDevices ?? 0) + (stats.unreachableDevices ?? 0) + (stats.monitoringErrorDevices ?? 0);

    return (
        <CimsLayout unreadAlerts={stats.activeAlerts ?? 0}>
            <Head title="Dashboard" />

            {/* Header halaman (§5D) */}
            <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                        Welcome back, {user?.name ?? "Network Admin"}
                    </h1>
                    <p className="mt-1.5 text-sm text-slate-500">
                        {stats.onlineDevices ?? 0} dari {totalDevices} perangkat terkonfirmasi online ·{" "}
                        {stats.activeAlerts ?? 0} alert aktif menunggu tindakan hari ini.
                    </p>
                    {unknownDevices > 0 && (
                        <p className="mt-1 text-xs text-slate-500">
                            {unknownDevices} perangkat belum pernah dipindai — statusnya belum diketahui.{" "}
                            <span className="text-slate-400">Jalankan health scan pada halaman Monitoring.</span>
                        </p>
                    )}
                </div>
            </div>

            {/* 3 Metric Cards (§6A) */}
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <MetricCard
                    title="Total Devices"
                    value={totalDevices}
                    icon={IconInventory}
                    tint="blue"
                    trend={
                        newDevices > 0
                            ? { tone: "positive", label: `+${newDevices} minggu ini` }
                            : { tone: "neutral", label: "Belum ada tambahan" }
                    }
                    caption="terdaftar di inventaris"
                />
                <MetricCard
                    title="Active Alerts"
                    value={stats.activeAlerts ?? 0}
                    icon={IconAlerts}
                    tint="red"
                    trend={
                        criticalAlerts > 0
                            ? { tone: "negative", label: `${criticalAlerts} critical` }
                            : { tone: "neutral", label: "Tidak ada critical" }
                    }
                    caption={attention > 0 ? `${attention} perangkat perlu perhatian` : "belum terselesaikan"}
                />
                <MetricCard
                    title="Maintenance Scheduled"
                    value={stats.maintenanceScheduled ?? 0}
                    icon={IconMaintenance}
                    tint="amber"
                    trend={{ tone: "neutral", label: `${stats.maintenanceToday ?? 0} hari ini` }}
                    caption="tiket terjadwal"
                />
            </div>

            {/* Chart utama + Recent Alerts (§6B, §6C) */}
            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                {/* min-w-0 wajib: chart punya area scroll horizontal sendiri, tanpa ini
                    lebar minimumnya ikut melebarkan grid dan memotong konten di mobile. */}
                <div className="min-w-0 lg:col-span-2">
                    <TrafficBarChart
                        title="Network Traffic Overview"
                        subtitle="Rata-rata throughput uplink kampus, interval 2 jam"
                        unit="Mbps"
                        data={traffic}
                        series={TRAFFIC_SERIES}
                    />
                </div>

                <RecentAlertsList
                    alerts={alerts}
                    action={
                        <Link href={route("alerts.index")} className={VIEW_ALL}>
                            Lihat semua →
                        </Link>
                    }
                />
            </div>

            {/* Device Status (§6C) */}
            <div className="mt-6">
                <DeviceStatusList
                    devices={devices}
                    action={
                        <Link href={route("devices.index")} className={VIEW_ALL}>
                            Lihat inventaris →
                        </Link>
                    }
                />
            </div>
        </CimsLayout>
    );
}
