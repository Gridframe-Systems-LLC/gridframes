@php
    $subscription = currentTeam()->subscription;
    $plan = config('subscription.plans.'.$subscription->plan_key, []);
    $charges = $subscription->charges()->latest('period_start')->limit(6)->get();
@endphp
<x-application.settings-section title="Plan overview"
    description="This plan is paid from GridFrames gateway credits, month by month.">
    <div class="space-y-2 text-sm">
        <div><span class="text-neutral-500">Plan:</span> <span class="font-medium">{{ data_get($plan, 'name', $subscription->plan_key) }}</span>
            @if (data_get($plan, 'price_cents'))
                <span class="text-neutral-500">· ${{ number_format($plan['price_cents'] / 100, 2) }} a month</span>
            @endif
        </div>
        <div><span class="text-neutral-500">Machines:</span> {{ data_get($plan, 'servers', '—') }}</div>
        @if ($subscription->isTrialing())
            <div><span class="text-neutral-500">Free trial until:</span> {{ $subscription->trial_ends_at->toFormattedDateString() }}.
                The first month is charged to your credits then.</div>
        @elseif ($subscription->paid_until)
            <div><span class="text-neutral-500">Paid through:</span> {{ $subscription->paid_until->toFormattedDateString() }}</div>
        @endif
        <div><span class="text-neutral-500">Paid from:</span> gateway account #{{ $subscription->gateway_customer_id }}</div>
        @if ($subscription->paid_until && $subscription->paid_until->isPast())
            <x-callout type="warning" title="Renewal not paid">
                The last renewal could not be charged. Top up your gateway credits; the plan stays in force until
                {{ $subscription->paid_until->copy()->addDays((int) config('subscription.credits.grace_days'))->toFormattedDateString() }}
                and the charge is retried every hour.
            </x-callout>
        @endif
    </div>
    <div class="mt-4 flex flex-wrap gap-2">
        @if ($subscription->stripe_cancel_at_period_end)
            <span class="text-sm text-neutral-500">Renewal is stopped: the plan ends when the paid period does.</span>
            <x-forms.button wire:click="resumeCreditsRenewal">Resume renewal</x-forms.button>
        @else
            <x-forms.button wire:click="stopCreditsRenewal">Stop renewing</x-forms.button>
        @endif
    </div>
</x-application.settings-section>

@if ($charges->isNotEmpty())
    <x-application.settings-section title="Charges" description="Every monthly charge taken from your credits.">
        <div class="divide-y divide-neutral-200 text-sm dark:divide-white/[0.07]">
            @foreach ($charges as $charge)
                <div class="flex items-center justify-between gap-4 py-2">
                    <span>{{ $charge->period_start->toFormattedDateString() }} to {{ $charge->period_end->toFormattedDateString() }}</span>
                    <span class="{{ $charge->status === 'paid' ? '' : 'text-red-500' }}">
                        ${{ number_format($charge->amount_cents / 100, 2) }} · {{ $charge->status === 'paid' ? 'paid' : 'not paid' }}
                    </span>
                </div>
            @endforeach
        </div>
    </x-application.settings-section>
@endif
