import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirmation } from '@/Components/ConfirmationModal';

export default function Index({ tickets = [], devices = [], technicians = [] }) {
    const [isCreateOpen, setIsCreateOpen] = useState(false);
    const [isEditOpen, setIsEditOpen] = useState(false);
    const [activeTicket, setActiveTicket] = useState(null);
    const [filterStatus, setFilterStatus] = useState('all');
    const { confirmAction } = useConfirmation();

    // Stats calculations
    const totalTickets = tickets.length;
    const pendingTickets = tickets.filter(t => t.status === 'pending').length;
    const ongoingTickets = tickets.filter(t => t.status === 'in_progress').length;
    const completedTickets = tickets.filter(t => t.status === 'completed').length;

    // Create Form
    const createForm = useForm({
        device_id: devices[0]?.id || '',
        technician_id: technicians[0]?.id || '',
        title: '',
        description: '',
        status: 'pending',
        scheduled_at: '',
        notes: '',
    });

    // Edit Form
    const editForm = useForm({
        device_id: '',
        technician_id: '',
        title: '',
        description: '',
        status: 'pending',
        scheduled_at: '',
        notes: '',
        attachment: null,
    });

    const handleCreateSubmit = (e) => {
        e.preventDefault();
        createForm.post(route('maintenance.store'), {
            onSuccess: () => {
                setIsCreateOpen(false);
                createForm.reset();
            }
        });
    };

    const handleEditOpen = (ticket) => {
        setActiveTicket(ticket);
        editForm.setData({
            device_id: ticket.device_id,
            technician_id: ticket.technician_id || '',
            title: ticket.title,
            description: ticket.description || '',
            status: ticket.status,
            scheduled_at: ticket.scheduled_at ? ticket.scheduled_at.substring(0, 16) : '',
            notes: ticket.notes || '',
            attachment: null,
        });
        setIsEditOpen(true);
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        // Use POST with a custom endpoint to support file uploads on update
        editForm.post(route('maintenance.update', activeTicket.id), {
            onSuccess: () => {
                setIsEditOpen(false);
                editForm.reset();
            }
        });
    };

    const handleDelete = (id) => {
        confirmAction({
            title: 'Hapus Tiket Pemeliharaan',
            message: 'Apakah Anda yakin ingin menghapus tiket pemeliharaan ini?',
            confirmLabel: 'Hapus',
            cancelLabel: 'Batal',
            variant: 'danger',
            onConfirm: () => {
                router.delete(route('maintenance.destroy', id));
            }
        });
    };

    const filteredTickets = filterStatus === 'all' 
        ? tickets 
        : tickets.filter(t => t.status === filterStatus);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-2xl font-bold tracking-tight text-white">
                            Maintenance Tickets
                        </h2>
                        <p className="text-sm text-brand-textSecondary">
                            Schedule routines, assign tasks, log resolution history, and upload ticket attachments.
                        </p>
                    </div>
                    <button
                        onClick={() => setIsCreateOpen(true)}
                        className="inline-flex items-center rounded-xl bg-brand-primary hover:bg-brand-primaryHover px-4 py-2.5 text-sm font-bold text-slate-950 shadow-md transition duration-150"
                    >
                        + Create Ticket
                    </button>
                </div>
            }
        >
            <Head title="Maintenance Tickets" />

            <div className="min-h-screen bg-brand-bg pb-16 text-white">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 pt-8">

                    {/* KPI Summary Cards */}
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-4 mb-8">
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary uppercase font-bold tracking-wider">Total Tasks</div>
                            <div className="text-3xl font-extrabold text-white mt-1">{totalTickets}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary uppercase font-bold tracking-wider">Pending Approval</div>
                            <div className="text-3xl font-extrabold text-amber-500 mt-1">{pendingTickets}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary uppercase font-bold tracking-wider">In Progress</div>
                            <div className="text-3xl font-extrabold text-sky-400 mt-1">{ongoingTickets}</div>
                        </div>
                        <div className="rounded-2xl bg-brand-card border border-brand-border p-5 shadow-lg">
                            <div className="text-xs text-brand-textSecondary uppercase font-bold tracking-wider">Completed</div>
                            <div className="text-3xl font-extrabold text-brand-primary mt-1">{completedTickets}</div>
                        </div>
                    </div>

                    {/* Filter Navigation */}
                    <div className="flex space-x-2 mb-6">
                        {['all', 'pending', 'in_progress', 'completed'].map((status) => (
                            <button
                                key={status}
                                onClick={() => setFilterStatus(status)}
                                className={`rounded-xl px-4 py-2 text-xs font-bold border transition ${
                                    filterStatus === status
                                        ? 'bg-brand-primary/10 border-brand-primary text-brand-primary'
                                        : 'bg-brand-card border-brand-border text-brand-textSecondary hover:text-white hover:border-brand-textMuted'
                                }`}
                            >
                                {status.toUpperCase().replace('_', ' ')}
                            </button>
                        ))}
                    </div>

                    {/* Tickets List */}
                    <div className="rounded-2xl bg-brand-card border border-brand-border overflow-hidden shadow-lg">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-brand-border text-left">
                                <thead className="bg-brand-bgSecondary/40">
                                    <tr>
                                        <th className="py-4 pl-6 pr-3 text-xs font-bold text-brand-textSecondary uppercase">Task Details</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary uppercase">Device Node</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary uppercase">Technician</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary uppercase">Scheduled</th>
                                        <th className="px-3 py-4 text-xs font-bold text-brand-textSecondary uppercase">Status</th>
                                        <th className="py-4 pr-6 text-right text-xs font-bold text-brand-textSecondary uppercase">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-brand-border/60">
                                    {filteredTickets.length > 0 ? (
                                        filteredTickets.map((ticket) => (
                                            <tr key={ticket.id} className="hover:bg-brand-bgSecondary/30 transition">
                                                <td className="py-4 pl-6 pr-3">
                                                    <div className="font-bold text-white text-sm">{ticket.title}</div>
                                                    <div className="text-xs text-brand-textSecondary mt-0.5 max-w-[280px] truncate" title={ticket.description}>
                                                        {ticket.description}
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary">
                                                    <div className="font-bold text-white">{ticket.device?.name}</div>
                                                    <div className="text-xs font-mono">{ticket.device?.ip_address}</div>
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary font-semibold">
                                                    {ticket.technician?.name || 'Unassigned'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm text-brand-textSecondary font-mono">
                                                    {ticket.scheduled_at ? new Date(ticket.scheduled_at).toLocaleDateString() : '-'}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-4 text-sm">
                                                    <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-bold ${
                                                        ticket.status === 'completed'
                                                            ? 'bg-emerald-500/10 text-emerald-450 border border-emerald-500/20'
                                                            : ticket.status === 'in_progress'
                                                            ? 'bg-sky-500/10 text-sky-400 border border-sky-500/20'
                                                            : 'bg-amber-500/10 text-amber-500 border border-amber-500/20'
                                                    }`}>
                                                        {ticket.status === 'in_progress' ? 'In Progress' : ticket.status.toUpperCase()}
                                                    </span>
                                                </td>
                                                <td className="whitespace-nowrap py-4 pr-6 text-right text-sm">
                                                    <button
                                                        onClick={() => handleEditOpen(ticket)}
                                                        className="text-brand-primary hover:underline font-bold text-xs mr-3"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(ticket.id)}
                                                        className="text-rose-450 hover:underline font-bold text-xs"
                                                    >
                                                        Delete
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="text-center py-12 text-brand-textSecondary text-sm">
                                                No maintenance tickets registered for this view.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {/* Create Modal */}
                    {isCreateOpen && (
                        <div className="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-slate-950/70 backdrop-blur-sm">
                            <div className="w-full max-w-lg bg-brand-card border border-brand-border rounded-2xl p-6 shadow-2xl relative">
                                <h3 className="text-lg font-bold text-white mb-4">Create Maintenance Ticket</h3>
                                
                                <form onSubmit={handleCreateSubmit} className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Target Device</label>
                                        <select
                                            value={createForm.data.device_id}
                                            onChange={(e) => createForm.setData('device_id', e.target.value)}
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        >
                                            {devices.map((d) => (
                                                <option key={d.id} value={d.id}>{d.name} ({d.ip_address})</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Assigned Technician</label>
                                        <select
                                            value={createForm.data.technician_id}
                                            onChange={(e) => createForm.setData('technician_id', e.target.value)}
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        >
                                            {technicians.map((t) => (
                                                <option key={t.id} value={t.id}>{t.name}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Ticket Title</label>
                                        <input
                                            type="text"
                                            value={createForm.data.title}
                                            onChange={(e) => createForm.setData('title', e.target.value)}
                                            required
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Description</label>
                                        <textarea
                                            value={createForm.data.description}
                                            onChange={(e) => createForm.setData('description', e.target.value)}
                                            rows="3"
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        ></textarea>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Status</label>
                                            <select
                                                value={createForm.data.status}
                                                onChange={(e) => createForm.setData('status', e.target.value)}
                                                className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                            >
                                                <option value="pending">Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Scheduled Time</label>
                                            <input
                                                type="datetime-local"
                                                value={createForm.data.scheduled_at}
                                                onChange={(e) => createForm.setData('scheduled_at', e.target.value)}
                                                className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary text-sm"
                                            />
                                        </div>
                                    </div>

                                    <div className="flex items-center justify-end space-x-2 pt-4 border-t border-brand-border">
                                        <button
                                            type="button"
                                            onClick={() => setIsCreateOpen(false)}
                                            className="px-4 py-2 text-xs font-bold text-brand-textSecondary hover:text-white transition"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={createForm.processing}
                                            className="px-4 py-2 bg-brand-primary hover:bg-brand-primaryHover text-slate-950 font-bold rounded-xl text-xs transition"
                                        >
                                            Create
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                    {/* Edit Modal */}
                    {isEditOpen && (
                        <div className="fixed inset-0 z-50 overflow-y-auto flex items-center justify-center bg-slate-950/70 backdrop-blur-sm">
                            <div className="w-full max-w-lg bg-brand-card border border-brand-border rounded-2xl p-6 shadow-2xl relative">
                                <h3 className="text-lg font-bold text-white mb-4">Edit Maintenance Ticket</h3>
                                
                                <form onSubmit={handleEditSubmit} className="space-y-4">
                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Target Device</label>
                                        <select
                                            value={editForm.data.device_id}
                                            onChange={(e) => editForm.setData('device_id', e.target.value)}
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        >
                                            {devices.map((d) => (
                                                <option key={d.id} value={d.id}>{d.name} ({d.ip_address})</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Assigned Technician</label>
                                        <select
                                            value={editForm.data.technician_id}
                                            onChange={(e) => editForm.setData('technician_id', e.target.value)}
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        >
                                            <option value="">Unassigned</option>
                                            {technicians.map((t) => (
                                                <option key={t.id} value={t.id}>{t.name}</option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Ticket Title</label>
                                        <input
                                            type="text"
                                            value={editForm.data.title}
                                            onChange={(e) => editForm.setData('title', e.target.value)}
                                            required
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Description</label>
                                        <textarea
                                            value={editForm.data.description}
                                            onChange={(e) => editForm.setData('description', e.target.value)}
                                            rows="3"
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        ></textarea>
                                    </div>

                                    <div className="grid grid-cols-2 gap-4">
                                        <div>
                                            <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Status</label>
                                            <select
                                                value={editForm.data.status}
                                                onChange={(e) => editForm.setData('status', e.target.value)}
                                                className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                            >
                                                <option value="pending">Pending</option>
                                                <option value="in_progress">In Progress</option>
                                                <option value="completed">Completed</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Scheduled Time</label>
                                            <input
                                                type="datetime-local"
                                                value={editForm.data.scheduled_at}
                                                onChange={(e) => editForm.setData('scheduled_at', e.target.value)}
                                                className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary text-sm"
                                            />
                                        </div>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Resolution Notes</label>
                                        <textarea
                                            value={editForm.data.notes}
                                            onChange={(e) => editForm.setData('notes', e.target.value)}
                                            rows="2"
                                            placeholder="Write resolution comments here..."
                                            className="w-full bg-brand-bgSecondary border border-brand-border rounded-xl text-white py-2 px-3 focus:outline-none focus:border-brand-primary"
                                        ></textarea>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-brand-textSecondary uppercase mb-1">Attachment File</label>
                                        <input
                                            type="file"
                                            onChange={(e) => editForm.setData('attachment', e.target.files[0])}
                                            className="w-full text-xs text-brand-textSecondary file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-brand-primary file:text-slate-950 hover:file:bg-brand-primaryHover"
                                        />
                                        {activeTicket?.attachment_path && (
                                            <div className="text-[10px] text-brand-primary mt-1">
                                                Current file: <a href={`/storage/${activeTicket.attachment_path}`} target="_blank" rel="noopener noreferrer" className="underline">View current upload</a>
                                            </div>
                                        )}
                                    </div>

                                    <div className="flex items-center justify-end space-x-2 pt-4 border-t border-brand-border">
                                        <button
                                            type="button"
                                            onClick={() => setIsEditOpen(false)}
                                            className="px-4 py-2 text-xs font-bold text-brand-textSecondary hover:text-white transition"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={editForm.processing}
                                            className="px-4 py-2 bg-brand-primary hover:bg-brand-primaryHover text-slate-950 font-bold rounded-xl text-xs transition"
                                        >
                                            Save Changes
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
