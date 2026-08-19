import CimsLayout from '@/Layouts/CimsLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function Vendors({ vendors = [] }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingVendor, setEditingVendor] = useState(null);
    const { confirmAction } = useConfirmation();

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        contact_person: '',
        email: '',
        phone: '',
        address: ''
    });

    const handleOpenCreateModal = () => {
        setEditingVendor(null);
        reset();
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (vendor) => {
        setEditingVendor(vendor);
        setData({
            name: vendor.name || '',
            contact_person: vendor.contact_person || '',
            email: vendor.email || '',
            phone: vendor.phone || '',
            address: vendor.address || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingVendor) {
            post(route('vendors.update', editingVendor.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('vendors.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Vendor',
            message: 'Apakah Anda yakin ingin menghapus vendor ini?',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                destroy(route('vendors.destroy', id));
            }
        });
    };

    return (
        <CimsLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            Data Master Vendor
                        </h2>
                        <p className="text-sm text-slate-500">
                            Kelola pemasok perangkat keras, vendor manufaktur, dan rincian kontak dukungan.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Vendor
                    </button>
                </div>
            }
        >
            <Head title="Master Vendor" />

            <div className="text-slate-900">
                    
                    {/* Vendors Table */}
                    <div className="overflow-hidden rounded-2xl bg-white border border-slate-200">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-slate-600">Nama Vendor</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">Kontak Person</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">Email</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">No. Telepon</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-slate-600">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {vendors.length > 0 ? (
                                        vendors.map((vendor) => (
                                            <tr key={vendor.id} className="hover:bg-slate-50/80 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-bold text-slate-900">
                                                    {vendor.name}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                    {vendor.contact_person || '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                    {vendor.email || '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600 font-mono">
                                                    {vendor.phone || '-'}
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(vendor)}
                                                            className="rounded-lg bg-blue-50 border border-blue-200 text-blue-700 hover:bg-blue-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(vendor.id)}
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
                                            <td colSpan="5" className="text-center py-8 text-slate-500 text-sm">
                                                Belum ada vendor terdaftar.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
            </div>

            {/* Create/Edit Modal */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 flex items-center justify-center p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-md rounded-2xl bg-white border border-slate-200 p-6">
                        <div className="flex items-center justify-between pb-4 border-b border-slate-200 mb-6">
                            <h3 className="text-lg font-bold text-slate-900">
                                {editingVendor ? 'Edit Data Vendor' : 'Tambah Vendor Baru'}
                            </h3>
                            <button
                                onClick={() => setIsModalOpen(false)}
                                className="text-slate-400 hover:text-slate-700 transition"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">Nama Vendor*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="Contoh: Mikrotik / Cisco / Ruijie"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                />
                                {errors.name && <span className="text-xs text-red-700 mt-1 block">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">Kontak Person (CP)</label>
                                <input
                                    type="text"
                                    placeholder="Contoh: Budi (Sales Support)"
                                    value={data.contact_person}
                                    onChange={(e) => setData('contact_person', e.target.value)}
                                    className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                />
                                {errors.contact_person && <span className="text-xs text-red-700 mt-1 block">{errors.contact_person}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">Alamat Email</label>
                                <input
                                    type="email"
                                    placeholder="Contoh: support@vendor.com"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                />
                                {errors.email && <span className="text-xs text-red-700 mt-1 block">{errors.email}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">No. Telepon / WA</label>
                                <input
                                    type="text"
                                    placeholder="Contoh: 081234567890"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600 font-mono"
                                />
                                {errors.phone && <span className="text-xs text-red-700 mt-1 block">{errors.phone}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-600 mb-1">Alamat</label>
                                <textarea
                                    value={data.address}
                                    onChange={(e) => setData('address', e.target.value)}
                                    rows="2"
                                    placeholder="Alamat kantor atau pusat perbaikan vendor..."
                                    className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                                ></textarea>
                                {errors.address && <span className="text-xs text-red-700 mt-1 block">{errors.address}</span>}
                            </div>

                            <div className="flex justify-end space-x-3 pt-4 border-t border-slate-200">
                                <button
                                    type="button"
                                    onClick={() => setIsModalOpen(false)}
                                    className="rounded-xl border border-slate-200 hover:bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-600 transition"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="rounded-xl bg-blue-600 hover:bg-blue-700 px-4 py-2.5 text-sm font-bold text-white transition duration-150"
                                >
                                    {editingVendor ? 'Simpan Perubahan' : 'Tambah Vendor'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CimsLayout>
    );
}
