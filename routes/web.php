<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

// Dashboard CIMS — tema terang sesuai Docs/design_cims_dashboard.md
Route::get('/dashboard', [\App\Http\Controllers\Web\DashboardWebController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Device Inventory CRUD & Import
    Route::get('/devices', [\App\Http\Controllers\Web\DeviceWebController::class, 'index'])->name('devices.index');
    // Password perangkat dibuka satu per satu lewat endpoint ini, bukan lewat
    // props halaman inventaris. Dipagari izin tersendiri ('view device
    // credentials') dan dibatasi lajunya supaya satu akun yang bocor tidak bisa
    // menyapu seluruh kredensial router kampus dalam satu putaran.
    Route::get('/devices/{id}/credential', [\App\Http\Controllers\Web\DeviceWebController::class, 'credential'])
        ->middleware(['can:view device credentials', 'throttle:20,1'])
        ->name('devices.credential');
    Route::post('/devices', [\App\Http\Controllers\Web\DeviceWebController::class, 'store'])->name('devices.store');
    Route::post('/devices/import', [\App\Http\Controllers\Web\DeviceWebController::class, 'import'])->name('devices.import');
    Route::post('/devices/excel/upload', [\App\Http\Controllers\Web\DeviceWebController::class, 'uploadExcelView'])->name('devices.excel.upload');
    Route::post('/devices/{id}/sync-interfaces', [\App\Http\Controllers\Web\DeviceWebController::class, 'syncInterfaces'])->name('devices.sync-interfaces');
    Route::post('/devices/{id}', [\App\Http\Controllers\Web\DeviceWebController::class, 'update'])->name('devices.update');
    // Hapus massal harus dideklarasikan SEBELUM `devices/{id}` di bawahnya —
    // kalau dibalik, '/devices/bulk-destroy' akan tertangkap lebih dulu oleh
    // pola {id} dan berakhir sebagai upaya menghapus perangkat ber-id
    // "bulk-destroy". `whereNumber` pada route di bawah menutup celah yang sama
    // dari arah sebaliknya.
    //
    // Keduanya dipagari 'manage devices', izin yang sudah ada dan dipegang Super
    // Admin serta Network Administrator. Sengaja memakai izin lama, bukan
    // membuat 'delete devices' baru: izin baru berarti tidak seorang pun bisa
    // menghapus perangkat sampai seeder dijalankan ulang di setiap environment.
    // Yang ditutup di sini adalah lubang sebenarnya — sebelumnya kedua route
    // hanya berpagar 'auth', sehingga Technician (yang tidak punya 'manage
    // devices') tetap bisa menghapus perangkat lewat request langsung.
    Route::delete('/devices/bulk-destroy', [\App\Http\Controllers\Web\DeviceWebController::class, 'bulkDestroy'])
        ->middleware('can:manage devices')
        ->name('devices.bulk-destroy');
    Route::delete('/devices/{id}', [\App\Http\Controllers\Web\DeviceWebController::class, 'destroy'])
        ->middleware('can:manage devices')
        ->whereNumber('id')
        ->name('devices.destroy');

    // Master Data CRUD
    Route::get('/buildings', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsIndex'])->name('buildings.index');
    Route::post('/buildings', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsStore'])->name('buildings.store');
    Route::post('/buildings/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsUpdate'])->name('buildings.update');
    Route::delete('/buildings/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsDestroy'])->name('buildings.destroy');

    Route::get('/floors', [\App\Http\Controllers\Web\MasterWebController::class, 'floorsIndex'])->name('floors.index');
    Route::get('/floors/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'floorsShow'])->name('floors.show');
    Route::post('/floors', [\App\Http\Controllers\Web\MasterWebController::class, 'floorsStore'])->name('floors.store');
    Route::post('/floors/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'floorsUpdate'])->name('floors.update');
    Route::delete('/floors/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'floorsDestroy'])->name('floors.destroy');

    Route::get('/rooms', [\App\Http\Controllers\Web\MasterWebController::class, 'roomsIndex'])->name('rooms.index');
    Route::post('/rooms', [\App\Http\Controllers\Web\MasterWebController::class, 'roomsStore'])->name('rooms.store');
    Route::post('/rooms/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'roomsUpdate'])->name('rooms.update');
    Route::delete('/rooms/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'roomsDestroy'])->name('rooms.destroy');

    Route::get('/vendors', [\App\Http\Controllers\Web\MasterWebController::class, 'vendorsIndex'])->name('vendors.index');
    Route::post('/vendors', [\App\Http\Controllers\Web\MasterWebController::class, 'vendorsStore'])->name('vendors.store');
    Route::post('/vendors/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'vendorsUpdate'])->name('vendors.update');
    Route::delete('/vendors/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'vendorsDestroy'])->name('vendors.destroy');

    Route::get('/device-categories', [\App\Http\Controllers\Web\MasterWebController::class, 'categoriesIndex'])->name('device-categories.index');
    Route::post('/device-categories', [\App\Http\Controllers\Web\MasterWebController::class, 'categoriesStore'])->name('device-categories.store');
    Route::post('/device-categories/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'categoriesUpdate'])->name('device-categories.update');
    Route::delete('/device-categories/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'categoriesDestroy'])->name('device-categories.destroy');

    // User Management CRUD (restricted to Super Admin or users with 'manage users' permission)
    Route::middleware('can:manage users')->group(function () {
        Route::get('/users', [\App\Http\Controllers\Web\UserWebController::class, 'index'])->name('users.index');
        Route::post('/users', [\App\Http\Controllers\Web\UserWebController::class, 'store'])->name('users.store');
        Route::post('/users/{id}', [\App\Http\Controllers\Web\UserWebController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [\App\Http\Controllers\Web\UserWebController::class, 'destroy'])->name('users.destroy');
    });

    // Infrastructure Monitoring Routes
    Route::get('/monitoring', [\App\Http\Controllers\Web\MonitoringWebController::class, 'index'])->name('monitoring.index');
    Route::post('/monitoring/scan', [\App\Http\Controllers\Web\MonitoringWebController::class, 'scanAll'])->name('monitoring.scan');
    Route::post('/monitoring/speedtest', [\App\Http\Controllers\Web\MonitoringWebController::class, 'runSpeedtest'])->name('monitoring.speedtest');
    Route::get('/monitoring/{id}', [\App\Http\Controllers\Web\MonitoringWebController::class, 'show'])->name('monitoring.show');

    // Maintenance Ticket Routes
    Route::get('/maintenance', [\App\Http\Controllers\Web\MaintenanceWebController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance', [\App\Http\Controllers\Web\MaintenanceWebController::class, 'store'])->name('maintenance.store');
    Route::post('/maintenance/{id}', [\App\Http\Controllers\Web\MaintenanceWebController::class, 'update'])->name('maintenance.update');
    Route::delete('/maintenance/{id}', [\App\Http\Controllers\Web\MaintenanceWebController::class, 'destroy'])->name('maintenance.destroy');

    // Laporan Speedtest Jaringan Bulanan
    Route::prefix('speedtest-reports')->name('speedtest-reports.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'index'])->name('index');
        Route::get('/export', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'exportCsv'])->name('export');
        Route::post('/', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'store'])->name('store');
        Route::post('/testers', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'storeTester'])->name('testers.store');
        Route::delete('/testers/{id}', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'destroyTester'])->name('testers.destroy');
        Route::post('/{id}', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [\App\Http\Controllers\Web\SpeedtestReportWebController::class, 'destroy'])->name('destroy');
    });

    // Reporting Routes
    Route::get('/reports', [\App\Http\Controllers\Web\ReportWebController::class, 'index'])->name('reports.index');
    Route::get('/reports/excel', [\App\Http\Controllers\Web\ReportWebController::class, 'exportExcel'])->name('reports.excel');
    Route::get('/reports/pdf', [\App\Http\Controllers\Web\ReportWebController::class, 'exportPdf'])->name('reports.pdf');

    // MikroTik API Explorer Routes
    Route::prefix('mikrotik')->name('mikrotik.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\MikrotikWebController::class, 'index'])->name('index');
        Route::get('/api/metrics', [\App\Http\Controllers\Web\MikrotikWebController::class, 'refreshMetrics'])->name('api.metrics');
        Route::get('/api/ip-addresses', [\App\Http\Controllers\Web\MikrotikWebController::class, 'ipAddresses'])->name('api.ip-addresses');
        Route::get('/api/routes', [\App\Http\Controllers\Web\MikrotikWebController::class, 'routes'])->name('api.routes');
        Route::get('/api/firewall-filter', [\App\Http\Controllers\Web\MikrotikWebController::class, 'firewallFilter'])->name('api.firewall-filter');
        Route::get('/api/nat-rules', [\App\Http\Controllers\Web\MikrotikWebController::class, 'natRules'])->name('api.nat-rules');
        Route::get('/api/hotspot-active', [\App\Http\Controllers\Web\MikrotikWebController::class, 'hotspotActive'])->name('api.hotspot-active');
        Route::get('/api/dhcp-leases', [\App\Http\Controllers\Web\MikrotikWebController::class, 'dhcpLeases'])->name('api.dhcp-leases');
        Route::get('/api/arp-table', [\App\Http\Controllers\Web\MikrotikWebController::class, 'arpTable'])->name('api.arp-table');
        Route::get('/api/logs', [\App\Http\Controllers\Web\MikrotikWebController::class, 'logs'])->name('api.logs');
        Route::get('/api/neighbors', [\App\Http\Controllers\Web\MikrotikWebController::class, 'neighbors'])->name('api.neighbors');
        Route::get('/api/queues', [\App\Http\Controllers\Web\MikrotikWebController::class, 'queues'])->name('api.queues');
        Route::get('/api/wireless-clients', [\App\Http\Controllers\Web\MikrotikWebController::class, 'wirelessClients'])->name('api.wireless-clients');
        Route::get('/api/ppp-active', [\App\Http\Controllers\Web\MikrotikWebController::class, 'pppActive'])->name('api.ppp-active');
        Route::get('/api/dns-config', [\App\Http\Controllers\Web\MikrotikWebController::class, 'dnsConfig'])->name('api.dns-config');
    });

    // Voucher WiFi Mahasiswa (MikroTik Hotspot)
    Route::prefix('hotspot/vouchers')->name('hotspot.vouchers.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'index'])->name('index');
        Route::get('/template', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'template'])->name('template');
        Route::get('/export', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'export'])->name('export');
        Route::get('/print', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'printCards'])->name('print');
        Route::get('/active', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'activeUsers'])->name('active');
        Route::post('/', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'store'])->name('store');
        Route::post('/import', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'import'])->name('import');
        Route::post('/sync-pmb', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'syncPmb'])->name('sync-pmb');
        Route::post('/push', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'push'])->name('push');
        Route::post('/{id}/push', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'pushOne'])->name('push-one');
        Route::post('/{id}/toggle', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'toggle'])->name('toggle');
        Route::post('/{id}/kick', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'kick'])->name('kick');
        Route::post('/{id}', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'update'])->name('update');
        Route::delete('/{id}', [\App\Http\Controllers\Web\HotspotVoucherWebController::class, 'destroy'])->name('destroy');
    });

    // Ruijie Reyee Cloud API Explorer Routes
    Route::prefix('ruijie')->name('ruijie.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Web\RuijieWebController::class, 'index'])->name('index');
        Route::get('/api/test', [\App\Http\Controllers\Web\RuijieWebController::class, 'testConnection'])->name('api.test');
        Route::get('/api/devices', [\App\Http\Controllers\Web\RuijieWebController::class, 'devices'])->name('api.devices');
        Route::get('/api/wireless-clients', [\App\Http\Controllers\Web\RuijieWebController::class, 'wirelessClients'])->name('api.wireless-clients');
        Route::get('/api/alarms', [\App\Http\Controllers\Web\RuijieWebController::class, 'alarms'])->name('api.alarms');
    });

    // Interactive Topology Map Routes
    Route::get('/topology', [\App\Http\Controllers\Web\TopologyWebController::class, 'index'])->name('topology.index');
    Route::get('/topology/data', [\App\Http\Controllers\Web\TopologyWebController::class, 'graphData'])->name('topology.data');

    // Security & Anomaly Alerts Center Routes
    Route::get('/alerts', [\App\Http\Controllers\Web\AlertWebController::class, 'index'])->name('alerts.index');
    Route::post('/alerts/scan', [\App\Http\Controllers\Web\AlertWebController::class, 'scan'])->name('alerts.scan');
    Route::post('/alerts/test-telegram', [\App\Http\Controllers\Web\AlertWebController::class, 'testTelegram'])->name('alerts.test-telegram');
});

require __DIR__.'/auth.php';
