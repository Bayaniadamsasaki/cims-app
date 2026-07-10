import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Index({ devices = [], vendors = [], categories = [], buildings = [], floors = [], rooms = [], racks = [], filters = {} }) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [selectedCategory, setSelectedCategory] = useState(filters.device_category_id || '');
    const [selectedBuilding, setSelectedBuilding] = useState(filters.building_id || '');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || '');

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingDevice, setEditingDevice] = useState(null);

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
        if (confirm('Are you sure you want to delete this device?')) {
            destroy(route('devices.destroy', id));
        }
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
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Device</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Hostname / IP</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Category</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Location Details</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Status</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {devices.length > 0 ? (
                                        devices.map((device) => (
                                            <tr key={device.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                    <div className="flex items-center space-x-3">
                                                        {device.image_path ? (
                                                            <img 
                                                                src={`/storage/${device.image_path}`} 
                                                                alt={device.name} 
                                                                className="h-10 w-10 rounded-lg object-cover border border-brand-border"
                                                            />
                                                        ) : (
                                                            <div className="h-10 w-10 rounded-lg bg-brand-primary/10 flex items-center justify-center text-brand-primary border border-brand-primary/20 font-black text-xs">
                                                                Dev
                                                            </div>
                                                        )}
                                                        <div>
                                                            <div className="font-bold text-white">{device.name}</div>
                                                            <div className="text-xs text-brand-textSecondary mt-0.5">{device.model || 'Generic Model'}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div className="font-mono text-xs text-white">{device.ip_address || '-'}</div>
                                                    <div className="text-xs text-brand-textMuted mt-0.5">{device.hostname || '-'}</div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    {categories.find(c => c.id === device.device_category_id)?.name || 'Unassigned'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div>{buildings.find(b => b.id === device.building_id)?.name || '-'}</div>
                                                    <div className="text-xs text-brand-textMuted mt-0.5">
                                                        {floors.find(f => f.id === device.floor_id)?.name || ''} 
                                                        {rooms.find(r => r.id === device.room_id) ? ` • ${rooms.find(r => r.id === device.room_id).name}` : ''}
                                                    </div>
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
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(device)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(device.id)}
                                                            className="rounded-lg bg-rose-500/10 border border-rose-500/20 text-rose-450 hover:bg-rose-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
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
                    <div className="relative w-full max-w-3xl max-h-[90vh] flex flex-col rounded-2xl bg-brand-card border border-brand-border shadow-2xl">
                        {/* Modal Header — fixed */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-brand-border shrink-0">
                            <h3 className="text-lg font-bold text-white">
                                {editingDevice ? 'Modify Network Node' : 'Register New Device Node'}
                            </h3>
                            <button
                                onClick={() => setIsModalOpen(false)}
                                className="text-brand-textSecondary hover:text-white transition"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Modal Body — scrollable */}
                        <form onSubmit={handleSubmit} className="flex flex-col flex-1 min-h-0">
                            <div className="overflow-y-auto px-6 py-5 space-y-4 flex-1">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Device Name*</label>
                                        <input
                                            type="text"
                                            required
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Hostname</label>
                                        <input
                                            type="text"
                                            value={data.hostname}
                                            onChange={(e) => setData('hostname', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.hostname && <span className="text-xs text-rose-450 mt-1 block">{errors.hostname}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">IP Address</label>
                                        <input
                                            type="text"
                                            value={data.ip_address}
                                            onChange={(e) => setData('ip_address', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.ip_address && <span className="text-xs text-rose-450 mt-1 block">{errors.ip_address}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">MAC Address</label>
                                        <input
                                            type="text"
                                            value={data.mac_address}
                                            onChange={(e) => setData('mac_address', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.mac_address && <span className="text-xs text-rose-450 mt-1 block">{errors.mac_address}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Vendor*</label>
                                        <select
                                            required
                                            value={data.vendor_id}
                                            onChange={(e) => setData('vendor_id', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        >
                                            <option value="">Select Vendor</option>
                                            {vendors.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}
                                        </select>
                                        {errors.vendor_id && <span className="text-xs text-rose-450 mt-1 block">{errors.vendor_id}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Category*</label>
                                        <select
                                            required
                                            value={data.device_category_id}
                                            onChange={(e) => setData('device_category_id', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        >
                                            <option value="">Select Category</option>
                                            {categories.map(c => <option key={c.id} value={c.id}>{c.name}</option>)}
                                        </select>
                                        {errors.device_category_id && <span className="text-xs text-rose-450 mt-1 block">{errors.device_category_id}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Model</label>
                                        <input
                                            type="text"
                                            value={data.model}
                                            onChange={(e) => setData('model', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.model && <span className="text-xs text-rose-450 mt-1 block">{errors.model}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Serial Number</label>
                                        <input
                                            type="text"
                                            value={data.serial_number}
                                            onChange={(e) => setData('serial_number', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        />
                                        {errors.serial_number && <span className="text-xs text-rose-450 mt-1 block">{errors.serial_number}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building*</label>
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
                                            <option value="">Select Building</option>
                                            {buildings.map(b => <option key={b.id} value={b.id}>{b.name}</option>)}
                                        </select>
                                        {errors.building_id && <span className="text-xs text-rose-450 mt-1 block">{errors.building_id}</span>}
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Floor</label>
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
                                            <option value="">Select Floor</option>
                                            {floors.filter(f => f.building_id == data.building_id).map(f => (
                                                <option key={f.id} value={f.id}>{f.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Room</label>
                                        <select
                                            value={data.room_id}
                                            onChange={(e) => {
                                                const rId = e.target.value;
                                                setData(prev => ({ ...prev, room_id: rId, rack_id: '' }));
                                            }}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        >
                                            <option value="">Select Room</option>
                                            {rooms.filter(r => r.floor_id == data.floor_id).map(r => (
                                                <option key={r.id} value={r.id}>{r.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Operating Status</label>
                                        <select
                                            value={data.status}
                                            onChange={(e) => setData('status', e.target.value)}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                        >
                                            <option value="active">Active / Online</option>
                                            <option value="maintenance">Under Maintenance</option>
                                            <option value="offline">Offline / Down</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Device Node Image</label>
                                        <input
                                            type="file"
                                            onChange={(e) => setData('image', e.target.files[0])}
                                            className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-brand-textSecondary file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-brand-primary/10 file:text-brand-primary hover:file:bg-brand-primary/20"
                                        />
                                        {errors.image && <span className="text-xs text-rose-450 mt-1 block">{errors.image}</span>}
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Operational Notes</label>
                                    <textarea
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        rows="2"
                                        className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                    ></textarea>
                                </div>
                            </div>

                            {/* Modal Footer — fixed */}
                            <div className="flex justify-end space-x-3 px-6 py-4 border-t border-brand-border shrink-0">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="rounded-xl border border-brand-border hover:bg-brand-bgSecondary px-4 py-2.5 text-sm font-semibold text-brand-textSecondary transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-4 py-2.5 text-sm font-bold text-slate-950 shadow transition duration-150"
                                >
                                    {editingDevice ? 'Save Changes' : 'Register Node'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
