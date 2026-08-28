<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu voucher hotspot milik satu mahasiswa pada satu router.
 */
class HotspotVoucher extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'nim',
        'student_name',
        'faculty',
        'program',
        'password',
        'profile',
        'server',
        'router_host',
        'device_id',
        'status',
        'mikrotik_id',
        'last_error',
        'synced_at',
        'limit_uptime',
        'valid_until',
        'batch_label',
        'comment',
        'created_by',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'valid_until' => 'date',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Pencarian bebas pada NIM / nama / prodi / fakultas.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%' . trim($term) . '%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('nim', 'like', $like)
                ->orWhere('student_name', 'like', $like)
                ->orWhere('program', 'like', $like)
                ->orWhere('faculty', 'like', $like)
                ->orWhere('batch_label', 'like', $like);
        });
    }

    /**
     * Voucher sudah kedaluwarsa berdasarkan tanggal berlaku.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->valid_until !== null && $this->valid_until->isPast();
    }

    /**
     * Atribut yang dikirim ke /ip/hotspot/user pada RouterOS.
     */
    public function toRouterAttributes(): array
    {
        $attributes = [
            'name' => $this->nim,
            'password' => $this->password,
        ];

        if (filled($this->profile)) {
            $attributes['profile'] = $this->profile;
        }

        if (filled($this->server)) {
            $attributes['server'] = $this->server;
        }

        if (filled($this->limit_uptime)) {
            $attributes['limit-uptime'] = $this->limit_uptime;
        }

        $comment = collect([
            $this->student_name,
            $this->program,
            $this->batch_label,
            $this->comment,
        ])->filter()->implode(' | ');

        $attributes['comment'] = $comment !== '' ? 'CIMS: ' . $comment : 'CIMS';

        return $attributes;
    }
}
