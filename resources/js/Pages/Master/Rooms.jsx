import CimsLayout from '@/Layouts/CimsLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

/**
 * Halaman global ruangan. Karena ruangan selalu berada di dalam sebuah lantai,
 * dan lantai selalu berada di dalam sebuah gedung, pemilihan lokasi memakai
 * dropdown bertingkat Building → Floor. Untuk menambah ruangan pada satu lantai
 * tertentu, halaman detail lantai (floors.show) tidak memerlukan dropdown lagi.
 */
export default function Rooms({ rooms = [], buildings = [], filters = {} }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingRoom, setEditingRoom] = useState(null);
    const [buildingFilter, setBuildingFilter] = useState(filters.building_id || '');
    const [floorFilter, setFloorFilter] = useState(filters.floor_id || '');
    const { confirmAction } = useConfirmation();

    const { data, setData, post, delete: destroy, reset, errors, processing } = useForm({
        building_id: '',
        floor_id: '',
        name: '',
        code: '',
        description: ''
    });

    const floorsOf = (buildingId) => {
        const building = buildings.find(b => String(b.id) === String(buildingId));
        return building?.floors || [];
    };

    const hasFloors = buildings.some(building => (building.floors || []).length > 0);
    const modalFloors = floorsOf(data.building_id);
    const filterFloors = floorsOf(buildingFilter);

    // Kode ruangan mengikuti pola generator gedung: <KODE>-F<level>-R<nn>.
    const suggestRoomCode = (buildingId, floorId) => {
        const building = buildings.find(b => String(b.id) === String(buildingId));
        const floor = (building?.floors || []).find(f => String(f.id) === String(floorId));
        if (!building || !floor) return '';

        const prefix = `${String(building.code || 'BLD').toUpperCase()}-F${floor.level}-R`;
        const used = rooms
            .filter(room => String(room.floor_id) === String(floor.id))
            .map(room => String(room.code || '').toUpperCase())
            .filter(code => code.startsWith(prefix))
            .map(code => parseInt(code.slice(prefix.length), 10))
            .filter(number => Number.isInteger(number));

        const next = used.length > 0 ? Math.max(...used) + 1 : 1;
        return `${prefix}${String(next).padStart(2, '0')}`;
    };

    // Ganti gedung → daftar lantai ikut berganti, pilihan lantai lama dikosongkan
    // supaya ruangan tidak pernah menunjuk lantai di gedung lain.
    const handleBuildingChange = (buildingId) => {
        setData(prev => ({ ...prev, building_id: buildingId, floor_id: '', code: editingRoom ? prev.code : '' }));
    };

    const handleFloorChange = (floorId) => {
        setData(prev => ({
            ...prev,
            floor_id: floorId,
            code: editingRoom ? prev.code : suggestRoomCode(prev.building_id, floorId)
        }));
    };

    const applyFilters = (nextBuilding, nextFloor) => {
        const params = {};
        if (nextBuilding) params.building_id = nextBuilding;
        if (nextFloor) params.floor_id = nextFloor;

        router.get(route('rooms.index'), params, {
            preserveState: true,
            preserveScroll: true,
            replace: true
        });
    };

    const handleBuildingFilterChange = (value) => {
        setBuildingFilter(value);
        setFloorFilter('');
        applyFilters(value, '');
    };

    const handleFloorFilterChange = (value) => {
        setFloorFilter(value);
        applyFilters(buildingFilter, value);
    };

    const handleOpenCreateModal = () => {
        setEditingRoom(null);
        reset();

        const defaultBuilding = buildingFilter || (buildings.length > 0 ? buildings[0].id : '');
        const floorOptions = floorsOf(defaultBuilding);
        const defaultFloor = floorFilter && floorOptions.some(f => String(f.id) === String(floorFilter))
            ? floorFilter
            : (floorOptions.length === 1 ? floorOptions[0].id : '');

        setData(prev => ({
            ...prev,
            building_id: defaultBuilding,
            floor_id: defaultFloor,
            code: defaultFloor ? suggestRoomCode(defaultBuilding, defaultFloor) : ''
        }));
        setIsModalOpen(true);
    };

    const handleOpenEditModal = (room) => {
        setEditingRoom(room);
        setData({
            building_id: room.floor?.building_id || '',
            floor_id: room.floor_id || '',
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
                            Data Master Ruangan
                        </h2>
                        <p className="text-sm text-slate-500">
                            Setiap ruangan berada di dalam satu lantai. Pilih gedung terlebih dahulu, lalu lantainya.
                        </p>
                    </div>
                    <button
                        onClick={handleOpenCreateModal}
                        disabled={!hasFloors}
                        className="inline-flex items-center rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition duration-150 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <svg className="h-5 w-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Ruangan
                    </button>
                </div>
            }
        >
            <Head title="Master Ruangan" />
            <div className="text-slate-900">
                    {/* Breadcrumb hierarki lokasi */}
                    <nav className="mb-4 flex items-center space-x-2 text-xs font-semibold text-brand-textSecondary">
                        <Link href={route('buildings.index')} className="hover:text-brand-primary transition">Gedung</Link>
                        <span className="text-brand-textMuted">/</span>
                        <Link href={route('floors.index')} className="hover:text-brand-primary transition">Lantai</Link>
                        <span className="text-brand-textMuted">/</span>
                        <span className="text-slate-900">Ruangan</span>
                    </nav>

                    {/* Filter bertingkat Gedung → Lantai */}
                    <div className="mb-6 grid gap-4 rounded-2xl bg-brand-card border border-brand-border p-4 sm:grid-cols-2">
                        <div>
                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Filter Gedung</label>
                            <select
                                value={buildingFilter}
                                onChange={(e) => handleBuildingFilterChange(e.target.value)}
                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary"
                            >
                                <option value="">Semua Gedung</option>
                                {buildings.map((building) => (
                                    <option key={building.id} value={building.id}>
                                        {building.name} ({building.code})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Filter Lantai</label>
                            <select
                                value={floorFilter}
                                onChange={(e) => handleFloorFilterChange(e.target.value)}
                                disabled={!buildingFilter}
                                className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <option value="">{buildingFilter ? 'Semua Lantai' : 'Pilih gedung dahulu'}</option>
                                {filterFloors.map((floor) => (
                                    <option key={floor.id} value={floor.id}>
                                        {floor.name} (Level {floor.level})
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                    {/* Rooms Table */}
                    <div className="overflow-hidden rounded-2xl bg-brand-card border border-brand-border">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary">Building &amp; Floor</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Code</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Room Name</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary">Description</th>
                                        <th className="py-4 pl-3 pr-6 text-right text-xs font-bold text-brand-textSecondary">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {rooms.length > 0 ? (
                                        rooms.map((room) => (
                                            <tr key={room.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-semibold">
                                                    {room.floor_id ? (
                                                        <Link
                                                            href={route('floors.show', room.floor_id)}
                                                            className="text-brand-primary hover:underline"
                                                        >
                                                            {room.floor?.building?.name || 'Unknown Building'} - {room.floor?.name || `Floor ID: ${room.floor_id}`}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-brand-textMuted">Belum ditempatkan</span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm font-mono text-slate-900 font-bold">
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
                                            <td colSpan="5" className="text-center py-8 text-brand-textSecondary text-sm">
                                                {hasFloors
                                                    ? 'No rooms registered yet.'
                                                    : 'Belum ada lantai. Daftarkan gedung dan lantainya terlebih dahulu.'}
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
                            {/* Dropdown bertingkat: lantai baru bisa dipilih setelah gedungnya jelas */}
                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Building*</label>
                                <select
                                    required
                                    value={data.building_id}
                                    onChange={(e) => handleBuildingChange(e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary"
                                >
                                    <option value="" disabled>Select Building</option>
                                    {buildings.map((building) => (
                                        <option key={building.id} value={building.id}>
                                            {building.name} ({building.code})
                                        </option>
                                    ))}
                                </select>
                                {errors.building_id && <span className="text-xs text-red-700 mt-1 block">{errors.building_id}</span>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Floor*</label>
                                <select
                                    required
                                    value={data.floor_id}
                                    disabled={!data.building_id}
                                    onChange={(e) => handleFloorChange(e.target.value)}
                                    className="w-full rounded-xl bg-brand-bg border-brand-border text-sm text-slate-900 focus:border-brand-primary focus:ring-brand-primary disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <option value="" disabled>
                                        {data.building_id ? 'Select Floor' : 'Pilih gedung dahulu'}
                                    </option>
                                    {modalFloors.map((floor) => (
                                        <option key={floor.id} value={floor.id}>
                                            {floor.name} (Level {floor.level})
                                        </option>
                                    ))}
                                </select>
                                {data.building_id && modalFloors.length === 0 && (
                                    <span className="text-xs text-amber-700 mt-1 block">
                                        Gedung ini belum punya lantai. Tambahkan lantainya dahulu di menu Lantai.
                                    </span>
                                )}
                                {errors.floor_id && <span className="text-xs text-red-700 mt-1 block">{errors.floor_id}</span>}
                            </div>
                            <div>
                                <label className="block text-xs font-semibold text-brand-textSecondary mb-1">Room Code*</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. LAB-201"
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
                                    placeholder="e.g. Laboratorium Jaringan"
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
                                    placeholder="Describe the usage or equipment in the room..."
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
