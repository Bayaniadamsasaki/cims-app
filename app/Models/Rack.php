<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'room_id',
        'name',
        'capacity',
        'code',
        'description',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
