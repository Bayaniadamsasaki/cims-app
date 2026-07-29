import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function Index({ devices = [], vendors = [], categories = [], buildings = [], floors = [], rooms = [], racks = [], filters = {} }) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [selectedCategory, setSelectedCategory] = useState(filters.device_category_id || '');
    const [selectedBuilding, setSelectedBuilding] = useState(filters.building_id || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || '');

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [editingDevice, setEditingDevice] = useState(null);
    const [viewingDevice, setViewingDevice] = useState(null);
    const { confirmAction } = useConfirmation();

    const importForm = useForm({
        file: null
    });

    const handleImportSubmit = (e) => {
        e.preventDefault();
        if (!importForm.data.file) return;

        importForm.post(route('devices.import'), {
            onSuccess: () => {
                setIsImportModalOpen(false);
                importForm.reset();
            }
        });
    };

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        hostname: '',
        ip_address: '',
        mac_address: '',
        vendor_id: '',
        device_category_id: '',
        model: '',
        serial_number: '',
        firmware: '',
        username: '',
        password: '',
        purchase_date: '',
        warranty: '',
        building_id: '',
        floor_id: '',
        room_id: '',
        rack_id: '',
        status: 'active',
        notes: '',
        image: null
    });

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('devices.index'), {
            search: searchTerm,
            device_category_id: selectedCategory,
            building_id: selectedBuilding,
            status: selectedStatus
        }, { preserveState: true });
    };

    const handleOpenCreateModal = () => {
        setEditingDevice(null);
        reset();
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (device) => {
        setEditingDevice(device);
        setData({
            name: device.name || '',
            hostname: device.hostname || '',
            ip_address: device.ip_address || '',
            mac_address: device.mac_address || '',
            vendor_id: device.vendor_id || '',
            device_category_id: device.device_category_id || '',
            model: device.model || '',
            serial_number: device.serial_number || '',
            firmware: device.firmware || '',
            username: device.username || '',
            password: '',
            purchase_date: device.purchase_date || '',
            warranty: device.warranty || '',
            building_id: device.building_id || '',
            floor_id: device.floor_id || '',
            room_id: device.room_id || '',
            rack_id: device.rack_id || '',
            status: device.status || 'active',
            notes: device.notes || '',
            image: null
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingDevice) {
            post(route('devices.update', editingDevice.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('devices.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Perangkat',
            message: 'Apakah Anda yakin ingin menghapus perangkat ini dari inventaris?',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                destroy(route('devices.destroy', id));
            }
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Device Inventory
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Manage all server hardware and networking equipment nodes.
                        </p>
                    </div>
                    <div className="flex items-center space-x-3">
                        <button
                            onClick={() => setIsImportModalOpen(true)}
                            className="inline-flex items-center rounded-xl bg-emerald-500/15 border border-emerald-500/30 px-4 py-2.5 text-sm font-bold text-emerald-400 shadow-md hover:bg-emerald-500 hover:text-slate-950 transition duration-150"
                        >
                            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Import Excel UBG
                        </button>
                        <button
                            onClick={handleOpenCreateModal}
                            className="inline-flex items-center rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md hover:bg-brand-primaryHover transition duration-150"
                        >
                            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Add Device
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Device Inventory" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Filters & Search */}
                    <form onSubmit={handleSearch} className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-5 bg-brand-card p-4 rounded-2xl border border-brand-border shadow-lg">
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Search Query</label>
                            <input
                                type="text"
                                placeholder="Search by name, IP, hostname..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Category</label>
                            <select
                                value={selectedCategory}
                                onChange={(e) => setSelectedCategory(e.target.value)}
                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                            >
                                <option value="">All Categories</option>
                                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building</label>
                            <select
                                value={selectedBuilding}
                                onChange={(e) => setSelectedBuilding(e.target.value)}
                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                            >
                                <option value="">All Buildings</option>
                                {buildings.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                            </select>
                        </div>
                        <div className="flex items-end">
                            <button
                                type="submit"
                                className="w-full rounded-xl bg-brand-primary/15 border border-brand-primary/20 hover:bg-brand-primary hover:text-slate-950 px-4 py-2.5 text-sm font-semibold text-brand-primary shadow transition duration-150"
                            >
                                Filter Inventory
                            </button>
                        </div>
                    </form>

                    {/* Inventory Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border shadow-xl">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Perangkat & Board</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Hostname / IP Utama</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Posisi (Kode Tempat)</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">SN & Username</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Port / Interface</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Status</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {devices.length > 0 ? (
                                        devices.map((device, idx) => (
                                            <tr key={device.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                    <div className="flex items-center space-x-3">
                                                        <div className="h-9 w-9 rounded-xl bg-brand-primary/10 flex items-center justify-center text-brand-primary border border-brand-primary/20 font-mono font-bold text-xs shrink-0">
                                                            #{idx + 1}
                                                        </div>
                                                        <div>
                                                            <div className="font-bold text-white flex items-center gap-2">
                                                                {device.name}
                                                            </div>
                                                            <div className="text-xs text-brand-textSecondary mt-0.5 flex items-center gap-1.5">
                                                                <span className="bg-brand-bg px-1.5 py-0.5 rounded text-emerald-400 font-mono text-[11px]">{device.model || 'MikroTik'}</span>
                                                                <span>• {device.vendor?.name || 'MikroTik'}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div className="font-mono text-xs font-semibold text-emerald-400">{device.ip_address || '-'}</div>
                                                    <div className="text-xs text-brand-textMuted mt-0.5 font-mono">{device.hostname || '-'}</div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div className="font-bold text-white flex items-center space-x-1.5">
                                                        {device.room?.code && (
                                                            <span className="bg-cyan-500/15 text-cyan-300 border border-cyan-500/30 px-1.5 py-0.5 rounded text-[11px] font-mono">
                                                                {device.room.code}
                                                            </span>
                                                        )}
                                                        <span>{device.building?.name || '-'}</span>
                                                    </div>
                                                    <div className="text-xs text-brand-textMuted mt-0.5">
                                                        {device.room?.name || device.floor?.name || '-'}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div className="font-mono text-xs text-white">{device.serial_number || '-'}</div>
                                                    <div className="text-xs text-brand-textMuted mt-0.5 font-mono">User: {device.username || '-'}</div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <span className="inline-flex items-center rounded-lg bg-indigo-500/15 border border-indigo-500/30 px-2.5 py-1 text-xs font-bold text-indigo-300">
                                                        {device.device_interfaces?.length || 0} Ports
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold border ${
                                                        device.status === 'active' 
                                                            ? 'bg-emerald-500/10 text-emerald-450 border-emerald-500/20' 
                                                            : device.status === 'maintenance' 
                                                            ? 'bg-amber-500/10 text-amber-450 border-amber-500/20' 
                                                            : 'bg-rose-500/10 text-rose-450 border-rose-500/20'
                                                    }`}>
                                                        {device.status.charAt(0).toUpperCase() + device.status.slice(1)}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-1.5">
                                                        <button
                                                            onClick={() => setViewingDevice(device)}
                                                            className="rounded-lg bg-emerald-500/15 border border-emerald-500/30 text-emerald-400 hover:bg-emerald-500 hover:text-slate-950 px-2.5 py-1.5 text-xs font-bold transition flex items-center"
                                                        >
                                                            <svg className="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                            Detail
                                                        </button>
                                                        <button
                                                            onClick={() => handleOpenEditModal(device)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-2.5 py-1.5 text-xs font-bold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(device.id)}
                                                            className="rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-450 hover:bg-rose-600 hover:text-white px-2.5 py-1.5 text-xs font-bold transition"
                                                        >
                                                            Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="text-center py-8 text-brand-textSecondary text-sm">
                                                No inventory records found matching current query.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            {/* Create/Edit Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/85 flex items-center justify-center p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-brand-card border border-brand-border shadow-2xl">
                        {/* Modal Header — fixed */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-brand-border bg-brand-bgSecondary/40 shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-white">
                                    {editingDevice ? 'Edit Data Perangkat Inventaris' : 'Tambah Perangkat Inventaris Manual'}
                                </h3>
                                <p className="text-xs text-brand-textSecondary mt-0.5">
                                    Input data sesuai skema kolom Excel Inventaris UBG
                                </p>
                            </div>
                            <button
                                onClick={() => setIsModalOpen(false)}
                                className="text-brand-textSecondary hover:text-white transition text-2xl font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        {/* Modal Body — scrollable */}
                        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
                            <div className="overflow-y-auto px-6 py-5 space-y-6 flex-1">
                                {/* Group 1: Identitas & Jenis Perangkat */}
                                <div>
                                    <h4 className="text-xs font-bold text-emerald-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-emerald-400"></span>
                                        1. Identitas & Hardware (Merek / Board)
                                    </h4>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Nama Perangkat*</label>
                                            <input
                                                type="text"
                                                required
                                                placeholder="Contoh: Router MikroTik RB450Gx4"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            />
                                            {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">kode (Hostname)</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: R"
                                                value={data.hostname}
                                                onChange={(e) => setData('hostname', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            />
                                            {errors.hostname && <span className="text-xs text-rose-450 mt-1 block">{errors.hostname}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Jenis Perangkat (Category)*</label>
                                            <select
                                                required
                                                value={data.device_category_id}
                                                onChange={(e) => setData('device_category_id', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="">-- Pilih Jenis Perangkat --</option>
                                                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                            </select>
                                            {errors.device_category_id && <span className="text-xs text-rose-450 mt-1 block">{errors.device_category_id}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Merek (Vendor)*</label>
                                            <select
                                                required
                                                value={data.vendor_id}
                                                onChange={(e) => setData('vendor_id', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="">-- Pilih Merek --</option>
                                                {vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                            </select>
                                            {errors.vendor_id && <span className="text-xs text-rose-450 mt-1 block">{errors.vendor_id}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Board (Model Hardware)</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: RB450Gx4"
                                                value={data.model}
                                                onChange={(e) => setData('model', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            />
                                            {errors.model && <span className="text-xs text-rose-450 mt-1 block">{errors.model}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">SN (Serial Number)</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: HD508CJZHSR"
                                                value={data.serial_number}
                                                onChange={(e) => setData('serial_number', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary font-mono"
                                            />
                                            {errors.serial_number && <span className="text-xs text-rose-450 mt-1 block">{errors.serial_number}</span>}
                                        </div>
                                    </div>
                                </div>

                                {/* Group 2: Spesifikasi Software & Kredensial Akses */}
                                <div className="border-t border-brand-border/60 pt-4">
                                    <h4 className="text-xs font-bold text-cyan-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-cyan-400"></span>
                                        2. Software (Patch) & Kredensial Akses
                                    </h4>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Versi Software (Patch/Firmware)</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: RouterOS 7.14"
                                                value={data.firmware}
                                                onChange={(e) => setData('firmware', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">USERNAME Akses</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: admin"
                                                value={data.username}
                                                onChange={(e) => setData('username', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary font-mono"
                                            />
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">PASSWORD Akses</label>
                                            <input
                                                type="password"
                                                placeholder={editingDevice ? 'Kosongkan jika tak diubah' : 'Ketik password'}
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary font-mono"
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* Group 3: Posisi Perangkat & Network IP */}
                                <div className="border-t border-brand-border/60 pt-4">
                                    <h4 className="text-xs font-bold text-amber-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-amber-400"></span>
                                        3. Posisi Perangkat (Lokasi) & Network IP
                                    </h4>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Gedung (Building)*</label>
                                            <select
                                                required
                                                value={data.building_id}
                                                onChange={(e) => {
                                                    const bId = e.target.value;
                                                    setData(prev => {
                                                        const updated = { ...prev, building_id: bId, rack_id: '' };
                                                        const firstFloor = floors.find(f => f.building_id == bId);
                                                        if (firstFloor) {
                                                            updated.floor_id = firstFloor.id;
                                                            const firstRoom = rooms.find(r => r.floor_id == firstFloor.id);
                                                            if (firstRoom) {
                                                                updated.room_id = firstRoom.id;
                                                            } else {
                                                                updated.room_id = '';
                                                            }
                                                        } else {
                                                            updated.floor_id = '';
                                                            updated.room_id = '';
                                                        }
                                                        return updated;
                                                    });
                                                }}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="">-- Pilih Gedung --</option>
                                                {buildings.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                            </select>
                                            {errors.building_id && <span className="text-xs text-rose-450 mt-1 block">{errors.building_id}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Lantai (Floor)</label>
                                            <select
                                                value={data.floor_id}
                                                onChange={(e) => {
                                                    const fId = e.target.value;
                                                    setData(prev => {
                                                        const updated = { ...prev, floor_id: fId, rack_id: '' };
                                                        const firstRoom = rooms.find(r => r.floor_id == fId);
                                                        if (firstRoom) {
                                                            updated.room_id = firstRoom.id;
                                                        } else {
                                                            updated.room_id = '';
                                                        }
                                                        return updated;
                                                    });
                                                }}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="">-- Pilih Lantai --</option>
                                                {floors.filter(f => f.building_id == data.building_id).map(f => (
                                                    <option key={f.id} value={f.id}>{f.name}</option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Ruangan / Kode Tempat (Room)</label>
                                            <select
                                                value={data.room_id}
                                                onChange={(e) => {
                                                    const rId = e.target.value;
                                                    setData(prev => ({ ...prev, room_id: rId, rack_id: '' }));
                                                }}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="">-- Pilih Ruangan / Kode --</option>
                                                {rooms.filter(r => r.floor_id == data.floor_id).map(r => (
                                                    <option key={r.id} value={r.id}>
                                                        {r.code ? `[${r.code}] ` : ''}{r.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">
                                                IP Address Utama (Port API Opsional)
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: 118.98.127.16 atau 118.98.127.16:8729"
                                                value={data.ip_address}
                                                onChange={(e) => setData('ip_address', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary font-mono"
                                            />
                                            <span className="text-[10px] text-brand-textMuted mt-1 block">
                                                Gunakan format <code>IP:Port</code> jika port API RouterOS disesuaikan.
                                            </span>
                                            {errors.ip_address && <span className="text-xs text-rose-450 mt-1 block">{errors.ip_address}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">MAC Address Utama</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: 48:8E:EF:00:11:22"
                                                value={data.mac_address}
                                                onChange={(e) => setData('mac_address', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary font-mono"
                                            />
                                            {errors.mac_address && <span className="text-xs text-rose-450 mt-1 block">{errors.mac_address}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Status Operasional</label>
                                            <select
                                                value={data.status}
                                                onChange={(e) => setData('status', e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            >
                                                <option value="active">Active / Normal</option>
                                                <option value="maintenance">Maintenance</option>
                                                <option value="offline">Offline / Down</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                {/* Group 4: Lampiran Foto & Catatan Detail */}
                                <div className="border-t border-brand-border/60 pt-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Foto Node Perangkat (Opsional)</label>
                                            <input
                                                type="file"
                                                onChange={(e) => setData('image', e.target.files[0])}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-brand-textSecondary file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-brand-primary/10 file:text-brand-primary hover:file:bg-brand-primary/20"
                                            />
                                            {errors.image && <span className="text-xs text-rose-450 mt-1 block">{errors.image}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Catatan / Detail Tambahan (Notes / Bandwidth)</label>
                                            <textarea
                                                value={data.notes}
                                                onChange={(e) => setData('notes', e.target.value)}
                                                rows="2"
                                                placeholder="Contoh: Bandwidth: 1 Gbps / Catatan lokasi khusus"
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            ></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Modal Footer — fixed */}
                            <div className="flex justify-end space-x-3 px-6 py-4 border-t border-brand-border bg-brand-bgSecondary/20 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="rounded-xl border border-brand-border hover:bg-brand-bgSecondary px-4 py-2.5 text-sm font-semibold text-brand-textSecondary transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-5 py-2.5 text-sm font-bold text-slate-950 shadow transition duration-150"
                                >
                                    {editingDevice ? 'Simpan Perubahan' : 'Simpan Perangkat Baru'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Import Excel Modal */}
            {isImportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-2xl bg-brand-card border border-brand-border shadow-2xl">
                        <div className="flex justify-between items-center px-6 py-4 border-b border-brand-border">
                            <h3 className="text-lg font-bold text-white flex items-center">
                                <svg className="h-5 w-5 mr-2 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                Import Data Inventaris (.xlsx)
                            </h3>
                            <button
                                onClick={() => setIsImportModalOpen(false)}
                                className="text-brand-textSecondary hover:text-white text-xl font-bold"
                            >
                                &times;
                            </button>
                        </div>
                        <form onSubmit={handleImportSubmit} className="p-6 space-y-4">
                            <p className="text-sm text-brand-textSecondary">
                                Unggah file Excel inventaris (seperti <code className="text-emerald-400 bg-brand-bg px-1.5 py-0.5 rounded">Inventaris Jaringan UBG.xlsx</code>). Sistem CIMS akan memproses lembar <strong>GEDUNG & RUANGAN</strong> serta <strong>Router</strong> secara otomatis.
                            </p>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-2">Pilih File Excel (.xlsx / .xls)</label>
                                <input
                                    type="file"
                                    accept=".xlsx, .xls"
                                    onChange={(e) => importForm.setData('file', e.target.files[0])}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-brand-textSecondary file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-500/10 file:text-emerald-400 hover:file:bg-emerald-500/20"
                                />
                                {importForm.errors.file && <span className="text-xs text-rose-450 mt-1 block">{importForm.errors.file}</span>}
                            </div>

                            <div className="flex justify-end space-x-3 pt-4 border-t border-brand-border">
                                <button
                                    type="button"
                                    onClick={() => setIsImportModalOpen(false)}
                                    className="rounded-xl border border-brand-border hover:bg-brand-bgSecondary px-4 py-2.5 text-sm font-semibold text-brand-textSecondary transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={importForm.processing || !importForm.data.file}
                                    className="rounded-xl bg-emerald-500 hover:bg-emerald-400 px-5 py-2.5 text-sm font-bold text-slate-950 shadow transition duration-150 disabled:opacity-50"
                                >
                                    {importForm.processing ? 'Mengimport...' : 'Mulai Import Excel'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* View Device Detail Modal */}
            {viewingDevice && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/85 flex items-center justify-center p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-brand-card border border-brand-border shadow-2xl overflow-hidden">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-brand-border bg-brand-bgSecondary/40 shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-white flex items-center gap-2">
                                    <span className="bg-brand-primary/10 text-brand-primary border border-brand-primary/20 px-2 py-0.5 rounded text-xs font-mono">
                                        {viewingDevice.model || 'Device'}
                                    </span>
                                    {viewingDevice.name}
                                </h3>
                                <p className="text-xs text-brand-textSecondary mt-0.5">
                                    Detail Inventaris Lengkap (Excel UBG Specification Mapping)
                                </p>
                            </div>
                            <button
                                onClick={() => setViewingDevice(null)}
                                className="text-brand-textSecondary hover:text-white transition text-2xl font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="overflow-y-auto p-6 space-y-6 flex-1 text-sm">
                            {/* Grid 23 Excel Fields */}
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 bg-brand-bg/60 p-4 rounded-xl border border-brand-border/60">
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">kode (Hostname)</span>
                                    <span className="font-mono text-white font-semibold">{viewingDevice.hostname || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Posisi Perangkat (Kode Tempat)</span>
                                    <span className="font-semibold text-cyan-300">
                                        {viewingDevice.room?.code ? `[${viewingDevice.room.code}] ` : ''}
                                        {viewingDevice.building?.name || '-'} ({viewingDevice.room?.name || viewingDevice.floor?.name || '-'})
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Jenis Perangkat</span>
                                    <span className="text-white font-medium">{viewingDevice.category?.name || 'Router'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Merek</span>
                                    <span className="text-white font-medium">{viewingDevice.vendor?.name || 'MikroTik'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Board (Model)</span>
                                    <span className="font-mono text-emerald-400 font-semibold">{viewingDevice.model || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Bandwith</span>
                                    <span className="text-white font-medium">
                                        {viewingDevice.notes?.includes('Bandwidth:') ? viewingDevice.notes.split('Bandwidth:')[1].trim() : '-'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Versi Software (Patch/Firmware)</span>
                                    <span className="font-mono text-white">{viewingDevice.firmware || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">SN (Serial Number)</span>
                                    <span className="font-mono text-amber-300 font-semibold">{viewingDevice.serial_number || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Kredensial Akses</span>
                                    <span className="font-mono text-white text-xs">
                                        User: <strong>{viewingDevice.username || '-'}</strong> | Pass: <span className="text-brand-textMuted italic">Terenkripsi</span>
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">IP Utama</span>
                                    <span className="font-mono text-emerald-400 font-bold">{viewingDevice.ip_address || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">MAC Address Utama</span>
                                    <span className="font-mono text-white">{viewingDevice.mac_address || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-brand-textSecondary">Status Operasional & Kondisi</span>
                                    <span className="inline-flex items-center rounded bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 text-xs font-bold text-emerald-400 capitalize">
                                        {viewingDevice.status} • Normal
                                    </span>
                                </div>
                            </div>

                            {/* Section Interfaces */}
                            <div>
                                <h4 className="text-sm font-bold text-white mb-3 flex items-center justify-between">
                                    <span className="flex items-center">
                                        <svg className="w-4 h-4 mr-1.5 text-brand-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        Daftar Interface & Port ({viewingDevice.device_interfaces?.length || 0} Port)
                                    </span>
                                </h4>
                                <div className="overflow-x-auto rounded-xl border border-brand-border bg-brand-bg">
                                    <table className="min-w-full divide-y divide-brand-border text-xs text-left">
                                        <thead className="bg-brand-bgSecondary/60 text-brand-textSecondary">
                                            <tr>
                                                <th className="py-2.5 px-3 font-bold">Interface</th>
                                                <th className="py-2.5 px-3 font-bold">MAC Address</th>
                                                <th className="py-2.5 px-3 font-bold">IP Address / Prefix</th>
                                                <th className="py-2.5 px-3 font-bold">Bridge</th>
                                                <th className="py-2.5 px-3 font-bold">Jenis Interface</th>
                                                <th className="py-2.5 px-3 font-bold">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-brand-border/40 text-brand-textSecondary font-mono">
                                            {viewingDevice.device_interfaces && viewingDevice.device_interfaces.length > 0 ? (
                                                viewingDevice.device_interfaces.map((iface) => (
                                                    <tr key={iface.id} className="hover:bg-brand-bgSecondary/20">
                                                        <td className="py-2 px-3 font-bold text-white">{iface.interface_name}</td>
                                                        <td className="py-2 px-3">{iface.mac_address || '-'}</td>
                                                        <td className="py-2 px-3 text-emerald-400 font-bold">
                                                            {iface.ip_address ? `${iface.ip_address}${iface.subnet || ''}` : '-'}
                                                        </td>
                                                        <td className="py-2 px-3 text-cyan-300">
                                                            {iface.description?.includes('Bridge:') ? iface.description.split('Bridge:')[1].trim() : '-'}
                                                        </td>
                                                        <td className="py-2 px-3">{iface.interface_type || 'Ethernet'}</td>
                                                        <td className="py-2 px-3">
                                                            <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                                                iface.interface_status === 'up' 
                                                                    ? 'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30' 
                                                                    : 'bg-rose-500/20 text-rose-400 border border-rose-500/30'
                                                            }`}>
                                                                {iface.interface_status === 'up' ? 'Aktif' : 'Nonaktif'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="6" className="py-4 text-center text-brand-textMuted italic font-sans">
                                                        Belum ada port interface yang terdaftar untuk perangkat ini.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div className="flex justify-end px-6 py-4 border-t border-brand-border bg-brand-bgSecondary/20 shrink-0">
                            <button
                                onClick={() => setViewingDevice(null)}
                                className="rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-5 py-2.5 text-sm font-bold text-slate-950 shadow transition duration-150"
                            >
                                Tutup Detail
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
