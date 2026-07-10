import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function Buildings({ buildings = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingBuilding, setEditingBuilding] = useState(null);
    const [selectedLayoutBuilding, setSelectedLayoutBuilding] = useState(null);
    const { confirmAction } = useConfirmation();

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        code: '',
        description: '',
        floors_count: 1,
        rooms_count: 1,
        floor_rooms: [1]
    });

    const handleFloorsCountChange = (val) => {
        const count = Math.max(1, parseInt(val) || 1);
        setData(prev => {
            const newFloorRooms = [...(prev.floor_rooms || [])];
            if (newFloorRooms.length < count) {
                while (newFloorRooms.length < count) {
                    newFloorRooms.push(1);
                }
            } else if (newFloorRooms.length > count) {
                newFloorRooms.splice(count);
            }
            const totalRooms = newFloorRooms.reduce((sum, curr) => sum + (parseInt(curr) || 0), 0);
            return {
                ...prev,
                floors_count: count,
                floor_rooms: newFloorRooms,
                rooms_count: totalRooms
            };
        });
    };

    const handleFloorRoomCountChange = (index, val) => {
        const roomVal = Math.max(0, parseInt(val) || 0);
        setData(prev => {
            const newFloorRooms = [...(prev.floor_rooms || [])];
            newFloorRooms[index] = roomVal;
            const totalRooms = newFloorRooms.reduce((sum, curr) => sum + (parseInt(curr) || 0), 0);
            return {
                ...prev,
                floor_rooms: newFloorRooms,
                rooms_count: totalRooms
            };
        });
    };
//
//
//
//
//
//

    const handleOpenCreateModal = () => {
        setEditingBuilding(null);
        reset();
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (building) => {
        setEditingBuilding(building);
        setData({
            name: building.name || '',
            code: building.code || '',
            description: building.description || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingBuilding) {
            post(route('buildings.update', editingBuilding.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('buildings.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Gedung',
            message: 'Apakah Anda yakin ingin menghapus gedung ini? Semua lantai dan ruangan di dalamnya juga akan terhapus secara permanen.',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                destroy(route('buildings.destroy', id));
            }
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Buildings Master Data
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Configure physical building zones for hosting infrastructure.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md hover:bg-brand-primaryHover transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Building
                    </button>
                </div>
            }
        >
            <Head title="Buildings Master" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Buildings Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border shadow-xl">
                        <div className="overflow-x-auto">
                             <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Code</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Name</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary text-center">Total Floors</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary text-center">Total Rooms</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Description</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {buildings.length > 0 ? (
                                        buildings.map((building) => (
                                            <tr key={building.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-mono text-brand-primary font-bold">
                                                    {building.code}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-white font-semibold">
                                                    {building.name}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-white text-center font-semibold">
                                                    {building.floors_count !== undefined ? building.floors_count : 0}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-white text-center font-semibold">
                                                    {building.rooms_count !== undefined ? building.rooms_count : 0}
                                                </td>
                                                <td className="px-3 py-4 text-sm text-brand-textSecondary max-w-md truncate">
                                                    {building.description || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                             onClick={() => setSelectedLayoutBuilding(building)}
                                                             type="button"
                                                             className="rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 hover:bg-emerald-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                         >
                                                             View Layout
                                                         </button>
                                                         <button
                                                            onClick={() => handleOpenEditModal(building)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(building.id)}
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
                                                No buildings registered yet.
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
                                {editingBuilding ? 'Modify Building Info' : 'Register New Building Zone'}
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
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building Code*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. GKB-A"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.code && <span className="text-xs text-rose-450 mt-1 block">{errors.code}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building Name*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Gedung Kuliah Bersama A"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                            </div>

                            {!editingBuilding && (
                                <div className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Jumlah Lantai*</label>
                                            <input
                                                type="number"
                                                required
                                                min="1"
                                                max="50"
                                                value={data.floors_count}
                                                onChange={(e) => handleFloorsCountChange(e.target.value)}
                                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                            />
                                            {errors.floors_count && <span className="text-xs text-rose-450 mt-1 block">{errors.floors_count}</span>}
                                        </div>
                                        <div>
                                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Total Ruangan (Auto)</label>
                                            <input
                                                type="number"
                                                disabled
                                                value={data.rooms_count}
                                                className="w-full rounded-xl bg-brand-bgSecondary border-brand-border text-sm text-brand-textSecondary cursor-not-allowed font-bold"
                                            />
                                        </div>
                                    </div>

                                    {/* Custom Rooms per Floor Inputs */}
                                    <div className="bg-brand-bgSecondary/30 border border-brand-border/40 rounded-xl p-4 space-y-3">
                                        <h4 className="text-xs font-bold text-brand-primary">Tentukan Jumlah Ruangan per Lantai</h4>
                                        <div className="max-h-48 overflow-y-auto pr-1 space-y-2.5">
                                            {data.floor_rooms && data.floor_rooms.map((roomCount, idx) => (
                                                <div key={idx} className="flex items-center justify-between space-x-4 bg-brand-bg/50 border border-brand-border/30 rounded-lg p-2">
                                                    <span className="text-xs font-semibold text-white">Lantai {idx + 1}</span>
                                                    <input
                                                        type="number"
                                                        required
                                                        min="0"
                                                        max="50"
                                                        value={roomCount}
                                                        onChange={(e) => handleFloorRoomCountChange(idx, e.target.value)}
                                                        className="w-24 rounded-lg bg-brand-bg border-brand-border text-xs text-center text-white focus:border-brand-primary focus:ring-brand-primary py-1"
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            )}

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
                                    {editingBuilding ? 'Save Changes' : 'Register Zone'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
            {/* View Layout Modal */}
            {selectedLayoutBuilding && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-950/85 flex items-center justify-center p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-2xl max-h-[85vh] flex flex-col rounded-2xl bg-brand-card border border-brand-border shadow-2xl">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between px-6 py-4 border-b border-brand-border shrink-0">
                            <div>
                                <h3 className="text-lg font-bold text-white">
                                    Layout Structure: {selectedLayoutBuilding.name}
                                </h3>
                                <p className="text-xs text-brand-textSecondary mt-0.5 font-mono">
                                    Code: {selectedLayoutBuilding.code} • {selectedLayoutBuilding.floors_count} Floors • {selectedLayoutBuilding.rooms_count} Rooms
                                </p>
                            </div>
                            <button
                                onClick={() => setSelectedLayoutBuilding(null)}
                                className="text-brand-textSecondary hover:text-white transition"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="overflow-y-auto p-6 space-y-6 flex-1">
                            {selectedLayoutBuilding.floors && selectedLayoutBuilding.floors.length > 0 ? (
                                selectedLayoutBuilding.floors.map((floor) => (
                                    <div key={floor.id} className="bg-brand-bgSecondary/50 border border-brand-border/40 rounded-xl p-4">
                                        <div className="flex items-center justify-between mb-3 border-b border-brand-border/20 pb-2">
                                            <h4 className="text-sm font-bold text-brand-primary flex items-center">
                                                <svg className="h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                                </svg>
                                                {floor.name}
                                            </h4>
                                            <span className="text-[10px] bg-brand-primary/10 text-brand-primary px-2.5 py-0.5 rounded-full font-semibold">
                                                Level {floor.level}
                                            </span>
                                        </div>
                                        
                                        {/* Rooms list in this floor */}
                                        {floor.rooms && floor.rooms.length > 0 ? (
                                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                                {floor.rooms.map((room) => (
                                                    <div key={room.id} className="bg-brand-bg/60 border border-brand-border/60 rounded-lg p-3 flex items-center justify-between">
                                                        <div>
                                                            <div className="text-xs font-bold text-white">{room.name}</div>
                                                            <div className="text-[10px] text-brand-textSecondary mt-0.5 font-mono">{room.code}</div>
                                                        </div>
                                                        <span className="text-[9px] text-brand-textMuted bg-brand-card px-2 py-1 rounded border border-brand-border/30">
                                                            Room Node
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="text-xs text-brand-textMuted italic">No rooms registered on this floor.</p>
                                        )}
                                    </div>
                                ))
                            ) : (
                                <div className="text-center py-8 text-brand-textSecondary text-sm">
                                    No structural floors found for this building.
                                </div>
                            )}
                        </div>

                        {/* Modal Footer */}
                        <div className="flex justify-end px-6 py-4 border-t border-brand-border shrink-0">
                            <button
                                onClick={() => setSelectedLayoutBuilding(null)}
                                className="rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-5 py-2.5 text-sm font-bold text-slate-950 shadow transition duration-150"
                            >
                                Close Layout
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
