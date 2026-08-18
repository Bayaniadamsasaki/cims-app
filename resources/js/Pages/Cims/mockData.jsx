import { CHART } from "@/Components/Cims/theme";

/**
 * Mock data dashboard CIMS untuk keperluan tampilan.
 * Ganti dengan props dari Inertia (controller Laravel) saat data nyata siap.
 */

export const METRICS = {
    totalDevices: 248,
    newDevices: 2,
    activeAlerts: 7,
    alertsDelta: -1,
    maintenanceScheduled: 4,
    maintenanceToday: 1,
    onlineDevices: 236,
};

export const TRAFFIC_SERIES = [
    { key: "inbound", label: "Inbound", color: CHART.series.inbound },
    { key: "outbound", label: "Outbound", color: CHART.series.outbound },
];

export const TRAFFIC_DATA = [
    { label: "00:00", inbound: 210, outbound: 120 },
    { label: "02:00", inbound: 150, outbound: 85 },
    { label: "04:00", inbound: 120, outbound: 60 },
    { label: "06:00", inbound: 320, outbound: 180 },
    { label: "08:00", inbound: 760, outbound: 410 },
    { label: "10:00", inbound: 940, outbound: 520 },
    { label: "12:00", inbound: 880, outbound: 470 },
    { label: "14:00", inbound: 1020, outbound: 560 },
    { label: "16:00", inbound: 810, outbound: 430 },
    { label: "18:00", inbound: 520, outbound: 290 },
    { label: "20:00", inbound: 430, outbound: 240 },
    { label: "22:00", inbound: 280, outbound: 160 },
];

export const DEVICES = [
    {
        id: 1,
        name: "CORE-SW-01",
        location: "Data Center",
        ip: "10.10.0.1",
        uptime: "182 hari",
        status: "online",
    },
    {
        id: 2,
        name: "DIST-SW-REKTORAT",
        location: "Gedung Rektorat · Lt. 1",
        ip: "10.10.2.1",
        uptime: "94 hari",
        status: "online",
    },
    {
        id: 3,
        name: "AP-LAB-KOMP-03",
        location: "Lab Komputer · Lt. 3",
        ip: "10.10.7.23",
        uptime: "12 jam",
        status: "warning",
    },
    {
        id: 4,
        name: "DIST-SW-PERPUS",
        location: "Perpustakaan · Lt. 2",
        ip: "10.10.4.1",
        uptime: "—",
        status: "offline",
    },
    {
        id: 5,
        name: "RTR-BORDER-01",
        location: "Data Center",
        ip: "10.10.0.254",
        uptime: "365 hari",
        status: "online",
    },
    {
        id: 6,
        name: "AP-AULA-01",
        location: "Aula Utama · Lt. 1",
        ip: "10.10.9.11",
        uptime: "3 hari",
        status: "maintenance",
    },
];

export const ALERTS = [
    {
        id: 1,
        severity: "offline",
        severityLabel: "Critical",
        message: "Perangkat tidak merespons ICMP selama 14 menit",
        device: "DIST-SW-PERPUS",
        at: "2026-08-18T09:12:00+08:00",
        ago: "14 menit lalu",
    },
    {
        id: 2,
        severity: "warning",
        severityLabel: "Warning",
        message: "Utilisasi CPU 87% melewati ambang batas",
        device: "AP-LAB-KOMP-03",
        at: "2026-08-18T08:40:00+08:00",
        ago: "46 menit lalu",
    },
    {
        id: 3,
        severity: "warning",
        severityLabel: "Warning",
        message: "Packet loss 4.2% pada uplink distribusi",
        device: "DIST-SW-REKTORAT",
        at: "2026-08-18T07:55:00+08:00",
        ago: "1 jam lalu",
    },
    {
        id: 4,
        severity: "maintenance",
        severityLabel: "Maintenance",
        message: "Jadwal upgrade firmware dimulai 20:00 WITA",
        device: "AP-AULA-01",
        at: "2026-08-18T06:30:00+08:00",
        ago: "3 jam lalu",
    },
    {
        id: 5,
        severity: "online",
        severityLabel: "Resolved",
        message: "Link redundansi kembali normal setelah failover",
        device: "CORE-SW-01",
        at: "2026-08-18T05:05:00+08:00",
        ago: "4 jam lalu",
    },
];
