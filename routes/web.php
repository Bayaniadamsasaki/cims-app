<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return redirect()->route('login');
});

Route::get('/dashboard', function () {
    $totalDevices = \App\Models\Device::count();
    $totalBuildings = \App\Models\Building::count();
    $activeDevices = \App\Models\Device::where('status', 'active')->count();
    $offlineDevices = \App\Models\Device::where('status', 'offline')->count();
    $maintenanceDevices = \App\Models\Device::where('status', 'maintenance')->count();
    
    $recentDevices = \App\Models\Device::with(['vendor', 'category', 'building', 'floor', 'room', 'rack'])
        ->latest()
        ->take(5)
        ->get();
        
    $categories = \App\Models\DeviceCategory::withCount('devices')->get()->map(function ($cat) {
        return [
            'name' => $cat->name,
            'count' => $cat->devices_count
        ];
    });

    $buildings = \App\Models\Building::withCount('devices')->get()->map(function ($bld) {
        return [
            'name' => $bld->name,
            'count' => $bld->devices_count
        ];
    });

    return Inertia::render('Dashboard', [
        'stats' => [
            'totalDevices' => $totalDevices,
            'totalBuildings' => $totalBuildings,
            'activeDevices' => $activeDevices,
            'offlineDevices' => $offlineDevices,
            'maintenanceDevices' => $maintenanceDevices,
        ],
        'recentDevices' => $recentDevices,
        'categories' => $categories,
        'buildings' => $buildings,
    ]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Device Inventory CRUD
    Route::get('/devices', [\App\Http\Controllers\Web\DeviceWebController::class, 'index'])->name('devices.index');
    Route::post('/devices', [\App\Http\Controllers\Web\DeviceWebController::class, 'store'])->name('devices.store');
    Route::post('/devices/{id}', [\App\Http\Controllers\Web\DeviceWebController::class, 'update'])->name('devices.update');
    Route::delete('/devices/{id}', [\App\Http\Controllers\Web\DeviceWebController::class, 'destroy'])->name('devices.destroy');

    // Master Data CRUD
    Route::get('/buildings', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsIndex'])->name('buildings.index');
    Route::post('/buildings', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsStore'])->name('buildings.store');
    Route::post('/buildings/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsUpdate'])->name('buildings.update');
    Route::delete('/buildings/{id}', [\App\Http\Controllers\Web\MasterWebController::class, 'buildingsDestroy'])->name('buildings.destroy');

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

    // Reporting Routes
    Route::get('/reports', [\App\Http\Controllers\Web\ReportWebController::class, 'index'])->name('reports.index');
    Route::get('/reports/excel', [\App\Http\Controllers\Web\ReportWebController::class, 'exportExcel'])->name('reports.excel');
    Route::get('/reports/pdf', [\App\Http\Controllers\Web\ReportWebController::class, 'exportPdf'])->name('reports.pdf');
});

require __DIR__.'/auth.php';
