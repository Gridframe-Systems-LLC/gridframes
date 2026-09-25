<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineOverageCharge extends Model
{
    protected $fillable = [
        'team_id',
        'period',
        'memory_cents',
        'disk_cents',
        'transfer_cents',
        'total_cents',
        'provider',
        'charge_ref',
        'status',
        'provider_reference',
    ];

    protected function casts(): array
    {
        return [
            'memory_cents' => 'integer',
            'disk_cents' => 'integer',
            'transfer_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The idempotent reference a month's overage is taken under, here and at the gateway or Stripe:
     * the same reference always names the same charge, so a retry can never take it twice.
     */
    public static function reference(int $teamId, string $period): string
    {
        return "team-{$teamId}-overage-{$period}";
    }
}
