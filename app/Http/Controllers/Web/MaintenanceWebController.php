<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MaintenanceTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceWebController extends Controller
{
    /**
     * Display a listing of the maintenance tickets.
     */
    public function index(Request $request): Response
    {
        $tickets = MaintenanceTicket::with(['device', 'technician'])
            ->latest()
            ->get();

        $devices = Device::all();
        // Load only users who are Technicians or Super Admins to list as assignable technicians
        $technicians = User::whereHas('roles', function($q) {
            $q->whereIn('name', ['Technician', 'Super Admin', 'Network Administrator']);
        })->get();

        return Inertia::render('Maintenance/Index', [
            'tickets' => $tickets,
            'devices' => $devices,
            'technicians' => $technicians,
        ]);
    }

    /**
     * Store a newly created maintenance ticket.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|exists:devices,id',
            'technician_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:pending,in_progress,completed',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        MaintenanceTicket::create($validated);

        return redirect()->back()->with('success', 'Maintenance ticket created successfully.');
    }

    /**
     * Update the specified maintenance ticket.
     * Note: Submitted via POST due to Laravel multipart/form-data routing constraint.
     */
    public function update(Request $request, $id)
    {
        $ticket = MaintenanceTicket::findOrFail($id);

        $validated = $request->validate([
            'device_id' => 'required|exists:devices,id',
            'technician_id' => 'nullable|exists:users,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|in:pending,in_progress,completed',
            'scheduled_at' => 'nullable|date',
            'notes' => 'nullable|string',
            'attachment' => 'nullable|file|max:5120', // max 5MB
        ]);

        if ($request->hasFile('attachment')) {
            // Delete old attachment if exists
            if ($ticket->attachment_path) {
                Storage::disk('public')->delete($ticket->attachment_path);
            }
            $path = $request->file('attachment')->store('attachments', 'public');
            $validated['attachment_path'] = $path;
        }

        $ticket->update($validated);

        return redirect()->back()->with('success', 'Maintenance ticket updated successfully.');
    }

    /**
     * Remove the specified maintenance ticket.
     */
    public function destroy($id)
    {
        $ticket = MaintenanceTicket::findOrFail($id);
        
        if ($ticket->attachment_path) {
            Storage::disk('public')->delete($ticket->attachment_path);
        }
        
        $ticket->delete();

        return redirect()->back()->with('success', 'Maintenance ticket deleted successfully.');
    }
}
