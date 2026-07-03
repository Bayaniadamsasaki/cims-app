<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\Master\BuildingController;
use App\Http\Controllers\Master\FloorController;
use App\Http\Controllers\Master\RoomController;
use App\Http\Controllers\Master\RackController;
use App\Http\Controllers\Master\VendorController;
use App\Http\Controllers\Master\DeviceCategoryController;
use App\Http\Controllers\DeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public Authentication Route
Route::post('/login', [AuthController::class, 'login']);

// Authenticated Sanctum Routes
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth Profile Actions
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/change-password', [AuthController::class, 'changePassword']);

    // User Management CRUD (Super Admin / Users with 'manage users' permission)
    Route::middleware('can:manage users')->apiResource('users', UserController::class);

    // Master Data
    // Read-only endpoints available to all authenticated users
    Route::get('buildings', [BuildingController::class, 'index']);
    Route::get('buildings/{building}', [BuildingController::class, 'show']);
    
    Route::get('floors', [FloorController::class, 'index']);
    Route::get('floors/{floor}', [FloorController::class, 'show']);

    Route::get('rooms', [RoomController::class, 'index']);
    Route::get('rooms/{room}', [RoomController::class, 'show']);

    Route::get('racks', [RackController::class, 'index']);
    Route::get('racks/{rack}', [RackController::class, 'show']);

    Route::get('vendors', [VendorController::class, 'index']);
    Route::get('vendors/{vendor}', [VendorController::class, 'show']);

    Route::get('device-categories', [DeviceCategoryController::class, 'index']);
    Route::get('device-categories/{device_category}', [DeviceCategoryController::class, 'show']);

    // Write endpoints restricted to users with 'manage master data' permission
    Route::middleware('can:manage master data')->group(function () {
        Route::post('buildings', [BuildingController::class, 'store']);
        Route::put('buildings/{building}', [BuildingController::class, 'update']);
        Route::delete('buildings/{building}', [BuildingController::class, 'destroy']);

        Route::post('floors', [FloorController::class, 'store']);
        Route::put('floors/{floor}', [FloorController::class, 'update']);
        Route::delete('floors/{floor}', [FloorController::class, 'destroy']);

        Route::post('rooms', [RoomController::class, 'store']);
        Route::put('rooms/{room}', [RoomController::class, 'update']);
        Route::delete('rooms/{room}', [RoomController::class, 'destroy']);

        Route::post('racks', [RackController::class, 'store']);
        Route::put('racks/{rack}', [RackController::class, 'update']);
        Route::delete('racks/{rack}', [RackController::class, 'destroy']);

        Route::post('vendors', [VendorController::class, 'store']);
        Route::put('vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('vendors/{vendor}', [VendorController::class, 'destroy']);

        Route::post('device-categories', [DeviceCategoryController::class, 'store']);
        Route::put('device-categories/{device_category}', [DeviceCategoryController::class, 'update']);
        Route::delete('device-categories/{device_category}', [DeviceCategoryController::class, 'destroy']);
    });

    // Devices (Inventory)
    // Read-only endpoints available to all authenticated users
    Route::get('devices', [DeviceController::class, 'index']);
    Route::get('devices/{device}', [DeviceController::class, 'show']);

    // Write endpoints restricted to users with 'manage devices' permission
    Route::middleware('can:manage devices')->group(function () {
        Route::post('devices', [DeviceController::class, 'store']);
        // We support both POST and PUT for updates because PUT requests do not parse multipart files out of the box in PHP
        Route::post('devices/{device}', [DeviceController::class, 'update']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);
    });
});
