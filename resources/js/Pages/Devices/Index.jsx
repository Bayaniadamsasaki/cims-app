import CimsLayout from '@/Layouts/CimsLayout';
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
    const [isSyncing, setIsSyncing] = useState(false);
    const { confirmAction } = useConfirmation();

    const handleSyncInterfaces = (deviceId) => {
        setIsSyncing(true);
        router.post(route('devices.sync-interfaces', deviceId), {}, {
            preserveScroll: true,
            onSuccess: (page) => {
                setIsSyncing(false);
                if (viewingDevice && viewingDevice.id === deviceId) {
                    const updated = page.props.devices?.find(d => d.id === deviceId);
                    if (updated) {
                        setViewingDevice(updated);
                    }
                }
            },
            onError: () => setIsSyncing(false),
            onFinish: () => setIsSyncing(false)
        });
    };

    // Form Import Excel
    const importForm = useForm({
        file: null
    });

    const handleImportSubmit = (e) => {
        e.preventDefault();
        if (!importForm.data.file) return;

        // Controller membalas dengan redirect back(), jadi respons visit ini sudah
        // membawa daftar perangkat terbaru — cukup dibaca dari page.props.
        importForm.post(route('devices.import'), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                // Import gagal: modal dibiarkan terbuka, pesannya tampil lewat Toast.
                if (page.props.flash?.error) return;

                setIsImportModalOpen(false);
                importForm.reset();

                // Daftar diurut terbaru dahulu, jadi entri pertama adalah hasil import.
                const refreshed = page.props.devices ?? [];
                if (refreshed.length > 0) {
                    setViewingDevice(refreshed[0]);
                }
            },
        });
    };
    
    // Form Input / Edit Manual Perangkat
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
        is_monitored: false,
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
            password: device.password_plain || '',
            purchase_date: device.purchase_date || '',
            warranty: device.warranty || '',
            building_id: device.building_id || '',
            floor_id: device.floor_id || '',
            room_id: device.room_id || '',
            rack_id: device.rack_id || '',
            status: device.status || 'active',
            notes: device.notes || '',
            is_monitored: device.source === 'live_api',
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
        <CimsLayout
            header={
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            Inventaris Perangkat (Device Inventory)
                        </h2>
                        <p className="text-sm text-slate-500">
                            Kelola semua perangkat router, switch, server, dan peralatan jaringan CIMS.
                        </p>
                    </div>
                    <div className="flex items-center space-x-3">
                        <button
                            onClick={() => setIsImportModalOpen(true)}
                            className="inline-flex items-center rounded-xl bg-emerald-50 border border-emerald-200 px-4 py-2.5 text-sm font-semibold text-emerald-700 hover:bg-emerald-100 transition duration-150"
                        >
                            <svg className="h-5 w-5 mr-2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                            </svg>
                            Import Excel UBG
                        </button>
                        <button
                            onClick={handleOpenCreateModal}
                            className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition duration-150"
                        >
                            <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                            </svg>
                            Tambah Perangkat
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Device Inventory" />

            <div className="text-slate-900">
                {/* Filters & Search */}
                <form onSubmit={handleSearch} className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-5 bg-white p-4 rounded-2xl border border-slate-200">
                    <div className="sm:col-span-2">
                        <label className="block text-xs font-semibold text-slate-600 mb-1">Cari Perangkat</label>
                        <input
                            type="text"
                            placeholder="Cari nama, IP, hostname..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1">Kategori</label>
                        <select
                            value={selectedCategory}
                            onChange={(e) => setSelectedCategory(e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        >
                            <option value="" className="bg-white text-slate-800">Semua Kategori</option>
                            {categories.map(c => <option key={c.id} value={c.id} className="bg-white text-slate-800">{c.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1">Gedung</label>
                        <select
                            value={selectedBuilding}
                            onChange={(e) => setSelectedBuilding(e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        >
                            <option value="" className="bg-white text-slate-800">Semua Gedung</option>
                            {buildings.map(b => <option key={b.id} value={b.id} className="bg-white text-slate-800">{b.name}</option>)}
                        </select>
                    </div>
                    <div className="flex items-end">
                        <button
                            type="submit"
                            className="w-full rounded-xl bg-blue-50 border border-blue-200 hover:bg-blue-600 hover:text-white px-4 py-2.5 text-sm font-semibold text-blue-700 transition duration-150"
                        >
                            Filter Inventaris
                        </button>
                    </div>
                </form>

                {/* Inventory Table */}
                <div className="overflow-hidden rounded-2xl bg-white border border-slate-200">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-left">
                            <thead className="bg-slate-50">
                                <tr>
                                    <th className="py-4 pl-6 pr-3 text-xs font-bold text-slate-500">Perangkat & Model</th>
                                    <th className="px-3 py-4 text-xs font-bold text-slate-500">Hostname / IP Utama</th>
                                    <th className="px-3 py-4 text-xs font-bold text-slate-500">Posisi & Lokasi</th>
                                    <th className="px-3 py-4 text-xs font-bold text-slate-500">Serial Number & User</th>
                                    <th className="px-3 py-4 text-xs font-bold text-slate-500">Sumber / Monitored</th>
                                    <th className="px-3 py-4 text-xs font-bold text-slate-500">Status</th>
                                    <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-slate-500">Aksi</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {devices.length > 0 ? (
                                    devices.map((device, idx) => (
                                        <tr key={device.id} className="hover:bg-slate-50/80 transition">
                                            <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                <div className="flex items-center space-x-3">
                                                    <div className="h-9 w-9 rounded-xl bg-blue-50 flex items-center justify-center text-blue-700 border border-blue-100 font-mono font-bold text-xs shrink-0">
                                                        #{idx + 1}
                                                    </div>
                                                    <div>
                                                        <div className="font-bold text-slate-900 flex items-center gap-2">
                                                            {device.name}
                                                        </div>
                                                        <div className="text-xs text-slate-500 mt-0.5 flex items-center gap-1.5">
                                                            <span className="bg-slate-100 px-1.5 py-0.5 rounded text-emerald-700 font-mono text-[11px]">{device.model || 'MikroTik'}</span>
                                                            <span>• {device.vendor?.name || 'MikroTik'}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                <div className="font-mono text-xs font-semibold text-emerald-700">{device.ip_address || '-'}</div>
                                                <div className="text-xs text-slate-400 mt-0.5 font-mono">{device.hostname || '-'}</div>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                <div className="font-bold text-slate-900 flex items-center space-x-1.5">
                                                    {device.room?.code && (
                                                        <span className="bg-cyan-50 text-cyan-700 border border-cyan-200 px-1.5 py-0.5 rounded text-[11px] font-mono">
                                                            {device.room.code}
                                                        </span>
                                                    )}
                                                    <span>{device.building?.name || '-'}</span>
                                                </div>
                                                <div className="text-xs text-slate-400 mt-0.5">
                                                    {device.room?.name || device.floor?.name || '-'}
                                                </div>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                <div className="font-mono text-xs text-slate-900">{device.serial_number || '-'}</div>
                                                <div className="text-xs text-slate-500 mt-0.5 font-mono">User: {device.username || '-'} | Pass: {device.password_plain || device.password || '-'}</div>
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                {device.source === 'live_api' ? (
                                                    <span className="inline-flex items-center rounded-lg bg-emerald-50 border border-emerald-200 px-2.5 py-1 text-xs font-bold text-emerald-700">
                                                        📡 Live API Monitored
                                                    </span>
                                                ) : (
                                                    <span className="inline-flex items-center rounded-lg bg-slate-100 border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-600">
                                                        📦 Inventaris Statis
                                                    </span>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                <span className={`inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold border ${
                                                    device.status === 'active'
                                                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                                        : device.status === 'maintenance'
                                                        ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                        : 'bg-rose-50 text-rose-700 border-rose-200'
                                                }`}>
                                                    {device.status.charAt(0).toUpperCase() + device.status.slice(1)}
                                                </span>
                                            </td>
                                            <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                <div className="flex justify-end space-x-1.5">
                                                    <button
                                                        onClick={() => setViewingDevice(device)}
                                                        className="rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-600 hover:text-white px-2.5 py-1.5 text-xs font-bold transition flex items-center"
                                                    >
                                                        <svg className="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                        </svg>
                                                        Detail
                                                    </button>
                                                    <button
                                                        onClick={() => handleOpenEditModal(device)}
                                                        className="rounded-lg bg-blue-50 border border-blue-200 text-blue-700 hover:bg-blue-600 hover:text-white px-2.5 py-1.5 text-xs font-bold transition"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(device.id)}
                                                        className="rounded-lg bg-rose-50 border border-rose-200 text-red-700 hover:bg-rose-600 hover:text-white px-2.5 py-1.5 text-xs font-bold transition"
                                                    >
                                                        Hapus
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="7" className="text-center py-8 text-slate-500 text-sm">
                                            Belum ada perangkat inventaris yang sesuai dengan filter pencarian.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Modal Tambah / Edit Perangkat Manual */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 flex items-center justify-center p-4 backdrop-blur-sm">
                    <div className="relative w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-white border border-slate-200 shadow-xl">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50 shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900">
                                    {editingDevice ? 'Edit Data Perangkat Inventaris' : 'Tambah Perangkat Inventaris Manual'}
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Isi data sesuai spesifikasi perangkat jaringan CIMS UBG
                                </p>
                            </div>
                            <button
                                onClick={() => setIsModalOpen(false)}
                                className="text-slate-400 hover:text-slate-700 transition text-2xl font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        {/* Modal Body */}
                        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
                            <div className="overflow-y-auto px-6 py-5 space-y-6 flex-1">
                                {/* Group 1: Identitas & Hardware */}
                                <div>
                                    <h4 className="text-xs font-bold text-emerald-700 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                        <span className="h-2 w-2 rounded-full bg-emerald-500"></span>
                                        1. Identitas & Hardware (Merek / Board)
                                    </h4>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Nama Perangkat*</label>
                                            <input
                                                type="text"
                                                required
                                                placeholder="Contoh: Router MikroTik RB450Gx4"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            />
                                            {errors.name && <span className="text-xs text-red-600 mt-1 block">{errors.name}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Kode / Hostname</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: RB-CORE-01"
                                                value={data.hostname}
                                                onChange={(e) => setData('hostname', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            />
                                            {errors.hostname && <span className="text-xs text-red-600 mt-1 block">{errors.hostname}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Jenis Perangkat (Kategori)*</label>
                                            <select
                                                required
                                                value={data.device_category_id}
                                                onChange={(e) => setData('device_category_id', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            >
                                                <option value="">-- Pilih Jenis Perangkat --</option>
                                                {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                            </select>
                                            {errors.device_category_id && <span className="text-xs text-red-600 mt-1 block">{errors.device_category_id}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Merek (Vendor)*</label>
                                            <select
                                                required
                                                value={data.vendor_id}
                                                onChange={(e) => setData('vendor_id', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            >
                                                <option value="">-- Pilih Merek --</option>
                                                {vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                            </select>
                                            {errors.vendor_id && <span className="text-xs text-red-600 mt-1 block">{errors.vendor_id}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Board / Model Hardware</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: RB450Gx4"
                                                value={data.model}
                                                onChange={(e) => setData('model', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            />
                                            {errors.model && <span className="text-xs text-red-600 mt-1 block">{errors.model}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Serial Number (SN)</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: HD508CJZHSR"
                                                value={data.serial_number}
                                                onChange={(e) => setData('serial_number', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono"
                                            />
                                            {errors.serial_number && <span className="text-xs text-red-600 mt-1 block">{errors.serial_number}</span>}
                                        </div>
                                    </div>
                                </div>

                                 {/* Group 2: Software & Kredensial */}
                                 <div className="border-t border-slate-200 pt-4">
                                     <h4 className="text-xs font-bold text-cyan-700 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                         <span className="h-2 w-2 rounded-full bg-cyan-500"></span>
                                         2. Software & Kredensial Akses
                                     </h4>
                                     <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Versi Software / Firmware</label>
                                             <input
                                                 type="text"
                                                 placeholder="Contoh: RouterOS 7.14"
                                                 value={data.firmware}
                                                 onChange={(e) => setData('firmware', e.target.value)}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                             />
                                         </div>
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Username Akses</label>
                                             <input
                                                 type="text"
                                                 placeholder="Contoh: admin"
                                                 value={data.username}
                                                 onChange={(e) => setData('username', e.target.value)}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono"
                                             />
                                         </div>
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Password Akses (Transparan)</label>
                                             <input
                                                 type="text"
                                                 placeholder="Contoh: admin123"
                                                 value={data.password}
                                                 onChange={(e) => setData('password', e.target.value)}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono font-medium"
                                             />
                                         </div>
                                     </div>
                                 </div>

                                 {/* Group 3: Lokasi & IP Network */}
                                 <div className="border-t border-slate-200 pt-4">
                                     <h4 className="text-xs font-bold text-amber-700 uppercase tracking-wider mb-3 flex items-center gap-1.5">
                                         <span className="h-2 w-2 rounded-full bg-amber-500"></span>
                                         3. Posisi Perangkat (Lokasi) & IP Address
                                     </h4>
                                     <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Gedung*</label>
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
                                                             const availableRooms = rooms
                                                                 .filter(r => r.floor_id == firstFloor.id)
                                                                 .sort((a, b) => {
                                                                     const aIsServer = (a.name || '').toLowerCase().includes('server') || (a.code || '').includes('RS');
                                                                     const bIsServer = (b.name || '').toLowerCase().includes('server') || (b.code || '').includes('RS');
                                                                     if (aIsServer && !bIsServer) return -1;
                                                                     if (!aIsServer && bIsServer) return 1;
                                                                     return 0;
                                                                 });
                                                             updated.room_id = availableRooms.length > 0 ? availableRooms[0].id : '';
                                                         } else {
                                                             updated.floor_id = '';
                                                             updated.room_id = '';
                                                         }
                                                         return updated;
                                                     });
                                                 }}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                             >
                                                 <option value="">-- Pilih Gedung --</option>
                                                 {buildings.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                             </select>
                                             {errors.building_id && <span className="text-xs text-red-600 mt-1 block">{errors.building_id}</span>}
                                         </div>
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Lantai</label>
                                             <select
                                                 value={data.floor_id}
                                                 onChange={(e) => {
                                                     const fId = e.target.value;
                                                     setData(prev => {
                                                         const updated = { ...prev, floor_id: fId, rack_id: '' };
                                                         const availableRooms = rooms
                                                             .filter(r => r.floor_id == fId)
                                                             .sort((a, b) => {
                                                                 const aIsServer = (a.name || '').toLowerCase().includes('server') || (a.code || '').includes('RS');
                                                                 const bIsServer = (b.name || '').toLowerCase().includes('server') || (b.code || '').includes('RS');
                                                                 if (aIsServer && !bIsServer) return -1;
                                                                 if (!aIsServer && bIsServer) return 1;
                                                                 return 0;
                                                             });
                                                         updated.room_id = availableRooms.length > 0 ? availableRooms[0].id : '';
                                                         return updated;
                                                     });
                                                 }}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                             >
                                                 <option value="">-- Pilih Lantai --</option>
                                                 {floors.filter(f => f.building_id == data.building_id).map(f => (
                                                     <option key={f.id} value={f.id}>{f.name}</option>
                                                 ))}
                                             </select>
                                         </div>
                                         <div>
                                             <label className="block text-xs font-semibold text-slate-600 mb-1">Ruangan / Kode Tempat</label>
                                             <select
                                                 value={data.room_id}
                                                 onChange={(e) => {
                                                     const rId = e.target.value;
                                                     setData(prev => ({ ...prev, room_id: rId, rack_id: '' }));
                                                 }}
                                                 className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-medium"
                                             >
                                                 <option value="">-- Pilih Ruangan --</option>
                                                 {rooms
                                                     .filter(r => r.floor_id == data.floor_id)
                                                     .sort((a, b) => {
                                                         const aIsServer = (a.name || '').toLowerCase().includes('server') || (a.code || '').includes('RS');
                                                         const bIsServer = (b.name || '').toLowerCase().includes('server') || (b.code || '').includes('RS');
                                                         if (aIsServer && !bIsServer) return -1;
                                                         if (!aIsServer && bIsServer) return 1;
                                                         return 0;
                                                     })
                                                     .map(r => {
                                                         const isServer = (r.name || '').toLowerCase().includes('server') || (r.code || '').includes('RS');
                                                         return (
                                                             <option key={r.id} value={r.id}>
                                                                 {isServer ? '🖥️ ' : ''}{r.code ? `[${r.code}] ` : ''}{r.name}{isServer ? ' (Utama)' : ''}
                                                             </option>
                                                         );
                                                     })}
                                             </select>
                                         </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">
                                                IP Address Utama
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: 118.98.127.16"
                                                value={data.ip_address}
                                                onChange={(e) => setData('ip_address', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono"
                                            />
                                            {errors.ip_address && <span className="text-xs text-red-600 mt-1 block">{errors.ip_address}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">MAC Address Utama</label>
                                            <input
                                                type="text"
                                                placeholder="Contoh: 48:8E:EF:00:11:22"
                                                value={data.mac_address}
                                                onChange={(e) => setData('mac_address', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono"
                                            />
                                            {errors.mac_address && <span className="text-xs text-red-600 mt-1 block">{errors.mac_address}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Status Operasional</label>
                                            <select
                                                value={data.status}
                                                onChange={(e) => setData('status', e.target.value)}
                                                className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            >
                                                <option value="active">Active / Normal</option>
                                                <option value="maintenance">Maintenance</option>
                                                <option value="offline">Offline / Down</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                {/* Group 4: Catatan & Mode Integration */}
                                <div className="border-t border-slate-200 pt-4">
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Foto Perangkat (Opsional)</label>
                                            <input
                                                type="file"
                                                onChange={(e) => setData('image', e.target.files[0])}
                                                className="w-full rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                            />
                                            {errors.image && <span className="text-xs text-red-600 mt-1 block">{errors.image}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-slate-600 mb-1">Catatan Tambahan</label>
                                            <textarea
                                                value={data.notes}
                                                onChange={(e) => setData('notes', e.target.value)}
                                                rows="2"
                                                placeholder="Contoh: Bandwidth: 1 Gbps / Keterangan posisi port"
                                                className="w-full rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                            ></textarea>
                                        </div>
                                    </div>

                                    {/* Toggle Checkbox fitub is_monitored (Live API / Inventory) */}
                                    <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                        <label className="flex items-start gap-3 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={!!data.is_monitored}
                                                onChange={(e) => setData('is_monitored', e.target.checked)}
                                                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                                            />
                                            <div>
                                                <span className="block text-sm font-semibold text-slate-900">
                                                    Masukkan ke Live Monitoring API
                                                </span>
                                                <span className="block text-xs text-slate-500 mt-0.5">
                                                    Jika dicentang, <code className="text-emerald-700 bg-white px-1.5 py-0.5 rounded border border-slate-200">source</code> bernilai
                                                    <code className="text-emerald-700 bg-white px-1.5 py-0.5 rounded border border-slate-200 ml-1">live_api</code>
                                                    dan perangkat dipantau secara real-time.
                                                    Jika tidak, sumber bernilai
                                                    <code className="text-slate-600 bg-white px-1.5 py-0.5 rounded border border-slate-200 ml-1">inventory</code>
                                                    (hanya pencatatan statis).
                                                </span>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            {/* Modal Footer */}
                            <div className="flex justify-end space-x-3 px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="rounded-xl border border-slate-200 hover:bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-600 transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-blue-600 hover:bg-blue-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-150"
                                >
                                    {editingDevice ? 'Simpan Perubahan' : 'Simpan Perangkat Baru'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal Import Excel */}
            {isImportModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-2xl bg-white border border-slate-200 shadow-xl">
                        <div className="flex justify-between items-center px-6 py-4 border-b border-slate-200 bg-slate-50">
                            <h3 className="text-lg font-bold text-slate-900 flex items-center">
                                <svg className="h-5 w-5 mr-2 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                                Import Data Inventaris (.xlsx / .xls)
                            </h3>
                            <button
                                onClick={() => setIsImportModalOpen(false)}
                                className="text-slate-400 hover:text-slate-700 text-xl font-bold"
                            >
                                &times;
                            </button>
                        </div>
                        <form onSubmit={handleImportSubmit} className="p-6 space-y-4">
                            <p className="text-sm text-slate-600">
                                Unggah file Excel inventaris (seperti <code className="text-emerald-700 bg-slate-100 px-1.5 py-0.5 rounded border border-slate-200">Inventaris Jaringan UBG.xlsx</code>). Baris data akan otomatis diproses dari baris ke-4.
                            </p>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-2">Pilih File Excel (.xlsx / .xls / .csv)</label>
                                <input
                                    type="file"
                                    accept=".xlsx, .xls, .csv"
                                    onChange={(e) => importForm.setData('file', e.target.files[0])}
                                    className="w-full rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-600 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100"
                                />
                                {importForm.errors.file && <span className="text-xs text-red-600 mt-1 block">{importForm.errors.file}</span>}
                            </div>

                            <div className="flex justify-end space-x-3 pt-4 border-t border-slate-200">
                                <button
                                    type="button"
                                    onClick={() => setIsImportModalOpen(false)}
                                    className="rounded-xl border border-slate-200 hover:bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-600 transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={importForm.processing || !importForm.data.file}
                                    className="rounded-xl bg-emerald-600 hover:bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-150 disabled:opacity-50"
                                >
                                    {importForm.processing ? 'Mengimport...' : 'Mulai Import Excel'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal Detail Perangkat */}
            {viewingDevice && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 flex items-center justify-center p-4 backdrop-blur-sm">
                    <div className="relative w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-white border border-slate-200 shadow-xl overflow-hidden">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-200 bg-slate-50 shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 flex items-center gap-2">
                                    <span className="bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded text-xs font-mono">
                                        {viewingDevice.model || 'Device'}
                                    </span>
                                    {viewingDevice.name}
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Spesifikasi & Detail Inventaris CIMS
                                </p>
                            </div>
                            <button
                                onClick={() => setViewingDevice(null)}
                                className="text-slate-400 hover:text-slate-700 transition text-2xl font-bold"
                            >
                                &times;
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="overflow-y-auto p-6 space-y-6 flex-1 text-sm">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Kode / Hostname</span>
                                    <span className="font-mono text-slate-900 font-semibold">{viewingDevice.hostname || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Posisi & Gedung</span>
                                    <span className="font-semibold text-cyan-700">
                                        {viewingDevice.room?.code ? `[${viewingDevice.room.code}] ` : ''}
                                        {viewingDevice.building?.name || '-'} ({viewingDevice.room?.name || viewingDevice.floor?.name || '-'})
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Jenis Perangkat</span>
                                    <span className="text-slate-900 font-medium">{viewingDevice.category?.name || 'Router'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Merek</span>
                                    <span className="text-slate-900 font-medium">{viewingDevice.vendor?.name || 'MikroTik'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Board / Model</span>
                                    <span className="font-mono text-emerald-700 font-semibold">{viewingDevice.model || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Serial Number (SN)</span>
                                    <span className="font-mono text-slate-900 font-semibold">{viewingDevice.serial_number || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Versi Firmware</span>
                                    <span className="font-mono text-slate-900">{viewingDevice.firmware || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Kredensial Akses</span>
                                    <div className="font-mono text-slate-900 text-xs mt-0.5 space-y-0.5">
                                        <div>User: <strong className="text-slate-900">{viewingDevice.username || '-'}</strong></div>
                                        <div>Pass: <strong className="text-blue-700 font-bold">{viewingDevice.password_plain || viewingDevice.password || '-'}</strong></div>
                                    </div>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">IP Utama</span>
                                    <span className="font-mono text-emerald-700 font-bold">{viewingDevice.ip_address || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">MAC Address Utama</span>
                                    <span className="font-mono text-slate-900">{viewingDevice.mac_address || '-'}</span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Sumber Monitoring</span>
                                    <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-bold ${
                                        viewingDevice.source === 'live_api' ? 'bg-emerald-100 text-emerald-700 border border-emerald-200' : 'bg-slate-200 text-slate-700'
                                    }`}>
                                        {viewingDevice.source === 'live_api' ? 'Live API Monitored' : 'Inventaris Statis'}
                                    </span>
                                </div>
                                <div>
                                    <span className="block text-[11px] font-bold uppercase text-slate-500">Status Operasional</span>
                                    <span className="inline-flex items-center rounded bg-emerald-50 border border-emerald-200 px-2 py-0.5 text-xs font-bold text-emerald-700 capitalize">
                                        {viewingDevice.status || 'active'}
                                    </span>
                                </div>
                            </div>

                            {/* Section Interfaces */}
                            <div>
                                <div className="mb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                    <h4 className="text-sm font-bold text-slate-900 flex items-center">
                                        <svg className="w-4 h-4 mr-1.5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        Daftar Interface & Port ({(viewingDevice.device_interfaces || viewingDevice.interfaces || []).length} Port)
                                    </h4>
                                    <button
                                        type="button"
                                        disabled={isSyncing}
                                        onClick={() => handleSyncInterfaces(viewingDevice.id)}
                                        className="inline-flex items-center px-3 py-1.5 rounded-xl bg-blue-50 border border-blue-200 text-blue-700 hover:bg-blue-600 hover:text-white text-xs font-bold transition disabled:opacity-50 shadow-sm"
                                    >
                                        <svg className={`w-3.5 h-3.5 mr-1.5 ${isSyncing ? 'animate-spin' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        {isSyncing ? 'Menghubungkan...' : '⚡ Sinkronkan Port & Interface'}
                                    </button>
                                </div>
                                <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                                    <table className="min-w-full divide-y divide-slate-200 text-xs text-left">
                                        <thead className="bg-slate-50 text-slate-500">
                                            <tr>
                                                <th className="py-2.5 px-3 font-bold">Interface</th>
                                                <th className="py-2.5 px-3 font-bold">MAC Address</th>
                                                <th className="py-2.5 px-3 font-bold">IP Address / Prefix</th>
                                                <th className="py-2.5 px-3 font-bold">Jenis Interface</th>
                                                <th className="py-2.5 px-3 font-bold">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 text-slate-600 font-mono">
                                            {(viewingDevice.device_interfaces || viewingDevice.interfaces || []).length > 0 ? (
                                                (viewingDevice.device_interfaces || viewingDevice.interfaces || []).map((iface, i) => (
                                                    <tr key={iface.id || i} className="hover:bg-slate-50">
                                                        <td className="py-2 px-3 font-bold text-slate-900">{iface.interface_name}</td>
                                                        <td className="py-2 px-3">{iface.mac_address || '-'}</td>
                                                        <td className="py-2 px-3 text-emerald-700 font-bold">
                                                             {iface.ip_address ? iface.subnet ? `${iface.ip_address}/${iface.subnet}` : iface.ip_address : '-'}
                                                         </td>
                                                        <td className="py-2 px-3">{iface.interface_type || 'Ethernet'}</td>
                                                        <td className="py-2 px-3">
                                                            <span className={`px-2 py-0.5 rounded text-[10px] font-bold ${
                                                                iface.interface_status === 'up'
                                                                    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                                                                    : 'bg-rose-50 text-rose-700 border border-rose-200'
                                                            }`}>
                                                                {iface.interface_status === 'up' ? 'Aktif' : 'Nonaktif'}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))
                                            ) : (
                                                <tr>
                                                    <td colSpan="5" className="py-4 text-center text-slate-400 italic font-sans">
                                                        Belum ada port interface yang terdaftar. Klik <strong>"⚡ Sinkronkan Port & Interface"</strong> di atas untuk menarik port langsung dari router MikroTik atau membuat port default.
                                                    </td>
                                                </tr>
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div className="flex justify-end px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                            <button
                                onClick={() => setViewingDevice(null)}
                                className="rounded-xl bg-blue-600 hover:bg-blue-700 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition duration-150"
                            >
                                Tutup Detail
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </CimsLayout>
    );
}
