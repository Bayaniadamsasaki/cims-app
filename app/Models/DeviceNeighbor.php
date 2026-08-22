<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device discovered next to this one (MikroTik MNDP / CDP / LLDP),
 * as reported by the "Neighbor" sheet of the audit Excel file.
 */
class DeviceNeighbor extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'device_id',
        'interface_name',
        'mac_address',
        'ip_address',
        'identity',
        'platform',
        'board',
        'version',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
