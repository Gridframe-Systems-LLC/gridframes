<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Sui address a team has proven it owns, by signing this platform's challenge with the key behind
 * the address. One address belongs to one team, ever; the private key is never seen here.
 */
class SuiIdentity extends Model
{
    protected $fillable = [
        'team_id',
        'sui_address',
        'public_key',
        'sui_ns_name',
        'verified_at',
        'unbound_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'unbound_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
