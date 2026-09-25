<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Machine extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'team_id',
        'server_id',
        'name',
        'size_key',
        'vcpu',
        'memory_mb',
        'disk_gb',
        'incus_project',
        'incus_name',
        'ipv4_private',
        'ipv6_public',
        'state',
        'failure',
        'stopped_reason',
        'stopped_at',
        'delete_after',
    ];

    protected function casts(): array
    {
        return [
            'vcpu' => 'integer',
            'memory_mb' => 'integer',
            'disk_gb' => 'integer',
            'stopped_at' => 'datetime',
            'delete_after' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function usageSamples()
    {
        return $this->hasMany(MachineUsageSample::class);
    }

    /**
     * The size this machine was created with, from config. The row's own vcpu, memory and disk are
     * what was reserved at creation; changing a size in config never resizes it silently.
     */
    public function size(): ?array
    {
        return config("machines.sizes.{$this->size_key}");
    }
}
