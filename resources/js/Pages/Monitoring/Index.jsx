import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ devices = [], summary = {}, alerts = [], latestSpeedtest = null }) {
    const [scanning, setScanning] = useState(false);
    const [testingSpeed, setTestingSpeed] = useState(false);

    const handleScanNow = () => {
        setScanning(true);
        router.post(route('monitoring.scan'), {}, {
            onFinish: () => setScanning(false)
        });
    };

    const handleRunSpeedtest = () => {
        setTestingSpeed(true);
        router.post(route('monitoring.speedtest'), {}, {
            onFinish: () => setTestingSpeed(false)
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Live Infrastructure Monitoring
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Real-time ICMP/SNMP status, packet latency, CPU load, and hardware health metrics.
                        </p>
                    </div>
                    <button
                        onClick={handleScanNow}
                        disabled={scanning}
                        className={`inline-flex items-center rounded-xl px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md transition duration-150 ${
                            scanning 
                                ? 'bg-brand-primary/50 cursor-not-allowed' 
                                : 'bg-brand-primary hover:bg-brand-primaryHover'
                        }`}
                    >
                        <svg className={`h-5 w-5 mr-2 ${scanning ? 'animate-spin' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 1121.21 7.89H18" />
                        </svg>
                        {scanning ? 'Scanning Nodes...' : 'Scan Now'}
                    </button>
                </div>
            }
        >
            <Head title="Live Monitoring" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Summary Counters */}
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-3 mb-8">
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="text-xs font-semibold text-brand-textSecondary uppercase tracking-wider">Online Availability</div>
                            <div className="flex items-baseline mt-2">
                                <span className="text-4xl font-extrabold text-brand-primary">{summary.onlinePercent}%</span>
                                <span className="text-xs text-brand-textMuted ml-2">Uptime Index</span>
                            </div>
                            <div className="w-full bg-brand-bgSecondary rounded-full h-1.5 mt-4 overflow-hidden">
                                <div className="bg-brand-primary h-1.5 rounded-full" style={{ width: `${summary.onlinePercent}%` }}></div>
                            </div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="text-xs font-semibold text-brand-textSecondary uppercase tracking-wider">Active Device Nodes</div>
                            <div className="flex items-baseline mt-2">
                                <span className="text-4xl font-extrabold text-white">{summary.online}</span>
                                <span className="text-xs text-brand-textSecondary ml-2">/ {summary.total} Live</span>
                            </div>
                            <div className="flex items-center space-x-1.5 text-xs text-emerald-450 mt-4 font-semibold">
                                <span className="h-2 w-2 rounded-full bg-brand-primary animate-ping"></span>
                                <span>No packet loss warnings</span>
                            </div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="text-xs font-semibold text-brand-textSecondary uppercase tracking-wider">Offline Nodes</div>
                            <div className="flex items-baseline mt-2">
                                <span className={`text-4xl font-extrabold ${summary.offline > 0 ? 'text-rose-450' : 'text-brand-textMuted'}`}>{summary.offline}</span>
                                <span className="text-xs text-brand-textSecondary ml-2">requiring inspection</span>
                            </div>
                            <div className="text-xs text-brand-textMuted mt-4">
                                {summary.offline > 0 ? 'Urgent attention required' : 'All systems operating within normal parameters'}
                            </div>
                        </div>
                    </div>

                    {/* Main Layout: Devices Table (2 cols) & Right Control Widgets (1 col) */}
                    <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
                        
                        {/* Device Monitoring Table (Left 2 Columns) */}
                        <div className="lg:col-span-2 rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <h3 className="text-lg font-bold text-white mb-6">Device Node Telemetry</h3>
                            
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-brand-border text-left">
                                    <thead>
                                        <tr>
                                            <th className="py-3 text-xs font-bold text-brand-textSecondary">Device</th>
                                            <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">Status</th>
                                            <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">Latency</th>
                                            <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">CPU Load</th>
                                            <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">RAM Usage</th>
                                            <th className="py-3 text-right text-xs font-bold text-brand-textSecondary">Logs</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border/60">
                                        {devices.map((device) => {
                                            const m = device.metrics;
                                            const isOnline = device.status === 'active';
                                            const latency = m?.last_ping_latency_ms;
                                            const cpu = m?.last_cpu_usage_percent;
                                            const ram = m?.last_ram_usage_percent;

                                            return (
                                                <tr key={device.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                    <td className="whitespace-nowrap py-4 pr-3 text-sm">
                                                        <div className="font-bold text-white">{device.name}</div>
                                                        <div className="text-xs text-brand-textSecondary font-mono mt-0.5">{device.ip_address}</div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        <span className="flex items-center space-x-2">
                                                            <span className={`h-2.5 w-2.5 rounded-full ${
                                                                device.status === 'active' 
                                                                    ? 'bg-brand-primary animate-pulse' 
                                                                    : device.status === 'maintenance' 
                                                                    ? 'bg-amber-500' 
                                                                    : 'bg-rose-500'
                                                            }`}></span>
                                                            <span className="text-xs font-bold text-brand-textSecondary">
                                                                {device.status === 'active' ? 'Online' : device.status === 'maintenance' ? 'Maintenance' : 'Offline'}
                                                            </span>
                                                        </span>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary font-mono">
                                                        {isOnline && latency !== null ? `${latency} ms` : '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        {isOnline && cpu !== null ? (
                                                            <div className="flex items-center space-x-2">
                                                                <span className={`text-xs font-semibold font-mono ${
                                                                    cpu > 80 ? 'text-rose-450' : cpu > 50 ? 'text-amber-400' : 'text-emerald-450'
                                                                }`}>{cpu}%</span>
                                                                <div className="w-12 bg-brand-bgSecondary rounded-full h-1 overflow-hidden">
                                                                    <div className={`h-1 rounded-full ${
                                                                        cpu > 80 ? 'bg-rose-500' : cpu > 50 ? 'bg-amber-500' : 'bg-brand-primary'
                                                                    }`} style={{ width: `${cpu}%` }}></div>
                                                                </div>
                                                            </div>
                                                        ) : '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        {isOnline && ram !== null ? (
                                                            <div className="flex items-center space-x-2">
                                                                <span className="text-xs font-semibold font-mono text-brand-textSecondary">{ram}%</span>
                                                                <div className="w-12 bg-brand-bgSecondary rounded-full h-1 overflow-hidden">
                                                                    <div className="bg-brand-primary h-1 rounded-full" style={{ width: `${ram}%` }}></div>
                                                                </div>
                                                            </div>
                                                        ) : '-'}
                                                    </td>
                                                    <td className="whitespace-nowrap py-4 text-right text-sm">
                                                        <button
                                                            onClick={() => router.get(route('monitoring.show', device.id))}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1 text-xs font-bold transition"
                                                        >
                                                            Graphs
                                                        </button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Right Sidebar Control Widgets */}
                        <div className="space-y-8">
                            
                            {/* Gateway Internet Speedtest Widget */}
                            <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                                <div className="flex items-center justify-between mb-4">
                                    <div>
                                        <h3 className="text-sm font-bold text-white">ISP Gateway Bandwidth</h3>
                                        <p className="text-[10px] text-brand-textSecondary">Measure actual campus gateway speeds.</p>
                                    </div>
                                    <button
                                        onClick={handleRunSpeedtest}
                                        disabled={testingSpeed}
                                        className={`rounded-lg px-2.5 py-1 text-xs font-bold text-slate-950 transition duration-150 ${
                                            testingSpeed
                                                ? 'bg-brand-primary/40 cursor-not-allowed'
                                                : 'bg-brand-primary hover:bg-brand-primaryHover'
                                        }`}
                                    >
                                        {testingSpeed ? 'Testing...' : 'Test Speed'}
                                    </button>
                                </div>

                                {testingSpeed ? (
                                    <div className="py-8 flex flex-col items-center justify-center text-center space-y-3">
                                        <div className="h-10 w-10 rounded-full border-4 border-brand-primary/20 border-t-brand-primary animate-spin"></div>
                                        <div className="text-xs font-semibold text-white animate-pulse">Running Speedtest...</div>
                                        <div className="text-[9px] text-brand-textMuted">Measuring download and upload throughput.</div>
                                    </div>
                                ) : latestSpeedtest ? (
                                    <div className="space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="bg-brand-bgSecondary/60 border border-brand-border rounded-xl p-3 text-center">
                                                <div className="text-[10px] text-brand-textSecondary uppercase">Download</div>
                                                <div className="text-xl font-black text-brand-primary mt-1 font-mono">
                                                    {latestSpeedtest.download_speed_mbps} <span className="text-[10px] font-normal text-white">Mbps</span>
                                                </div>
                                            </div>
                                            <div className="bg-brand-bgSecondary/60 border border-brand-border rounded-xl p-3 text-center">
                                                <div className="text-[10px] text-brand-textSecondary uppercase">Upload</div>
                                                <div className="text-xl font-black text-white mt-1 font-mono">
                                                    {latestSpeedtest.upload_speed_mbps} <span className="text-[10px] font-normal text-brand-textSecondary">Mbps</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div className="flex justify-between items-center text-[10px] text-brand-textSecondary border-t border-brand-border/40 pt-3">
                                            <span>Ping: <strong className="text-white font-mono">{latestSpeedtest.ping_ms} ms</strong></span>
                                            <span className="truncate max-w-[120px]" title={latestSpeedtest.isp}>ISP: <strong className="text-white">{latestSpeedtest.isp}</strong></span>
                                        </div>
                                    </div>
                                ) : (
                                    <div className="py-6 text-center bg-brand-bgSecondary/30 border border-brand-border/40 rounded-xl">
                                        <p className="text-xs text-brand-textSecondary">No bandwidth test run yet today.</p>
                                        <button
                                            onClick={handleRunSpeedtest}
                                            className="text-xs font-semibold text-brand-primary mt-2 hover:underline"
                                        >
                                            Run Initial Test →
                                        </button>
                                    </div>
                                )}
                            </div>

                            {/* Live Alert Console System Logs */}
                            <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg flex flex-col">
                                <h3 className="text-sm font-bold text-white mb-1">Live Alert Console</h3>
                                <p className="text-[10px] text-brand-textSecondary mb-4">Critical system notifications and triggers.</p>
                                
                                <div className="space-y-3 overflow-y-auto max-h-[300px] pr-1">
                                    {alerts.length > 0 ? (
                                        alerts.map((alert, idx) => (
                                            <div key={idx} className={`p-3 rounded-xl border flex flex-col ${
                                                alert.type === 'critical' 
                                                    ? 'bg-rose-950/20 border-rose-500/30' 
                                                    : 'bg-amber-950/20 border-amber-500/30'
                                            }`}>
                                                <div className="flex items-center justify-between">
                                                    <span className={`text-[9px] uppercase font-bold tracking-wider ${
                                                        alert.type === 'critical' ? 'text-rose-450' : 'text-amber-400'
                                                    }`}>
                                                        {alert.type}
                                                    </span>
                                                    <span className="text-[9px] text-brand-textMuted">{alert.timestamp}</span>
                                                </div>
                                                <div className="text-xs font-bold text-white mt-1">{alert.device_name}</div>
                                                <div className="text-xs text-brand-textSecondary mt-0.5">{alert.message}</div>
                                            </div>
                                        ))
                                    ) : (
                                        <div className="flex flex-col items-center justify-center py-6 text-center">
                                            <div className="h-6 w-6 rounded-full bg-brand-primary/10 border border-brand-primary/25 flex items-center justify-center text-brand-primary mb-2 text-xs">
                                                ✓
                                            </div>
                                            <div className="text-xs font-bold text-white">All systems normal</div>
                                            <div className="text-[10px] text-brand-textSecondary mt-0.5">No critical alerts detected.</div>
                                        </div>
                                    )}
                                </div>
                            </div>

                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
