import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ buildings = [] }) {
    const [reportType, setReportType] = useState('inventory');
    const [selectedBuilding, setSelectedBuilding] = useState('');
    const [selectedStatus, setSelectedStatus] = useState('');

    const handleExportExcel = () => {
        const queryParams = new URLSearchParams({
            type: reportType,
            building_id: selectedBuilding,
            status: selectedStatus,
        }).toString();
        
        window.location.href = `/reports/excel?${queryParams}`;
    };

    const handleExportPdf = () => {
        const queryParams = new URLSearchParams({
            type: reportType,
            building_id: selectedBuilding,
            status: selectedStatus,
        }).toString();
        
        window.open(`/reports/pdf?${queryParams}`, '_blank');
    };

    // Get statuses available for dropdown based on report type selection
    const getStatusOptions = () => {
        if (reportType === 'inventory') {
            return [
                { value: 'active', label: 'Active / Online' },
                { value: 'offline', label: 'Offline' },
                { value: 'maintenance', label: 'Maintenance' },
            ];
        } else if (reportType === 'monitoring') {
            return [
                { value: 'online', label: 'Online Status' },
                { value: 'offline', label: 'Offline Status' },
            ];
        } else if (reportType === 'maintenance') {
            return [
                { value: 'pending', label: 'Pending Approval' },
                { value: 'in_progress', label: 'In Progress' },
                { value: 'completed', label: 'Completed' },
            ];
        }
        return [];
    };

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="text-2xl font-bold tracking-tight text-white">
                        Reports & Export Hub
                    </h2>
                    <p className="text-sm text-brand-textSecondary">
                        Configure query filters and generate high-fidelity PDF documents or standard Excel sheets.
                    </p>
                </div>
            }
        >
            <Head title="Reports & Export" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8 pt-8">

                    <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
                        
                        {/* Configuration Form (Left 2 columns) */}
                        <div className="md:col-span-2 rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg space-y-6">
                            <h3 className="text-base font-bold text-white border-b border-brand-border/40 pb-3">
                                1. Select Filter Configurations
                            </h3>

                            <div className="space-y-4">
                                {/* Report Type */}
                                <div>
                                    <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1.5">
                                        Report Data Type
                                    </label>
                                    <div className="grid grid-cols-3 gap-3">
                                        {[
                                            { id: 'inventory', label: 'Device Inventory', desc: 'Active IT asset list' },
                                            { id: 'monitoring', label: 'Telemetry Logs', desc: 'System uptime metrics' },
                                            { id: 'maintenance', label: 'Maintenance Log', desc: 'Repair histories' },
                                        ].map((t) => (
                                            <button
                                                key={t.id}
                                                type="button"
                                                onClick={() => {
                                                    setReportType(t.id);
                                                    setSelectedStatus('');
                                                }}
                                                className={`p-4 rounded-xl border text-left flex flex-col justify-between transition ${
                                                    reportType === t.id
                                                        ? 'bg-brand-primary/10 border-brand-primary text-brand-primary'
                                                        : 'bg-brand-bgSecondary/40 border-brand-border text-brand-textSecondary hover:text-white hover:border-brand-textMuted'
                                                }`}
                                            >
                                                <span className="text-xs font-bold block">{t.label}</span>
                                                <span className="text-[9px] text-brand-textMuted mt-1 block">{t.desc}</span>
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {/* Building Filter (only relevant for inventory) */}
                                {reportType === 'inventory' && (
                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1.5">
                                            Filter by Location (Building)
                                        </label>
                                        <select
                                            value={selectedBuilding}
                                            onChange={(e) => setSelectedBuilding(e.target.value)}
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2.5 px-3 focus:outline-none focus:border-brand-primary text-sm"
                                        >
                                            <option value="">All Buildings / Locations</option>
                                            {buildings.map((b) => (
                                                <option key={b.id} value={b.id}>{b.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                )}

                                {/* Status Filter */}
                                <div>
                                    <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1.5">
                                        Filter by Status
                                    </label>
                                    <select
                                        value={selectedStatus}
                                        onChange={(e) => setSelectedStatus(e.target.value)}
                                        className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2.5 px-3 focus:outline-none focus:border-brand-primary text-sm"
                                    >
                                        <option value="">All Status Levels</option>
                                        {getStatusOptions().map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                        </div>

                        {/* Export Action Card (Right 1 column) */}
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-6 shadow-lg flex flex-col justify-between">
                            <div>
                                <h3 className="text-sm font-bold text-white border-b border-brand-border/40 pb-3 mb-4">
                                    2. Action Outputs
                                </h3>
                                <p className="text-xs text-brand-textSecondary leading-relaxed mb-6">
                                    Your configured filter will output matching campus network node records to your chosen output device or software application.
                                </p>
                            </div>

                            <div className="space-y-3">
                                <button
                                    onClick={handleExportExcel}
                                    className="w-full inline-flex items-center justify-center rounded-xl bg-brand-primary hover:bg-brand-primaryHover text-slate-950 py-3 text-xs font-bold shadow-md transition duration-150"
                                >
                                    <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    Export Excel (CSV)
                                </button>
                                <button
                                    onClick={handleExportPdf}
                                    className="w-full inline-flex items-center justify-center rounded-xl bg-brand-bgSecondary border border-brand-border hover:border-brand-primary hover:text-brand-primary py-3 text-xs font-bold transition duration-150"
                                >
                                    <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                                    </svg>
                                    Export PDF / Print
                                </button>
                            </div>
                        </div>

                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
