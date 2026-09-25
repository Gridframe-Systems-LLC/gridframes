<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineUsageSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'machine_id',
        'sampled_at',
        'memory_mb',
        'disk_mb',
        'tx_bytes_total',
        'failure_kind',
        'failure_detail',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'memory_mb' => 'integer',
            'disk_mb' => 'integer',
            'tx_bytes_total' => 'integer',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * A sample that failed to read anything: the value columns stay null and this says why. A failed
     * read is never stored as zero.
     */
    public function isFailure(): bool
    {
        return $this->failure_kind !== null;
    }
}
