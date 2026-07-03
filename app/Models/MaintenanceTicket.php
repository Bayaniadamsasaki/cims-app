<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'technician_id',
        'title',
        'description',
        'status',
        'scheduled_at',
        'notes',
        'attachment_path',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
