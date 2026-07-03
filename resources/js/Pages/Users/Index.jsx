import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';

export default function UsersIndex({ users = [], roles = [], filters = {} }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState(null);

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
        if (confirm('Are you sure you want to delete this user?')) {
            destroy(route('users.destroy', id));
        }
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            User Management
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Configure user credentials, roles, and resource access levels.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-brand-primary px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md hover:bg-brand-primaryHover transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                        Add User
                    </button>
                </div>
            }
        >
            <Head title="User Management" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">
                    
                    {/* Users Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border shadow-xl">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Name</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Email Address</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Assigned Role</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {users.length > 0 ? (
                                        users.map((user) => (
                                            <tr key={user.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm">
                                                    <div className="flex items-center space-x-3">
                                                        <div className="h-9 w-9 rounded-xl bg-brand-primary/10 border border-brand-primary/20 flex items-center justify-center text-brand-primary font-black">
                                                            {user.name.charAt(0).toUpperCase()}
                                                        </div>
                                                        <div className="font-bold text-white">{user.name}</div>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    {user.email}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span className={`inline-flex items-center rounded-md px-2.5 py-0.5 text-xs font-semibold border ${
                                                        user.roles?.[0] === 'Super Admin'
                                                            ? 'bg-rose-500/10 text-rose-450 border-rose-500/30'
                                                            : user.roles?.[0] === 'Network Administrator'
                                                            ? 'bg-brand-primary/10 text-brand-primary border-brand-primary/30'
                                                            : 'bg-brand-bgSecondary text-brand-textSecondary border-brand-border'
                                                    }`}>
                                                        {user.roles?.[0] || 'No Role'}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                    <div className="flex justify-end space-x-2">
                                                        <button
                                                            onClick={() => handleOpenEditModal(user)}
                                                            className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-slate-950 px-3 py-1.5 text-xs font-semibold transition"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            onClick={() => handleDelete(user.id)}
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
                                                No users found.
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
                                {editingUser ? 'Modify User Settings' : 'Register New User Accounts'}
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
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Full Name*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Administrator"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.name && <span className="text-xs text-rose-450 mt-1 block">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Email Address*</label>
                                <input
                                    type="email"
                                    required
                                    placeholder="e.g. name@cims.com"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.email && <span className="text-xs text-rose-450 mt-1 block">{errors.email}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">
                                    Password {editingUser && '(Leave empty to keep current)'}
                                </label>
                                <input
                                    type="password"
                                    required={!editingUser}
                                    placeholder="••••••••"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.password && <span className="text-xs text-rose-450 mt-1 block">{errors.password}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Confirm Password</label>
                                <input
                                    type="password"
                                    required={!editingUser && data.password}
                                    placeholder="••••••••"
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Security Role*</label>
                                <select
                                    required
                                    value={data.role}
                                    onChange={(e) => setData('role', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-white focus:border-brand-primary focus:ring-brand-primary"
                                >
                                    <option value="">Select Role</option>
                                    {roles.map((r, idx) => <option key={idx} value={r}>{r}</option>)}
                                </select>
                                {errors.role && <span className="text-xs text-rose-450 mt-1 block">{errors.role}</span>}
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
                                    {editingUser ? 'Save Changes' : 'Register User'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
