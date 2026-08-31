<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpeedtestResult extends Model
{
    use HasFactory;

    /** Diukur dari server aplikasi (SpeedtestService → Cloudflare). */
    public const SOURCE_SERVER = 'server';

    /** Diukur dari container di router (MikrotikContainerSpeedtestService). */
    public const SOURCE_ROUTER = 'router-container';

    protected $fillable = [
        'source',
        'download_speed_mbps',
        'upload_speed_mbps',
        'ping_ms',
        'isp',
        'router_host',
        'server_name',
        'raw_output',
    ];

    protected $casts = [
        'download_speed_mbps' => 'float',
        'upload_speed_mbps' => 'float',
        'ping_ms' => 'integer',
    ];
}
