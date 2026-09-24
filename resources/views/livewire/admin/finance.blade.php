@php
    $money = fn (int $cents) => '$'.number_format($cents / 100, 2);
    $card = $cardThisMonth['ok'] ? (int) $cardThisMonth['cents'] : null;
@endphp
<div>
    <x-slot:title>Finance | GridFrames</x-slot>
    <div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Monthly recurring revenue</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">{{ $money($monthlyCents) }}</div>
                <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">
                    Paying plans, trials excluded{{ $unpriced ? ' · '.$unpriced.' with no known price not counted' : '' }}
                </div>
            </div>
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Card revenue, {{ $month }}</div>
                @if ($card !== null)
                    <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">{{ $money($card) }}</div>
                    <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">
                        {{ $cardThisMonth['count'] }} paid {{ Str::plural('invoice', $cardThisMonth['count']) }}{{ $cardThisMonth['partial'] ? ' · first '.$cardThisMonth['count'].' only, total is higher' : '' }}
                    </div>
                @else
                    <div class="mt-2 text-sm font-semibold text-amber-600">Not measured</div>
                    <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">Stripe did not answer ({{ $cardThisMonth['failure'] }})</div>
                @endif
            </div>
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">Credit revenue, {{ $month }}</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">{{ $money($creditsThisMonth) }}</div>
                <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">Charged to gateway credits</div>
            </div>
            <div class="rounded-[10px] border border-neutral-200 bg-white p-4 dark:border-white/[0.07] dark:bg-surface">
                <div class="text-xs font-medium text-neutral-500 dark:text-fg-dim">
                    Licence share{{ $share !== null ? ', '.rtrim(rtrim(number_format($share, 2), '0'), '.').'%' : '' }}</div>
                @if ($share === null)
                    <div class="mt-2 text-sm text-neutral-500 dark:text-fg-dim">Not set on this instance</div>
                @elseif ($card === null)
                    <div class="mt-2 text-sm font-semibold text-amber-600">Not measured</div>
                    <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">Needs card revenue; credits alone: {{ $money((int) round($creditsThisMonth * $share / 100)) }}</div>
                @else
                    <div class="mt-2 text-2xl font-semibold tracking-tight text-black dark:text-fg">{{ $money((int) round(($card + $creditsThisMonth) * $share / 100)) }}</div>
                    <div class="mt-0.5 text-xs text-neutral-500 dark:text-fg-faint">Of {{ $money($card + $creditsThisMonth) }} this month</div>
                @endif
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Plans in force</h2>
                    <p>Teams on each plan, by how they pay.{{ $unknownPlan ? ' '.$unknownPlan.' in force on a price this instance does not sell.' : '' }}</p>
                </div>
            </div>
            <div class="application-settings-section-body overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-neutral-500 dark:text-fg-dim">
                        <tr><th class="py-2 pr-4">Plan</th><th class="pr-4">Price</th><th class="pr-4">Card</th><th class="pr-4">Credits</th><th class="pr-4">In trial</th><th class="pr-4">Not renewing</th><th class="pr-4">Past due</th><th>Stripe price check</th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @foreach ($plans as $key => $plan)
                            @php $row = $byPlan[$key] ?? ['card' => 0, 'credits' => 0, 'trial' => 0, 'stopping' => 0, 'past_due' => 0]; $check = $prices[$key] ?? null; @endphp
                            <tr wire:key="finance-plan-{{ $key }}">
                                <td class="py-2 pr-4 font-medium">{{ $plan['name'] }}</td>
                                <td class="pr-4">{{ $plan['price_cents'] ? $money($plan['price_cents']) : ($plan['price_label'] ?: '—') }}</td>
                                <td class="pr-4">{{ $row['card'] }}</td>
                                <td class="pr-4">{{ $row['credits'] }}</td>
                                <td class="pr-4">{{ $row['trial'] }}</td>
                                <td class="pr-4">{{ $row['stopping'] }}</td>
                                <td class="pr-4">{{ $row['past_due'] }}</td>
                                <td>
                                    @if (! $check)
                                        <span class="text-neutral-500">No Stripe price</span>
                                    @elseif (! $check['ok'])
                                        <span class="text-amber-600">Not measured ({{ $check['failure'] }})</span>
                                    @elseif (! $plan['price_cents'])
                                        <span class="text-neutral-500">Stripe {{ $money($check['amount']) }}; no credit price set</span>
                                    @elseif ((int) $check['amount'] === (int) $plan['price_cents'])
                                        <span class="text-emerald-600">Matches Stripe</span>
                                    @else
                                        <span class="font-semibold text-red-600">Stripe charges {{ $money($check['amount']) }}, credits charge {{ $money($plan['price_cents']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if ($trialsEnding->isNotEmpty() || $failedCharges->isNotEmpty())
            <section class="application-settings-section">
                <div class="application-settings-section-header">
                    <div><h2>Needs attention</h2><p>Trials ending within 7 days, and credit renewals refused in the last 30.</p></div>
                </div>
                <div class="application-settings-section-body divide-y divide-neutral-200 text-sm dark:divide-white/[0.07]">
                    @foreach ($trialsEnding as $subscription)
                        <div class="flex justify-between gap-4 py-2">
                            <span>{{ $subscription->team?->name }} · trial of {{ data_get($plans->get($subscription->planKey()), 'name', $subscription->planKey()) }}</span>
                            <span class="text-neutral-500">ends {{ $subscription->trial_ends_at->toFormattedDateString() }}</span>
                        </div>
                    @endforeach
                    @foreach ($failedCharges as $charge)
                        <div class="flex justify-between gap-4 py-2">
                            <span>{{ $charge->team?->name }} · {{ $money($charge->amount_cents) }} for {{ $charge->period_start->toFormattedDateString() }}</span>
                            <span class="text-red-600">{{ $charge->failure }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div><h2>Recent credit charges</h2><p>The last 20 charges taken from gateway credits. Card payments are itemised in Stripe.</p></div>
            </div>
            <div class="application-settings-section-body divide-y divide-neutral-200 text-sm dark:divide-white/[0.07]">
                @forelse ($recentCharges as $charge)
                    <div class="flex justify-between gap-4 py-2">
                        <span>{{ $charge->team?->name }} · {{ data_get($plans->get($charge->plan_key), 'name', $charge->plan_key) }}</span>
                        <span>{{ $money($charge->amount_cents) }} · {{ $charge->created_at->toFormattedDateString() }}</span>
                    </div>
                @empty
                    <p class="py-2 text-neutral-500">No plan has been paid from credits yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
