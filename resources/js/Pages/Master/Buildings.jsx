import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function Buildings({ buildings = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingBuilding, setEditingBuilding] = useState(null);

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        code: '',
        description: ''
    });

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
        if (confirm('Are you sure you want to delete this building?')) {
            destroy(route('buildings.destroy', id));
        }
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
                                                <td className="px-3 py-4 text-sm text-brand-textSecondary max-w-md truncate">
                                                    {building.description || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
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
                                            <td colSpan="4" className="text-center py-8 text-brand-textSecondary text-sm">
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
        </AuthenticatedLayout>
    );
}
