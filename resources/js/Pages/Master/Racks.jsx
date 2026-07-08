import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Racks({ racks = [], rooms = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingRack, setEditingRack] = useState(null);

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        room_id: '',
        name: '',
        code: '',
        height_u: '',
        description: ''
    });

    const handleOpenCreateModal = () => {
        setEditingRack(null);
        reset();
        if (rooms.length > 0) {
            setData('room_id', rooms[0].id);
        }
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (rack) => {
        setEditingRack(rack);
        setData({
            room_id: rack.room_id || '',
            name: rack.name || '',
            code: rack.code || '',
            height_u: rack.height_u !== undefined ? rack.height_u : '',
            description: rack.description || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingRack) {
            post(route('racks.update', editingRack.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('racks.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        if (confirm('Are you sure you want to delete this rack?')) {
            destroy(route('racks.destroy', id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Server Racks Master Data
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Configure physical cabinets/racks and their U-heights.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md hover:bg-brand-primaryHover transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Rack
                    </button>
                </div>
            }
        >
            <Head title="Server Racks Master" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Racks Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border shadow-xl">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Room Location</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Code</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Rack Name</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Height (U)</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Description</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {racks.length > 0 ? (
                                        racks.map((rack) => (
                                            <tr key={rack.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-semibold text-brand-primary">
                                                    {rack.room?.floor?.building?.code || 'BLDG'} - {rack.room?.code || `Room ID: ${rack.room_id}`}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm font-mono text-white font-bold">
                                                    {rack.code}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-white font-semibold">
                                                    {rack.name}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    {rack.height_u} U
                                                </td>
                                                <td className="px-3 py-4 text-sm text-brand-textSecondary max-w-md truncate">
                                                    {rack.description || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(rack)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(rack.id)}
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
                                                No racks registered yet.
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
                    <div className="relative w-full max-w-md rounded-2xl bg-brand-card border border-brand-border p-6 shadow-2xl">
                        <div className="flex items-center justify-between pb-4 border-b border-brand-border mb-6">
                            <h3 className="text-lg font-bold text-white">
                                {editingRack ? 'Modify Rack Info' : 'Register New Cabinet Rack'}
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

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Room*</label>
                                <select
                                    required
                                    value={data.room_id}
                                    onChange={(e) => setData('room_id', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                >
                                    <option value="" disabled>Select Room</option>
                                    {rooms.map((room) => (
                                        <option key={room.id} value={room.id}>
                                            {room.floor?.building?.name || 'Building'} - Floor {room.floor?.name} - Room {room.name} ({room.code})
                                        </option>
                                    ))}
                                </select>
                                {errors.room_id && <span className="text-xs text-rose-450 mt-1 block">{errors.room_id}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Rack Code*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. RK-01"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.code && <span className="text-xs text-rose-450 mt-1 block">{errors.code}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Rack Name*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Cabinet Rack A"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Height (U-Size)*</label>
                                <input
                                    type="number"
                                    required
                                    placeholder="e.g. 42"
                                    value={data.height_u}
                                    onChange={(e) => setData('height_u', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.height_u && <span className="text-xs text-rose-450 mt-1 block">{errors.height_u}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Description</label>
                                <textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows="3"
                                    placeholder="Describe the usage or location details..."
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                ></textarea>
                                {errors.description && <span className="text-xs text-rose-450 mt-1 block">{errors.description}</span>}
                            </div>

                            <div className="flex justify-end space-x-3 pt-4 border-t border-brand-border">
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
                                    {editingRack ? 'Save Changes' : 'Register Cabinet'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
