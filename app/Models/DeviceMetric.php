<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'last_ping_status',
        'last_ping_latency_ms',
        'last_packet_loss_percent',
        'last_cpu_usage_percent',
        'last_ram_usage_percent',
        'last_storage_usage_percent',
        'last_temperature_celsius',
        'last_uptime_seconds',
        'last_interface_status',
        'last_bandwidth_rx_bps',
        'last_bandwidth_tx_bps',
        'last_checked_at',
    ];

    protected $casts = [
        'last_interface_status' => 'array',
        'last_checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
