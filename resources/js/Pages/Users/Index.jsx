import CimsLayout from '@/Layouts/CimsLayout';
import Modal from '@/Components/Modal';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function UsersIndex({ users = [], roles = [], filters = {} }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);
    const { confirmAction } = useConfirmation();

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: ''
    });

    const handleOpenCreateModal = () => {
        setEditingUser(null);
        reset();
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (user) => {
        setEditingUser(user);
        setData({
            name: user.name || '',
            email: user.email || '',
            password: '',
            password_confirmation: '',
            role: user.roles?.[0] || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (editingUser) {
            post(route('users.update', editingUser.id), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        } else {
            post(route('users.store'), {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                }
            });
        }
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Pengguna',
            message: 'Apakah Anda yakin ingin menghapus pengguna ini?',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                destroy(route('users.destroy', id));
            }
        });
    };

    return (
        <CimsLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            Manajemen Pengguna
                        </h2>
                        <p className="text-sm text-slate-500">
                            Konfigurasi kredensial pengguna, peran, dan tingkat akses sumber daya.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700 transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                        Tambah Pengguna
                    </button>
                </div>
            }
        >
            <Head title="Manajemen Pengguna" />

            <div className="text-slate-900">
                    
                    {/* Users Table */}
                    <div className="overflow-hidden rounded-2xl bg-white border border-slate-200">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-left">
                                <thead className="bg-slate-50">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-slate-600">Nama Pengguna</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">Alamat Email</th>
                                        <th className="px-3 py-4 text-xs font-bold text-slate-600">Peran Sistem</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-slate-600">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {users.length > 0 ? (
                                        users.map((user) => (
                                            <tr key={user.id} className="hover:bg-slate-50/80 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                    <div className="flex items-center space-x-3">
                                                        <div className="h-9 w-9 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-700 font-bold">
                                                            {user.name.charAt(0).toUpperCase()}
                                                        </div>
                                                        <div className="font-bold text-slate-900">{user.name}</div>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-600">
                                                    {user.email}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold border ${
                                                        user.roles?.[0] === 'Super Admin'
                                                            ? 'bg-rose-50 text-rose-700 border-rose-200'
                                                            : user.roles?.[0] === 'Network Administrator'
                                                            ? 'bg-blue-50 text-blue-700 border-blue-200'
                                                            : 'bg-slate-100 text-slate-700 border-slate-200'
                                                    }`}>
                                                        {user.roles?.[0] || 'No Role'}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(user)}
                                                            className="rounded-lg bg-blue-50 border border-blue-200 text-blue-700 hover:bg-blue-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(user.id)}
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
                                            <td colSpan="4" className="text-center py-8 text-slate-500 text-sm">
                                                Tidak ada pengguna ditemukan.
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
                title={editingUser ? 'Edit Pengguna' : 'Tambah Pengguna Baru'}
                footer={
                    <div className="flex justify-end space-x-3">
                        <button
                            type="button"
                            onClick={() => setIsModalOpen(false)}
                            className="rounded-xl border border-slate-200 hover:bg-slate-50 px-4 py-2.5 text-sm font-semibold text-slate-600 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                        >
                            Batal
                        </button>
                        <button
                            type="submit"
                            form="user-form"
                            disabled={processing}
                            className="rounded-xl bg-blue-600 hover:bg-blue-700 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                        >
                            {editingUser ? 'Simpan Perubahan' : 'Daftarkan Pengguna'}
                        </button>
                    </div>
                }
            >
                {/* Tombol simpan berada di footer sticky di luar <form>, jadi ia
                    ditautkan lewat atribut `form` agar Enter dan klik tetap mengirim. */}
                <form id="user-form" onSubmit={handleSubmit} className="space-y-4 px-5 py-5 sm:px-6">
                    <div>
                        <label htmlFor="user-name" className="block text-xs font-semibold text-slate-600 mb-1">Nama Lengkap*</label>
                        <input
                            id="user-name"
                            type="text"
                            required
                            placeholder="Contoh: Administrator"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                        {errors.name && <span className="text-xs text-red-700 mt-1 block">{errors.name}</span>}
                    </div>

                    <div>
                        <label htmlFor="user-email" className="block text-xs font-semibold text-slate-600 mb-1">Alamat Email*</label>
                        <input
                            id="user-email"
                            type="email"
                            required
                            placeholder="Contoh: admin@ubg.ac.id"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                        {errors.email && <span className="text-xs text-red-700 mt-1 block">{errors.email}</span>}
                    </div>

                    <div>
                        <label htmlFor="user-password" className="block text-xs font-semibold text-slate-600 mb-1">
                            Password {editingUser && '(Kosongkan jika tidak diubah)'}
                        </label>
                        <input
                            id="user-password"
                            type="password"
                            required={!editingUser}
                            placeholder="••••••••"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                        {errors.password && <span className="text-xs text-red-700 mt-1 block">{errors.password}</span>}
                    </div>

                    <div>
                        <label htmlFor="user-password-confirmation" className="block text-xs font-semibold text-slate-600 mb-1">Konfirmasi Password</label>
                        <input
                            id="user-password-confirmation"
                            type="password"
                            required={!editingUser && data.password}
                            placeholder="••••••••"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        />
                    </div>

                    <div>
                        <label htmlFor="user-role" className="block text-xs font-semibold text-slate-600 mb-1">Peran Akses*</label>
                        <select
                            id="user-role"
                            required
                            value={data.role}
                            onChange={(e) => setData('role', e.target.value)}
                            className="w-full rounded-xl bg-slate-50 border-slate-200 text-sm text-slate-800 focus:bg-white focus:border-blue-600 focus:ring-blue-600"
                        >
                            <option value="" className="bg-white text-slate-800">Pilih Peran</option>
                            {roles.map((r, idx) => <option key={idx} value={r} className="bg-white text-slate-800">{r}</option>)}
                        </select>
                        {errors.role && <span className="text-xs text-red-700 mt-1 block">{errors.role}</span>}
                    </div>
                </form>
            </Modal>
        </CimsLayout>
    );
}
