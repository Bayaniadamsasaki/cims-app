<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris = satu voucher hotspot milik satu mahasiswa.
 *
 * Sejak voucher diterapkan ke RADIUS, kuncinya NIM saja — bukan lagi (NIM,
 * router). Satu identitas mahasiswa berlaku di semua router hotspot kampus,
 * karena yang menjawab Access-Request cuma satu server. Kolom router_host dan
 * mikrotik_id ditinggalkan sebagai catatan sejarah, bukan penentu apa pun.
 *
 * Arti status:
 *   pending  — sudah ada di CIMS, belum diterapkan ke RADIUS
 *   synced   — barisnya ada di RADIUS dan isinya sama dengan di sini
 *   failed   — penerapan terakhir gagal; alasannya di last_error
 *   disabled — barisnya ADA di RADIUS tapi ditolak (Auth-Type := Reject),
 *              alasannya di disabled_reason
 */
class HotspotVoucher extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const STATUS_DISABLED = 'disabled';

    /** Voucher lahir dari tarikan API PMB/SISKA; boleh dinonaktifkan otomatis. */
    public const SOURCE_PMB = 'pmb';

    /** Dibuat tangan lewat form. Dosen, staf, dan tamu masuk sini. */
    public const SOURCE_MANUAL = 'manual';

    /** Dari unggahan Excel/CSV. Seperti manual: tidak pernah dimatikan sync. */
    public const SOURCE_IMPORT = 'import';

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
        'source',
        'disabled_reason',
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

    /**
     * Baris yang mewakili voucher ini di tiga tabel FreeRADIUS. Simetris dengan
     * toRouterAttributes(): satu-satunya tempat kolom voucher diterjemahkan
     * menjadi atribut RADIUS.
     *
     * $defaultGroup datang dari service, bukan dibaca di sini, supaya model tetap
     * bisa diuji tanpa menyentuh config.
     *
     * @return array{
     *     check: array<int,array{username:string,attribute:string,op:string,value:string}>,
     *     reply: array<int,array{username:string,attribute:string,op:string,value:string}>,
     *     group: array<int,array{username:string,groupname:string,priority:int}>
     * }
     */
    public function toRadiusRows(?string $defaultGroup = null): array
    {
        $username = (string) $this->nim;

        // op ':=' bukan '=': FreeRADIUS memakai ':=' sebagai "ganti nilainya",
        // sementara '=' hanya menambahkan bila atributnya belum ada.
        $check = [[
            'username' => $username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => (string) $this->password,
        ]];

        // Voucher nonaktif tetap punya baris di radcheck, hanya ditolak. Kalau
        // barisnya dihapus, radpostauth mencatatnya sebagai user tak dikenal —
        // tidak bisa dibedakan dari NIM salah ketik — dan mengaktifkan kembali
        // berarti menulis ulang seluruh kredensialnya.
        if ($this->status === self::STATUS_DISABLED) {
            $check[] = [
                'username' => $username,
                'attribute' => 'Auth-Type',
                'op' => ':=',
                'value' => 'Reject',
            ];
        }

        $reply = [];

        if (($seconds = $this->limitUptimeSeconds()) !== null) {
            $reply[] = [
                'username' => $username,
                'attribute' => 'Session-Timeout',
                'op' => ':=',
                'value' => (string) $seconds,
            ];
        }

        $group = [];
        $groupname = filled($this->profile) ? trim((string) $this->profile) : trim((string) $defaultGroup);

        if ($groupname !== '') {
            $group[] = [
                'username' => $username,
                'groupname' => $groupname,
                'priority' => 1,
            ];
        }

        return ['check' => $check, 'reply' => $reply, 'group' => $group];
    }

    /**
     * limit_uptime memakai notasi durasi RouterOS ("2h30m", "1d", "45m"),
     * sedangkan Session-Timeout RADIUS hanya menerima detik. Nilai yang tidak
     * terbaca dikembalikan sebagai null supaya barisnya dilewati, bukan dikirim
     * dengan angka yang salah — batas waktu yang keliru lebih buruk daripada
     * tidak ada batas.
     */
    public function limitUptimeSeconds(): ?int
    {
        $raw = strtolower(trim((string) $this->limit_uptime));

        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw)) {
            return ((int) $raw) ?: null;
        }

        // Bentuk "1w2d3h4m5s"; RouterOS juga menerima "hh:mm:ss".
        if (preg_match('/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $raw, $clock)) {
            return (int) $clock[1] * 3600 + (int) $clock[2] * 60 + (int) ($clock[3] ?? 0);
        }

        if (! preg_match('/^(?:\d+[wdhms])+$/', $raw)) {
            return null;
        }

        $units = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
        $seconds = 0;

        preg_match_all('/(\d+)([wdhms])/', $raw, $parts, PREG_SET_ORDER);

        foreach ($parts as $part) {
            $seconds += (int) $part[1] * $units[$part[2]];
        }

        return $seconds ?: null;
    }
}
