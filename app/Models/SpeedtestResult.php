<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpeedtestResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'download_speed_mbps',
        'upload_speed_mbps',
        'ping_ms',
        'isp',
    ];
}
