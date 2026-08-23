import CimsLayout from '@/Layouts/CimsLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

/**
 * Detail satu lantai. Ruangan ditambahkan langsung ke lantai ini, jadi tidak ada
 * dropdown lantai di modal — floor_id sudah ditentukan oleh halaman.
 */
export default function FloorDetail({ floor }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingRoom, setEditingRoom] = useState(null);
    const { confirmAction } = useConfirmation();

    const rooms = floor.rooms || [];
    const building = floor.building || null;

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        floor_id: floor.id,
        name: '',
        code: '',
        description: ''
    });

    // Kode ruangan mengikuti pola generator gedung: <KODE>-F<level>-R<nn>.
    const suggestRoomCode = () => {
        const prefix = `${(building?.code || 'BLD').toUpperCase()}-F${floor.level}-R`;
        const used = rooms
            .map(room => String(room.code || '').toUpperCase())
            .filter(code => code.startsWith(prefix))
            .map(code => parseInt(code.slice(prefix.length), 10))
            .filter(number => Number.isInteger(number));

        const next = used.length > 0 ? Math.max(...used) + 1 : 1;
        return `${prefix}${String(next).padStart(2, '0')}`;
    };

    const handleOpenCreateModal = () => {
        setEditingRoom(null);
        reset();
        setData(prev => ({ ...prev, floor_id: floor.id, code: suggestRoomCode() }));
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (room) => {
        setEditingRoom(room);
        setData({
            floor_id: floor.id,
            name: room.name || '',
            code: room.code || '',
            description: room.description || ''
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const target = editingRoom
            ? route('rooms.update', editingRoom.id)
            : route('rooms.store');

        post(target, {
            preserveScroll: true,
            onSuccess: () => {
                setIsModalOpen(false);
                reset();
            }
        });
    };

    const handleDelete = (room) => {
        confirmAction({
            title: 'Hapus Ruangan',
            message: `Hapus ${room.name}? Rak di dalamnya akan terhapus dan penempatan perangkat di ruangan ini akan dilepas.`,
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => destroy(route('rooms.destroy', room.id), { preserveScroll: true })
        });
    };

    return (
        <CimsLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                            {floor.name}
                        </h2>
                        <p className="text-sm text-slate-500">
                            {building ? `${building.name} (${building.code})` : 'Gedung tidak diketahui'} · Level {floor.level}
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition duration-150"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Ruangan
                    </button>
                </div>
            }
        >
            <Head title={`${floor.name} - ${building?.name || 'Lantai'}`} />
            <div className="text-slate-900">
                {/* Breadcrumb hierarki lokasi: gedung → lantai ini */}
                <nav className="mb-4 flex flex-wrap items-center gap-x-2 text-xs font-semibold text-brand-textSecondary">
                    <Link href={route('buildings.index')} className="hover:text-brand-primary transition">Gedung</Link>
                    <span className="text-brand-textMuted">/</span>
                    <Link
                        href={route('floors.index', building ? { building_id: building.id } : {})}
                        className="hover:text-brand-primary transition"
                    >
                        {building?.name || 'Lantai'}
                    </Link>
                    <span className="text-brand-textMuted">/</span>
                    <span className="text-slate-900">{floor.name}</span>
                </nav>

                {/* Ringkasan lantai */}
                <div className="mb-6 grid gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl bg-brand-card border border-brand-border p-4">
                        <p className="text-xs font-semibold text-brand-textSecondary">Gedung</p>
                        <p className="mt-1 text-sm font-bold text-slate-900">{building?.name || '-'}</p>
                        <p className="text-xs text-brand-textMuted">{building?.code || '-'}</p>
                    </div>
                    <div className="rounded-2xl bg-brand-card border border-brand-border p-4">
                        <p className="text-xs font-semibold text-brand-textSecondary">Level</p>
                        <p className="mt-1 text-sm font-bold text-slate-900">Level {floor.level}</p>
                        <p className="text-xs text-brand-textMuted">{floor.name}</p>
                    </div>
                    <div className="rounded-2xl bg-brand-card border border-brand-border p-4">
                        <p className="text-xs font-semibold text-brand-textSecondary">Total Ruangan</p>
                        <p className="mt-1 text-sm font-bold text-slate-900">{rooms.length}</p>
                        <p className="text-xs text-brand-textMuted">Ruangan pada lantai ini</p>
                    </div>
                </div>
                {/* Rooms Table */}
                <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-brand-border text-left">
                            <thead className="bg-brand-bgSecondary/40">
                                <tr>
                                    <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Code</th>
                                    <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Room Name</th>
                                    <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Description</th>
                                    <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-brand-border/60">
                                {rooms.length > 0 ? (
                                    rooms.map((room) => (
                                        <tr key={room.id} className="hover:bg-brand-bgSecondary/30 transition">
                                            <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-semibold text-brand-primary">
                                                {room.code}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-4 text-sm text-slate-900 font-semibold">
                                                {room.name}
                                            </td>
                                            <td className="px-3 py-4 text-sm text-brand-textSecondary max-w-md truncate">
                                                {room.description || '-'}
                                            </td>
                                            <td className="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm font-medium">
                                                <div className="flex justify-end space-x-2">
                                                    <button
                                                        onClick={() => handleOpenEditModal(room)}
                                                        className="rounded-lg bg-brand-primary/10 border border-brand-primary/20 text-brand-primary hover:bg-brand-primary hover:text-white px-3 py-1.5 text-xs font-semibold transition"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(room)}
                                                        className="rounded-lg bg-rose-500/10 border border-rose-500/20 text-red-700 hover:bg-rose-600 hover:text-white px-3 py-1.5 text-xs font-semibold transition"
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
                                            Belum ada ruangan di lantai ini. Klik "Tambah Ruangan" untuk mulai mengisi.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            {/* Create/Edit Room Modal — lantai sudah pasti, tidak ada dropdown lantai */}
            {isModalOpen && (
                <div className="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 flex items-center justify-center p-4 backdrop-blur-md">
                    <div className="relative w-full max-w-md rounded-2xl bg-brand-card border border-brand-border p-6">
                        <div className="flex items-center justify-between pb-4 border-b border-brand-border mb-6">
                            <h3 className="text-lg font-bold text-slate-900">
                                {editingRoom ? 'Modify Room Info' : 'Register New Room'}
                            </h3>
                            <button
                                onClick={() => setIsModalOpen(false)}
                                className="text-brand-textSecondary hover:text-slate-900 transition"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form onSubmit={handleSubmit} className="space-y-4">
                            {/* Lokasi terkunci: ruangan ini pasti milik lantai yang sedang dibuka */}
                            <div className="rounded-xl bg-brand-bgSecondary/40 border border-brand-border px-4 py-3">
                                <p className="text-xs font-semibold text-brand-textSecondary">Lokasi</p>
                                <p className="mt-0.5 text-sm font-semibold text-slate-900">
                                    {building?.name || 'Gedung'} · {floor.name}
                                </p>
                                <p className="text-[11px] text-brand-textMuted">
                                    Ruangan otomatis masuk ke lantai ini (Level {floor.level}).
                                </p>
                                {errors.floor_id && <span className="text-xs text-red-700 mt-1 block">{errors.floor_id}</span>}
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Room Code*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. REK-F1-R01"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.code && <span className="text-xs text-red-700 mt-1 block">{errors.code}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Room Name*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Ruang Server"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary"
                                />
                                {errors.name && <span className="text-xs text-red-700 mt-1 block">{errors.name}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Description</label>
                                <textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    rows="3"
                                    placeholder="Describe the usage or location details..."
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary"
                                ></textarea>
                                {errors.description && <span className="text-xs text-red-700 mt-1 block">{errors.description}</span>}
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
                                    className="rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-4 py-2.5 text-sm font-bold text-white transition duration-150"
                                >
                                    {editingRoom ? 'Save Changes' : 'Register Room'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </CimsLayout>
    );
}
