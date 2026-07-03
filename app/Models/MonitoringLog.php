<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'status',
        'ping_latency_ms',
        'packet_loss_percent',
        'cpu_usage_percent',
        'ram_usage_percent',
        'storage_usage_percent',
        'temperature_celsius',
        'uptime_seconds',
        'bandwidth_rx_bps',
        'bandwidth_tx_bps',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
