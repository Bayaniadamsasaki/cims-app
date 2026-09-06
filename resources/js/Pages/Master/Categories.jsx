import CimsLayout from '@/Layouts/CimsLayout';
import Modal from '@/Components/Modal';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function Categories({ categories = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingCategory, setEditingCategory] = useState(null);
    const { confirmAction } = useConfirmation();

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        description: ''
    });

    const handleOpenCreateModal = () => {
        setEditingCategory(null);
        reset();
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (category) => {
        setEditingCategory(category);
        setData({
            name: category.name || '',
            description: category.description || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingCategory) {
            post(route('device-categories.update', editingCategory.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('device-categories.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Kategori',
            message: 'Apakah Anda yakin ingin menghapus kategori perangkat ini?',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                destroy(route('device-categories.destroy', id));
            }
        });
    };

    return (
        <CimsLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            Kategori Perangkat
                        </h2>
                        <p className="text-sm text-slate-500">
                            Klasifikasi node perangkat keras (contoh: Router, Switch, Server, Access Point).
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Kategori
                    </button>
                </div>
            }
        >
            <Head title="Kategori Perangkat" />

            <div className="text-slate-900">
                    
                    {/* Categories Table */}
                    <div className="overflow-hidden rounded-2xl bg-white border border-slate-200">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-slate-600">Nama Kategori</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">Deskripsi</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-slate-600">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {categories.length > 0 ? (
                                        categories.map((category) => (
                                            <tr key={category.id} className="hover:bg-slate-50/80 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-bold text-slate-900">
                                                    {category.name}
                                                </td>
                                                <td className="px-3 py-4 text-sm text-slate-600 max-w-md truncate">
                                                    {category.description || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(category)}
                                                            className="rounded-lg bg-blue-50 border border-blue-200 text-blue-700 hover:bg-blue-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(category.id)}
                                                            className="rounded-lg bg-rose-50 border border-rose-200 text-rose-700 hover:bg-rose-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Hapus
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="3" className="text-center py-8 text-slate-500 text-sm">
                                                Belum ada kategori terdaftar.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
            </div>

            {/* Create/Edit Modal */}
            <Modal
                show={isModalOpen}
                onClose={() => setIsModalOpen(false)}
                maxWidth="md"
                title={editingCategory ? 'Edit Kategori' : 'Tambah Kategori Baru'}
                footer={
                    <div className="flex justify-end gap-3">
                        <button
                            type="button"
                            onClick={() => setIsModalOpen(false)}
                            className="rounded-xl border border-slate-200 hover:bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-600 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            form="category-form"
                            disabled={processing}
                            className="rounded-xl bg-blue-600 hover:bg-blue-700 px-4 py-2.5 text-sm font-bold text-white transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                        >
                            {editingCategory ? 'Simpan Perubahan' : 'Tambah Kategori'}
                        </button>
                    </div>
                }
            >
                {/* Tombol simpan berada di footer sticky di luar <form>, jadi ia
                    ditautkan lewat atribut `form` agar Enter dan klik tetap mengirim. */}
                <form id="category-form" onSubmit={handleSubmit} className="space-y-4 px-5 py-5 sm:px-6">
                    <div>
                        <label htmlFor="category-name" className="block text-xs font-semibold text-slate-600 mb-1">Nama Kategori*</label>
                        <input
                            id="category-name"
                            type="text"
                            required
                            placeholder="Contoh: Router / Switch / Access Point"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                        {errors.name && <span className="text-xs text-red-700 mt-1 block">{errors.name}</span>}
                    </div>

                    <div>
                        <label htmlFor="category-description" className="block text-xs font-semibold text-slate-600 mb-1">Deskripsi</label>
                        <textarea
                            id="category-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            rows="3"
                            placeholder="Penjelasan singkat mengenai kategori..."
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        ></textarea>
                        {errors.description && <span className="text-xs text-red-700 mt-1 block">{errors.description}</span>}
                    </div>
                </form>
            </Modal>
        </CimsLayout>
    );
}
