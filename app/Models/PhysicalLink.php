<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PhysicalLink extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'device_a_id',
        'interface_a_id',
        'device_b_id',
        'interface_b_id',
        'relationship_type',
        'discovery_source',
        'verification_status',
        'dedupe_key',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function deviceA(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_a_id');
    }

    public function interfaceA(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class, 'interface_a_id');
    }

    public function deviceB(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_b_id');
    }

    public function interfaceB(): BelongsTo
    {
        return $this->belongsTo(DeviceInterface::class, 'interface_b_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
