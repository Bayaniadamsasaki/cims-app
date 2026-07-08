import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function Floors({ floors = [], buildings = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingFloor, setEditingFloor] = useState(null);

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        building_id: '',
        name: '',
        level: '',
        description: ''
    });

    const handleOpenCreateModal = () => {
        setEditingFloor(null);
        reset();
        if (buildings.length > 0) {
            setData('building_id', buildings[0].id);
        }
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (floor) => {
        setEditingFloor(floor);
        setData({
            building_id: floor.building_id || '',
            name: floor.name || '',
            level: floor.level !== undefined ? floor.level : '',
            description: floor.description || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingFloor) {
            post(route('floors.update', editingFloor.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('floors.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        if (confirm('Are you sure you want to delete this floor?')) {
            destroy(route('floors.destroy', id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Floors Master Data
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Configure floor levels for placement within buildings.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md hover:bg-brand-primaryHover transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Add Floor
                    </button>
                </div>
            }
        >
            <Head title="Floors Master" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Floors Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border shadow-xl">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Building</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Floor Name</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Level</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Description</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {floors.length > 0 ? (
                                        floors.map((floor) => (
                                            <tr key={floor.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-semibold text-brand-primary">
                                                    {floor.building?.name || `Building ID: ${floor.building_id}`}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-white font-semibold">
                                                    {floor.name}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    {floor.level}
                                                </td>
                                                <td className="px-3 py-4 text-sm text-brand-textSecondary max-w-md truncate">
                                                    {floor.description || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(floor)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(floor.id)}
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
                                            <td colSpan="5" className="text-center py-8 text-brand-textSecondary text-sm">
                                                No floors registered yet.
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
                                {editingFloor ? 'Modify Floor Info' : 'Register New Floor'}
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
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building*</label>
                                <select
                                    required
                                    value={data.building_id}
                                    onChange={(e) => setData('building_id', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                >
                                    <option value="" disabled>Select Building</option>
                                    {buildings.map((building) => (
                                        <option key={building.id} value={building.id}>
                                            {building.name} ({building.code})
                                        </option>
                                    ))}
                                </select>
                                {errors.building_id && <span className="text-xs text-rose-450 mt-1 block">{errors.building_id}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Floor Name*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Lantai 1"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Level (Numeric)*</label>
                                <input
                                    type="number"
                                    required
                                    placeholder="e.g. 1"
                                    value={data.level}
                                    onChange={(e) => setData('level', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.level && <span className="text-xs text-rose-450 mt-1 block">{errors.level}</span>}
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
                                    {editingFloor ? 'Save Changes' : 'Register Floor'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
