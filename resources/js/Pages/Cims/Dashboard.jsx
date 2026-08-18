import { Head, Link, usePage } from "@inertiajs/react";
import CimsLayout from "@/Layouts/CimsLayout";
import MetricCard from "@/Components/Cims/MetricCard";
import TrafficBarChart from "@/Components/Cims/TrafficBarChart";
import DeviceStatusList from "@/Components/Cims/DeviceStatusList";
import RecentAlertsList from "@/Components/Cims/RecentAlertsList";
import { IconAlerts, IconInventory, IconMaintenance } from "@/Components/Cims/icons";
import { ALERTS, DEVICES, METRICS, TRAFFIC_DATA, TRAFFIC_SERIES } from "./mockData";

const VIEW_ALL =
    "text-xs font-semibold text-blue-600 transition hover:text-blue-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-2 rounded";

/**
 * Halaman Dashboard CIMS (tema terang, primer biru).
 * Setiap props opsional; bila belum dikirim dari controller, mock data dipakai
 * sebagai fallback sehingga tampilan tetap utuh.
 */
export default function Dashboard({ metrics, traffic, devices, alerts }) {
    const user = usePage().props.auth?.user;
    const stats = { ...METRICS, ...(metrics ?? {}) };
    const trafficData = traffic ?? TRAFFIC_DATA;
    const deviceRows = devices ?? DEVICES;
    const alertRows = alerts ?? ALERTS;

    return (
        <CimsLayout unreadAlerts={stats.activeAlerts}>
            <Head title="Dashboard" />

            {/* Header halaman (§5D) */}
            <div className="mb-6">
                <h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                    Welcome back, {user?.name ?? "Network Admin"}
                </h1>
                <p className="mt-1.5 text-sm text-slate-500">
                    {stats.onlineDevices} dari {stats.totalDevices} perangkat online ·{" "}
                    {stats.activeAlerts} alert aktif menunggu tindakan hari ini.
                </p>
            </div>

            {/* 3 Metric Cards (§6A) */}
            <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <MetricCard
                    title="Total Devices"
                    value={stats.totalDevices}
                    icon={IconInventory}
                    tint="blue"
                    trend={{ tone: "positive", label: `+${stats.newDevices} New` }}
                    caption="terdaftar di inventaris"
                />
                <MetricCard
                    title="Active Alerts"
                    value={stats.activeAlerts}
                    icon={IconAlerts}
                    tint="red"
                    trend={{
                        tone: stats.alertsDelta <= 0 ? "positive" : "negative",
                        label: `${stats.alertsDelta > 0 ? "+" : ""}${stats.alertsDelta} vs kemarin`,
                    }}
                    caption="belum terselesaikan"
                />
                <MetricCard
                    title="Maintenance Scheduled"
                    value={stats.maintenanceScheduled}
                    icon={IconMaintenance}
                    tint="amber"
                    trend={{ tone: "neutral", label: `${stats.maintenanceToday} hari ini` }}
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
                        data={trafficData}
                        series={TRAFFIC_SERIES}
                    />
                </div>

                <RecentAlertsList
                    alerts={alertRows}
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
                    devices={deviceRows}
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
