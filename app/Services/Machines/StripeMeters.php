<?php

namespace App\Services\Machines;

use Illuminate\Support\Str;
use Stripe\StripeClient;

/**
 * The three Stripe Billing Meters machine overage is reported through, one per resource, created
 * once by `machines:stripe-meters` and only ever read back afterwards.
 *
 * Verified against Stripe's API reference for the installed stripe-php v16 client (v16.6.0), which
 * posts to /v1/billing/meters and /v1/billing/meter_events of the Billing Meters API (GA with API
 * version 2024-06-20):
 * - a meter is created with `display_name`, `event_name`, `default_aggregation[formula]`,
 *   `value_settings[event_payload_key]=value` and a `customer_mapping` on `stripe_customer_id`;
 * - a meter event carries `event_name`, a `payload` with `value` and `stripe_customer_id`, an
 *   `identifier` that Stripe deduplicates on, and a `timestamp` that must fall within the past 35
 *   calendar days — which a run on the 1st for the previous month satisfies.
 */
class StripeMeters
{
    /** Resource to the meter's event-name suffix. These names are wiring: set once, never changed. */
    public const EVENT_SUFFIXES = [
        'memory' => 'memory_gb_months',
        'disk' => 'disk_gb_months',
        'transfer' => 'transfer_gb',
    ];

    public function eventName(string $resource): string
    {
        $prefix = trim((string) config('machines.stripe.meter_prefix'));

        return $prefix.'_'.self::EVENT_SUFFIXES[$resource];
    }

    /**
     * List the account's meters and create whichever of the three are missing, so running the
     * command twice leaves exactly the same three meters behind.
     *
     * @return array{created: array<string, string>, existing: array<string, string>} resource to meter id
     */
    public function createMissing(StripeClient $stripe): array
    {
        $existing = [];
        foreach ($stripe->billing->meters->all(['limit' => 100])->autoPagingIterator() as $meter) {
            $existing[(string) $meter->event_name] = (string) $meter->id;
        }

        $created = [];
        $found = [];
        foreach (array_keys(self::EVENT_SUFFIXES) as $resource) {
            $eventName = $this->eventName($resource);
            if (isset($existing[$eventName])) {
                $found[$resource] = $existing[$eventName];

                continue;
            }
            $meter = $stripe->billing->meters->create([
                'display_name' => 'GridFrames machines '.Str::of($resource)->replace('_', ' ').' overage',
                'event_name' => $eventName,
                'default_aggregation' => ['formula' => 'sum'],
                'value_settings' => ['event_payload_key' => 'value'],
                'customer_mapping' => ['type' => 'by_id', 'event_payload_key' => 'stripe_customer_id'],
            ]);
            $created[$resource] = (string) $meter->id;
        }

        return ['created' => $created, 'existing' => $found];
    }
}
