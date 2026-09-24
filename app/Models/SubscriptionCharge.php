<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One charge taken, or attempted, for a credit-paid plan.
 */
class SubscriptionCharge extends Model
{
    protected $fillable = [
        'team_id',
        'provider',
        'plan_key',
        'amount_cents',
        'charge_ref',
        'gateway_charge_id',
        'period_start',
        'period_end',
        'status',
        'failure',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
