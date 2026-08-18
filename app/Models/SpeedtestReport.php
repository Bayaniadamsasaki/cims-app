<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpeedtestReport extends Model
{
    use HasFactory;

    /**
     * Pilihan dropdown "Status Action" beserta label tampilannya.
     */
    public const STATUSES = [
        'lancar' => 'Lancar',
        'sedang' => 'Sedang',
        'lambat' => 'Lambat',
        'terputus' => 'Terputus',
    ];

    /**
     * Pilihan dropdown "Perangkat Uji Coba".
     */
    public const DEVICE_TYPES = [
        'laptop' => 'Laptop',
        'smartphone' => 'HP / Smartphone',
        'pc' => 'PC',
        'tablet' => 'Tablet',
        'lainnya' => 'Lainnya',
    ];

    /**
     * Pilihan dropdown "Tindakan / Action".
     */
    public const ACTIONS = [
        'maintenance' => 'Maintenance',
        'selesai' => 'Selesai',
        'monitoring_traffic' => 'Monitoring Traffic',
    ];

    protected $fillable = [
        'tested_at',
        'location',
        'ssid',
        'download_mbps',
        'upload_mbps',
        'ping_ms',
        'status',
        'device_type',
        'tester_id',
        'action',
        'screenshot_path',
        'created_by',
    ];

    protected $casts = [
        'tested_at' => 'datetime',
        'download_mbps' => 'float',
        'upload_mbps' => 'float',
        'ping_ms' => 'float',
    ];

    protected $appends = [
        'status_label',
        'device_type_label',
        'action_label',
        'screenshot_url',
        'screenshot_name',
        'tested_at_display',
        'tested_at_input',
    ];

    public function tester(): BelongsTo
    {
        return $this->belongsTo(SpeedtestTester::class, 'tester_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getDeviceTypeLabelAttribute(): string
    {
        return self::DEVICE_TYPES[$this->device_type] ?? $this->device_type;
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function getScreenshotUrlAttribute(): ?string
    {
        return $this->screenshot_path ? asset('storage/'.$this->screenshot_path) : null;
    }

    public function getScreenshotNameAttribute(): ?string
    {
        return $this->screenshot_path ? basename($this->screenshot_path) : null;
    }

    /**
     * Tanggal siap tampil pada zona waktu aplikasi, supaya nilai yang dibaca
     * pengguna selalu sama dengan yang diinput tanpa konversi di browser.
     */
    public function getTestedAtDisplayAttribute(): ?string
    {
        return $this->tested_at?->translatedFormat('d M Y H:i');
    }

    /**
     * Nilai untuk <input type="datetime-local"> saat mengedit laporan.
     */
    public function getTestedAtInputAttribute(): ?string
    {
        return $this->tested_at?->format('Y-m-d\TH:i');
    }

    /**
     * Terapkan filter tabel laporan (pencarian, rentang tanggal, dan dropdown).
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('location', 'like', "%{$search}%")
                        ->orWhere('ssid', 'like', "%{$search}%")
                        ->orWhereHas('tester', fn (Builder $t) => $t->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['date_from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('tested_at', '>=', $from))
            ->when($filters['date_to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('tested_at', '<=', $to))
            ->when($filters['month'] ?? null, function (Builder $q, string $month) {
                // Format YYYY-MM dari input <input type="month">
                [$year, $m] = array_pad(explode('-', $month), 2, null);
                $q->whereYear('tested_at', (int) $year)->whereMonth('tested_at', (int) $m);
            })
            ->when($filters['location'] ?? null, fn (Builder $q, string $location) => $q->where('location', $location))
            ->when($filters['ssid'] ?? null, fn (Builder $q, string $ssid) => $q->where('ssid', $ssid))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['tester_id'] ?? null, fn (Builder $q, $testerId) => $q->where('tester_id', $testerId))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action));
    }
}
