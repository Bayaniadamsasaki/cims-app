<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'mac_address',
        'vendor_id',
        'device_category_id',
        'model',
        'serial_number',
        'firmware',
        'purchase_date',
        'warranty',
        'building_id',
        'floor_id',
        'room_id',
        'rack_id',
        'status',
        'notes',
        'image_path',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(DeviceCategory::class, 'device_category_id');
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    public function metrics(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeviceMetric::class);
    }

    public function monitoringLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MonitoringLog::class);
    }

    public function maintenanceTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MaintenanceTicket::class);
    }
}
