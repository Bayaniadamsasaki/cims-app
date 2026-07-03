import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Dashboard({ stats, recentDevices, categories, buildings }) {
    const {
        totalDevices = 0,
        totalBuildings = 0,
        activeDevices = 0,
        offlineDevices = 0,
        maintenanceDevices = 0
    } = stats || {};

    const activePercent = totalDevices > 0 ? Math.round((activeDevices / totalDevices) * 100) : 0;
    const offlinePercent = totalDevices > 0 ? Math.round((offlineDevices / totalDevices) * 100) : 0;
    const maintenancePercent = totalDevices > 0 ? Math.round((maintenanceDevices / totalDevices) * 100) : 0;

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Infrastructure Dashboard
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Real-time status overview of Campus IT infrastructure.
                        </p>
                    </div>
                    <div className="hidden md:flex space-x-2">
                        <span className="inline-flex items-center rounded-md bg-brand-primary/10 px-3 py-1 text-xs font-semibold text-brand-primary ring-1 ring-inset ring-brand-primary/30 shadow-[0_0_12px_rgba(34,197,94,0.15)]">
                            System Live
                        </span>
                        <span className="inline-flex items-center rounded-md bg-emerald-500/10 px-3 py-1 text-xs font-semibold text-emerald-450 ring-1 ring-inset ring-emerald-500/30">
                            Database Connected
                        </span>
                    </div>
                </div>
            }
        >
            <Head title="Infrastructure Dashboard" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Top Stats Cards */}
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        
                        {/* Total Devices */}
                        <div className="relative overflow-hidden rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-brand-primary/5 blur-xl"></div>
                            <div className="flex items-center">
                                <div className="rounded-xl bg-brand-primary/10 p-3 text-brand-primary border border-brand-primary/20">
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                                    </svg>
                                </div>
                                <div className="ml-5">
                                    <p className="text-sm font-medium text-brand-textSecondary">Total Devices</p>
                                    <h4 className="text-3xl font-extrabold tracking-tight text-white mt-1">{totalDevices}</h4>
                                </div>
                            </div>
                            <div className="mt-4 text-xs text-brand-textMuted">
                                Across {totalBuildings} academic & operations buildings
                            </div>
                        </div>

                        {/* Active Devices */}
                        <div className="relative overflow-hidden rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-brand-primary/5 blur-xl"></div>
                            <div className="flex items-center">
                                <div className="rounded-xl bg-brand-primary/10 p-3 text-brand-primary border border-brand-primary/20">
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div className="ml-5">
                                    <p className="text-sm font-medium text-brand-textSecondary">Active / Online</p>
                                    <h4 className="text-3xl font-extrabold tracking-tight text-white mt-1">{activeDevices}</h4>
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="w-full bg-brand-bgSecondary rounded-full h-1.5 overflow-hidden">
                                    <div className="bg-brand-primary h-1.5 rounded-full" style={{ width: `${activePercent}%` }}></div>
                                </div>
                                <div className="flex justify-between text-[10px] text-brand-textSecondary mt-1">
                                    <span>Operating Status</span>
                                    <span>{activePercent}% of total</span>
                                </div>
                            </div>
                        </div>

                        {/* Maintenance Devices */}
                        <div className="relative overflow-hidden rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-amber-500/5 blur-xl"></div>
                            <div className="flex items-center">
                                <div className="rounded-xl bg-amber-550/10 p-3 text-amber-400 border border-amber-500/20">
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                </div>
                                <div className="ml-5">
                                    <p className="text-sm font-medium text-brand-textSecondary">Under Maintenance</p>
                                    <h4 className="text-3xl font-extrabold tracking-tight text-white mt-1">{maintenanceDevices}</h4>
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="w-full bg-brand-bgSecondary rounded-full h-1.5 overflow-hidden">
                                    <div className="bg-amber-500 h-1.5 rounded-full" style={{ width: `${maintenancePercent}%` }}></div>
                                </div>
                                <div className="flex justify-between text-[10px] text-brand-textSecondary mt-1">
                                    <span>Scheduled/Repair</span>
                                    <span>{maintenancePercent}% of total</span>
                                </div>
                            </div>
                        </div>

                        {/* Offline Devices */}
                        <div className="relative overflow-hidden rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="absolute top-0 right-0 -mt-4 -mr-4 h-24 w-24 rounded-full bg-rose-500/5 blur-xl"></div>
                            <div className="flex items-center">
                                <div className="rounded-xl bg-rose-550/10 p-3 text-rose-450 border border-rose-500/20">
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                                <div className="ml-5">
                                    <p className="text-sm font-medium text-brand-textSecondary">Offline / Down</p>
                                    <h4 className="text-3xl font-extrabold tracking-tight text-white mt-1">{offlineDevices}</h4>
                                </div>
                            </div>
                            <div className="mt-4">
                                <div className="w-full bg-brand-bgSecondary rounded-full h-1.5 overflow-hidden">
                                    <div className="bg-rose-500 h-1.5 rounded-full" style={{ width: `${offlinePercent}%` }}></div>
                                </div>
                                <div className="flex justify-between text-[10px] text-brand-textSecondary mt-1">
                                    <span>Needs Attention</span>
                                    <span>{offlinePercent}% of total</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Middle Section: Recent Devices and Location Breakdowns */}
                    <div className="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-3">
                        
                        {/* Recent Devices List (Left 2 Columns) */}
                        <div className="lg:col-span-2 rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                            <div className="flex items-center justify-between mb-6">
                                <div>
                                    <h3 className="text-lg font-bold text-white">Recent Inventory Additions</h3>
                                    <p className="text-xs text-brand-textSecondary">Recently added physical and network assets.</p>
                                </div>
                                <button className="text-xs font-semibold text-brand-primary hover:underline transition duration-150">
                                    View All Devices →
                                </button>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-brand-border text-left">
                                    <thead>
                                        <tr>
                                            <th className="py-3.5 pl-4 pr-3 text-xs font-bold text-brand-textSecondary sm:pl-0">Device Info</th>
                                            <th className="px-3 py-3.5 text-xs font-bold text-brand-textSecondary">IP & Host</th>
                                            <th className="px-3 py-3.5 text-xs font-bold text-brand-textSecondary">Location</th>
                                            <th className="px-3 py-3.5 text-xs font-bold text-brand-textSecondary">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-brand-border/60">
                                        {recentDevices && recentDevices.length > 0 ? (
                                            recentDevices.map((device) => (
                                                <tr key={device.id} className="hover:bg-brand-bgSecondary/40 transition">
                                                    <td className="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-0">
                                                        <div className="flex items-center">
                                                            <div>
                                                                <div className="font-semibold text-white">{device.name}</div>
                                                                <div className="text-xs text-brand-textSecondary mt-0.5">{device.vendor?.name} • {device.model}</div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                        <div className="font-mono text-xs text-white">{device.ip_address || 'No IP'}</div>
                                                        <div className="text-xs text-brand-textMuted mt-0.5">{device.hostname || 'no-hostname'}</div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                        <div>{device.building?.name || 'Unknown Bldg'}</div>
                                                        <div className="text-xs text-brand-textMuted mt-0.5">
                                                            {device.room?.name || 'No Room'} {device.rack ? `• ${device.rack.name}` : ''}
                                                        </div>
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                        <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold ring-1 ring-inset ${
                                                            device.status === 'active' 
                                                                ? 'bg-emerald-500/10 text-emerald-450 ring-emerald-500/30' 
                                                                : device.status === 'maintenance' 
                                                                ? 'bg-amber-500/10 text-amber-450 ring-amber-500/30' 
                                                                : 'bg-rose-500/10 text-rose-450 ring-rose-500/30'
                                                        }`}>
                                                            {device.status ? device.status.charAt(0).toUpperCase() + device.status.slice(1) : 'Unknown'}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td colSpan="4" className="text-center py-6 text-brand-textSecondary text-sm">
                                                    No devices found. Run php artisan db:seed to populate data.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Right Column: Distributions & Breakdowns */}
                        <div className="space-y-8">
                            
                            {/* Device Categories Distribution */}
                            <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                                <h3 className="text-lg font-bold text-white mb-4">Device Categories</h3>
                                <div className="space-y-4">
                                    {categories && categories.length > 0 ? (
                                        categories.map((category, idx) => {
                                            const categoryPercent = totalDevices > 0 ? Math.round((category.count / totalDevices) * 100) : 0;
                                            const progressColors = ['bg-brand-primary', 'bg-emerald-500', 'bg-purple', 'bg-amber-500', 'bg-rose-500'];
                                            const colorClass = progressColors[idx % progressColors.length];

                                            return (
                                                <div key={idx}>
                                                    <div className="flex justify-between text-sm mb-1">
                                                        <span className="font-semibold text-brand-textSecondary">{category.name}</span>
                                                        <span className="text-white font-bold">{category.count} <span className="text-xs font-normal text-brand-textMuted">({categoryPercent}%)</span></span>
                                                    </div>
                                                    <div className="w-full bg-brand-bgSecondary rounded-full h-2">
                                                        <div className={`${colorClass} h-2 rounded-full`} style={{ width: `${categoryPercent}%` }}></div>
                                                    </div>
                                                </div>
                                            );
                                        })
                                    ) : (
                                        <p className="text-brand-textSecondary text-sm">No categories seeded.</p>
                                    )}
                                </div>
                            </div>

                            {/* Buildings Location Breakdown */}
                            <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg">
                                <h3 className="text-lg font-bold text-white mb-4">Location Breakdowns</h3>
                                <div className="space-y-4">
                                    {buildings && buildings.length > 0 ? (
                                        buildings.map((building, idx) => {
                                            return (
                                                <div key={idx} className="flex items-center justify-between p-3 rounded-xl bg-brand-bgSecondary/60 border border-brand-border hover:border-brand-primary/20 transition">
                                                    <div className="flex items-center space-x-3">
                                                        <div className="bg-brand-primary/10 p-2 rounded-lg text-brand-primary border border-brand-primary/20">
                                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <div className="font-bold text-sm text-white">{building.name}</div>
                                                            <div className="text-xs text-brand-textSecondary">Distribution Node</div>
                                                        </div>
                                                    </div>
                                                    <div className="text-right">
                                                        <span className="inline-flex items-center rounded-full bg-brand-primary/10 px-2.5 py-0.5 text-xs font-bold text-brand-primary border border-brand-primary/20">
                                                            {building.count} Devices
                                                        </span>
                                                    </div>
                                                </div>
                                            );
                                        })
                                    ) : (
                                        <p className="text-brand-textSecondary text-sm">No building locations found.</p>
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
