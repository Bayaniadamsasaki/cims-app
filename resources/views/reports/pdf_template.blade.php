<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }} - CIMS Campus Net</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #1f2937;
            margin: 20px;
            font-size: 12px;
            line-height: 1.5;
        }
        .header {
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 20px;
            margin: 0;
            color: #111827;
        }
        .header p {
            margin: 5px 0 0 0;
            color: #6b7280;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background-color: #f9fafb;
            font-weight: bold;
            color: #374151;
            font-size: 10px;
            text-transform: uppercase;
        }
        tr:nth-child(even) {
            background-color: #fafafa;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 9px;
            font-weight: bold;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .badge-active { background-color: #def7ec; color: #03543f; }
        .badge-offline { background-color: #fde8e8; color: #9b1c1c; }
        .badge-maintenance { background-color: #fef08a; color: #713f12; }
        
        .badge-completed { background-color: #def7ec; color: #03543f; }
        .badge-in_progress { background-color: #e1f5fe; color: #0288d1; }
        .badge-pending { background-color: #fff3e0; color: #e65100; }
        
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="header">
        <h1>{{ $title }}</h1>
        <p>Generated: {{ $timestamp }} | CIMS Campus Infrastructure Monitoring System</p>
    </div>

    @if($type === 'inventory')
        <table>
            <thead>
                <tr>
                    <th>Perangkat</th>
                    <th>Hostname</th>
                    <th>IP Address</th>
                    <th>Kategori</th>
                    <th>Gedung</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data as $device)
                    <tr>
                        <td><strong>{{ $device->name }}</strong><br><span style="color:#6b7280; font-size:9px;">{{ $device->model }}</span></td>
                        <td>{{ $device->hostname }}</td>
                        <td><code>{{ $device->ip_address }}</code></td>
                        <td>{{ $device->category?->name ?? 'N/A' }}</td>
                        <td>{{ $device->building?->name ?? 'N/A' }}</td>
                        <td>
                            <span class="badge badge-{{ $device->status }}">
                                {{ $device->status }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif($type === 'monitoring')
        <table>
            <thead>
                <tr>
                    <th>Device Node</th>
                    <th>IP Address</th>
                    <th>Ping Status</th>
                    <th>Latency</th>
                    <th>CPU Load</th>
                    <th>RAM Usage</th>
                    <th>Timestamp</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data as $log)
                    <tr>
                        <td><strong>{{ $log->device?->name ?? 'N/A' }}</strong></td>
                        <td><code>{{ $log->device?->ip_address ?? 'N/A' }}</code></td>
                        <td>
                            <span class="badge badge-{{ $log->status === 'online' ? 'active' : 'offline' }}">
                                {{ $log->status }}
                            </span>
                        </td>
                        <td>{{ $log->ping_latency_ms ?? 0 }} ms</td>
                        <td>{{ $log->cpu_usage_percent ?? 0 }}%</td>
                        <td>{{ $log->ram_usage_percent ?? 0 }}%</td>
                        <td>{{ $log->checked_at->toDateTimeString() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @elseif($type === 'maintenance')
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tugas Pemeliharaan</th>
                    <th>Perangkat Target</th>
                    <th>Teknisi</th>
                    <th>Jadwal Kerja</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($data as $ticket)
                    <tr>
                        <td>#{{ $ticket->id }}</td>
                        <td><strong>{{ $ticket->title }}</strong><br><span style="color:#6b7280; font-size:9px;">{{ $ticket->description }}</span></td>
                        <td>{{ $ticket->device?->name ?? 'N/A' }}</td>
                        <td>{{ $ticket->technician?->name ?? 'Unassigned' }}</td>
                        <td>{{ $ticket->scheduled_at ? $ticket->scheduled_at->toDateString() : '-' }}</td>
                        <td>
                            <span class="badge badge-{{ $ticket->status }}">
                                {{ $ticket->status }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script>
        // Trigger print/save to PDF interface immediately upon display
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
