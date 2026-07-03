<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Device;
use App\Models\MaintenanceTicket;
use App\Models\MonitoringLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportWebController extends Controller
{
    /**
     * Display the reporting control center.
     */
    public function index(Request $request): Response
    {
        $buildings = Building::all();
        
        return Inertia::render('Reports/Index', [
            'buildings' => $buildings,
        ]);
    }

    /**
     * Export data to Excel/CSV format.
     */
    public function exportExcel(Request $request)
    {
        $type = $request->query('type', 'inventory');
        $buildingId = $request->query('building_id');
        $status = $request->query('status');

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"cims_{$type}_report_" . date('Ymd_His') . ".csv\"",
        ];

        $callback = function() use ($type, $buildingId, $status) {
            $file = fopen('php://output', 'w');

            if ($type === 'inventory') {
                // Header
                fputcsv($file, ['Device Name', 'Hostname', 'IP Address', 'MAC Address', 'Model', 'Building', 'Status', 'Last Checked']);
                
                $query = Device::with(['building', 'metrics']);
                if ($buildingId) $query->where('building_id', $buildingId);
                if ($status) $query->where('status', $status);
                
                foreach ($query->get() as $device) {
                    fputcsv($file, [
                        $device->name,
                        $device->hostname,
                        $device->ip_address,
                        $device->mac_address,
                        $device->model,
                        $device->building?->name ?? 'N/A',
                        strtoupper($device->status),
                        $device->metrics?->last_checked_at?->toDateTimeString() ?? 'N/A'
                    ]);
                }
            } elseif ($type === 'monitoring') {
                // Header
                fputcsv($file, ['Device Name', 'IP Address', 'Ping Status', 'Latency (ms)', 'CPU Usage (%)', 'RAM Usage (%)', 'Timestamp']);
                
                $query = MonitoringLog::with('device')->latest()->limit(500);
                if ($status) $query->where('status', $status);
                
                foreach ($query->get() as $log) {
                    fputcsv($file, [
                        $log->device?->name ?? 'N/A',
                        $log->device?->ip_address ?? 'N/A',
                        strtoupper($log->status),
                        $log->ping_latency_ms ?? 0,
                        $log->cpu_usage_percent ?? 0,
                        $log->ram_usage_percent ?? 0,
                        $log->checked_at->toDateTimeString()
                    ]);
                }
            } elseif ($type === 'maintenance') {
                // Header
                fputcsv($file, ['Ticket ID', 'Device Name', 'Technician', 'Title', 'Scheduled At', 'Status', 'Notes']);
                
                $query = MaintenanceTicket::with(['device', 'technician']);
                if ($status) $query->where('status', $status);
                
                foreach ($query->get() as $ticket) {
                    fputcsv($file, [
                        $ticket->id,
                        $ticket->device?->name ?? 'N/A',
                        $ticket->technician?->name ?? 'Unassigned',
                        $ticket->title,
                        $ticket->scheduled_at ? $ticket->scheduled_at->toDateTimeString() : 'N/A',
                        strtoupper($ticket->status),
                        $ticket->notes ?? ''
                    ]);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export data to PDF/Print format.
     */
    public function exportPdf(Request $request)
    {
        $type = $request->query('type', 'inventory');
        $buildingId = $request->query('building_id');
        $status = $request->query('status');

        $data = [];
        $title = '';

        if ($type === 'inventory') {
            $title = 'Device Inventory Aset TI';
            $query = Device::with(['building', 'metrics']);
            if ($buildingId) $query->where('building_id', $buildingId);
            if ($status) $query->where('status', $status);
            $data = $query->get();
        } elseif ($type === 'monitoring') {
            $title = 'Device Monitoring Telemetry Logs';
            $query = MonitoringLog::with('device')->latest()->limit(100);
            if ($status) $query->where('status', $status);
            $data = $query->get();
        } elseif ($type === 'maintenance') {
            $title = 'Riwayat Maintenance & Perbaikan';
            $query = MaintenanceTicket::with(['device', 'technician']);
            if ($status) $query->where('status', $status);
            $data = $query->get();
        }

        return view('reports.pdf_template', [
            'type' => $type,
            'title' => $title,
            'data' => $data,
            'timestamp' => now()->toDateTimeString(),
        ]);
    }
}
