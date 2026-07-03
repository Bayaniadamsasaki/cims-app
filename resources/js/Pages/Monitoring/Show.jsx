import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

export default function Show({ device = {}, historyLogs = [] }) {
    const isOnline = device.status === 'active';
    const m = device.metrics || {};

    // Helper to generate SVG points for charts
    const getSvgPoints = (dataKey, maxVal = 100) => {
        if (historyLogs.length === 0) return '';
        const width = 500;
        const height = 150;
        const padding = 20;

        return historyLogs.map((log, index) => {
            const val = log[dataKey] || 0;
            const x = padding + (index / (historyLogs.length - 1)) * (width - padding * 2);
            // Invert Y axis for SVG (0 is top, height is bottom)
            const y = height - padding - (val / maxVal) * (height - padding * 2);
            return `${x},${y}`;
        }).join(' ');
    };

    // Latency points (max range: 100ms)
    const latencyPoints = getSvgPoints('ping_latency_ms', 100);
    // CPU points (max range: 100%)
    const cpuPoints = getSvgPoints('cpu_usage_percent', 100);
    // RAM points (max range: 100%)
    const ramPoints = getSvgPoints('ram_usage_percent', 100);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-4">
                        <Link
                            href={route('monitoring.index')}
                            className="p-2 rounded-xl bg-brand-bgSecondary border border-brand-border text-brand-textSecondary hover:text-white hover:bg-brand-card transition"
                        >
                            ← Back
                        </Link>
                        <div>
                            <h2 className="text-2xl font-bold tracking-tight text-white">
                                Telemetry Details: {device.name}
                            </h2>
                            <p className="text-sm text-brand-textSecondary">
                                Physical host node historical charts and SNMP metrics.
                            </p>
                        </div>
                    </div>
                    <div>
                        <span className={`inline-flex items-center rounded-md px-3 py-1.5 text-xs font-bold border ${
                            device.status === 'active' 
                                ? 'bg-emerald-500/10 text-emerald-450 border-emerald-500/20' 
                                : 'bg-rose-500/10 text-rose-450 border-rose-500/20'
                        }`}>
                            Status: {device.status === 'active' ? 'ONLINE' : 'OFFLINE'}
                        </span>
                    </div>
                </div>
            }
        >
            <Head title={`Telemetry - ${device.name}`} />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Top Device Specs Grid */}
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary font-bold">Network Address</div>
                            <div className="text-lg font-bold font-mono text-white mt-1">{device.ip_address || '-'}</div>
                            <div className="text-xs text-brand-textMuted mt-1">MAC: {device.mac_address || '-'}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary font-bold">Hardware Model</div>
                            <div className="text-lg font-bold text-white mt-1 truncate">{device.model || '-'}</div>
                            <div className="text-xs text-brand-textMuted mt-1">SN: {device.serial_number || '-'}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary font-bold">Location Zone</div>
                            <div className="text-lg font-bold text-white mt-1">{device.building?.name || '-'}</div>
                            <div className="text-xs text-brand-textMuted mt-1">Rack Node: {device.rack?.name || '-'}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary font-bold">System Uptime</div>
                            <div className="text-lg font-bold text-brand-primary mt-1">
                                {m.last_uptime_seconds 
                                    ? `${Math.floor(m.last_uptime_seconds / 3600)}h ${Math.floor((m.last_uptime_seconds % 3600) / 60)}m` 
                                    : '-'}
                            </div>
                            <div className="text-xs text-brand-textMuted mt-1">Last scan: {m.last_checked_at ? new Date(m.last_checked_at).toLocaleTimeString() : '-'}</div>
                        </div>
                    </div>

                    {/* Telemetry Historical Graphs */}
                    <div className="grid grid-cols-1 gap-8 lg:grid-cols-2 mb-8">
                        
                        {/* Ping Latency Trend */}
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <h3 className="text-sm font-bold text-white mb-4">ICMP Latency Trend (24 Hours)</h3>
                            {historyLogs.length > 0 && latencyPoints ? (
                                <div className="w-full h-40 bg-brand-bgSecondary/30 rounded-xl border border-brand-border/40 p-2 flex items-center justify-center">
                                    <svg className="w-full h-full" viewBox="0 0 500 150" preserveAspectRatio="none">
                                        <polyline
                                            fill="none"
                                            stroke="#22C55E"
                                            strokeWidth="2.5"
                                            points={latencyPoints}
                                        />
                                    </svg>
                                </div>
                            ) : (
                                <div className="h-40 bg-brand-bgSecondary/30 rounded-xl border border-brand-border/40 flex items-center justify-center text-xs text-brand-textSecondary">
                                    No latency log data recorded yet.
                                </div>
                            )}
                            <div className="flex justify-between text-[10px] text-brand-textMuted mt-2">
                                <span>24 hours ago</span>
                                <span>Current</span>
                            </div>
                        </div>

                        {/* CPU & RAM Utilization Trend */}
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <h3 className="text-sm font-bold text-white mb-4">CPU & RAM load Trend (24 Hours)</h3>
                            {historyLogs.length > 0 && cpuPoints ? (
                                <div className="w-full h-40 bg-brand-bgSecondary/30 rounded-xl border border-brand-border/40 p-2 flex items-center justify-center">
                                    <svg className="w-full h-full" viewBox="0 0 500 150" preserveAspectRatio="none">
                                        {/* RAM line (cyan-ish or white) */}
                                        <polyline
                                            fill="none"
                                            stroke="#A1A1AA"
                                            strokeWidth="1.5"
                                            strokeDasharray="4"
                                            points={ramPoints}
                                        />
                                        {/* CPU line (Neon Green) */}
                                        <polyline
                                            fill="none"
                                            stroke="#22C55E"
                                            strokeWidth="2.5"
                                            points={cpuPoints}
                                        />
                                    </svg>
                                </div>
                            ) : (
                                <div className="h-40 bg-brand-bgSecondary/30 rounded-xl border border-brand-border/40 flex items-center justify-center text-xs text-brand-textSecondary">
                                    No SNMP log data recorded yet.
                                </div>
                            )}
                            <div className="flex justify-between text-[10px] text-brand-textMuted mt-2">
                                <span className="flex items-center space-x-1.5">
                                    <span className="h-1.5 w-1.5 rounded-full bg-brand-primary"></span>
                                    <span>CPU Load</span>
                                </span>
                                <span className="flex items-center space-x-1.5">
                                    <span className="h-1.5 w-1.5 rounded-full bg-brand-textSecondary"></span>
                                    <span>RAM Memory</span>
                                </span>
                            </div>
                        </div>

                    </div>

                    {/* Historical Log Points Table */}
                    <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                        <h3 className="text-lg font-bold text-white mb-6">Historical Log Points</h3>
                        
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-3 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Time</th>
                                        <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">Latency</th>
                                        <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">CPU usage</th>
                                        <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">RAM usage</th>
                                        <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">Uptime</th>
                                        <th className="px-3 py-3 text-xs font-bold text-brand-textSecondary">Rx/Tx Bandwidth</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {historyLogs.length > 0 ? (
                                        historyLogs.slice().reverse().map((log, idx) => (
                                            <tr key={idx} className="hover:bg-brand-bgSecondary/30 transition text-sm">
                                                <td className="whitespace-nowrap py-3 pl-6 pr-3 text-brand-textSecondary">
                                                    {new Date(log.checked_at).toLocaleString()}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 font-mono text-white">
                                                    {log.ping_latency_ms ? `${log.ping_latency_ms} ms` : '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 font-semibold text-brand-primary">
                                                    {log.cpu_usage_percent ? `${log.cpu_usage_percent}%` : '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 text-brand-textSecondary">
                                                    {log.ram_usage_percent ? `${log.ram_usage_percent}%` : '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 text-brand-textSecondary">
                                                    {log.uptime_seconds 
                                                        ? `${Math.floor(log.uptime_seconds / 3600)}h` 
                                                        : '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-3 text-brand-textSecondary font-mono text-xs">
                                                    {log.bandwidth_rx_bps 
                                                        ? `${(log.bandwidth_rx_bps / 1000000).toFixed(1)}M / ${(log.bandwidth_tx_bps / 1000000).toFixed(1)}M` 
                                                        : '-'}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="text-center py-6 text-brand-textSecondary">
                                                No history logs available for this device.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
