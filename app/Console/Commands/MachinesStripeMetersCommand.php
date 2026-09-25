<?php

namespace App\Console\Commands;

use App\Services\Machines\StripeMeters;
use Illuminate\Console\Command;
use Stripe\StripeClient;

/**
 * Creates the three Billing Meters machine overage is reported through, once. Safe to run again:
 * a meter that is already on the account is reported and left alone, never duplicated.
 */
class MachinesStripeMetersCommand extends Command
{
    protected $signature = 'machines:stripe-meters';

    protected $description = 'Create the GridFrames machines Billing Meters on Stripe (once, idempotent)';

    public function handle(StripeMeters $meters): int
    {
        if (! config('subscription.stripe_api_key')) {
            $this->error('Stripe is not connected on this instance; there is no account to create the meters on.');

            return self::FAILURE;
        }

        $result = $meters->createMissing(app(StripeClient::class));
        foreach ($result['created'] as $resource => $id) {
            $this->info("Created the {$resource} meter: {$id}");
        }
        foreach ($result['existing'] as $resource => $id) {
            $this->line("The {$resource} meter already exists: {$id}");
        }

        return self::SUCCESS;
    }
}
