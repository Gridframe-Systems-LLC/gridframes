<div class="w-full">
    <x-application.settings-section title="Choose a plan"
        description="Pick the plan for what you run. Machines are counted per team; change plans any time.">

        @if ($plans->isEmpty())
            <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                No plan is on sale on this instance yet. Ask the operator.
            </p>
        @else
            @php
                // Literal class names, so the stylesheet build keeps every one of them.
                $columns = [1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3', 4 => 'lg:grid-cols-2 xl:grid-cols-4'][min($plans->count(), 4)];
            @endphp
            <div class="grid gap-6 {{ $columns }}">
                @foreach ($plans as $key => $plan)
                    <div wire:key="plan-{{ $key }}"
                        class="flex flex-col rounded-[10px] border border-neutral-200 bg-neutral-50 p-4 dark:border-white/[0.08] dark:bg-white/[0.05]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">
                            {{ $plan['name'] }}</p>
                        <div class="mt-2 flex items-end gap-1.5">
                            <span class="text-2xl font-semibold tracking-tight">{{ $plan['price_label'] }}</span>
                            <span class="pb-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">per month</span>
                        </div>
                        <p class="mt-2 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">{{ $plan['tagline'] }}</p>

                        <div class="mt-4 divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @foreach (array_values(array_filter([
                                'Up to '.$plan['servers'].' '.Str::plural('machine', $plan['servers']).' on this account',
                                filled($plan['machine'] ?? null) ? $plan['machine'] : null,
                                'Deploy anything: containers, databases, agents',
                                'Automatic HTTPS for every address',
                                $key === 'team' ? 'Invite your team, one account' : 'Email notifications',
                            ])) as $feature)
                                <div class="flex min-h-9 items-center gap-2.5 py-1.5 text-[12px]">
                                    <x-reicon name="check-circle" class="size-4 shrink-0 text-emerald-500" />
                                    <span>{{ $feature }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-auto space-y-2 pt-4">
                            @if ($plan['by_card'])
                                <x-forms.button class="w-full justify-center" wire:click="subscribeStripe('{{ $key }}')"
                                    isHighlighted>
                                    {{ $trialDays > 0 ? 'Start '.$trialDays.'-day free trial' : 'Start '.$plan['name'] }}
                                </x-forms.button>
                            @endif
                            @if ($plan['by_credits'])
                                @if ($creditsPlan === $key)
                                    <form wire:submit="subscribeCredits" class="space-y-2">
                                        <x-forms.input type="password" id="gatewayKey" label="GridFrames gateway API key"
                                            required
                                            helper="Used once to find your gateway account, then discarded. Only the account is kept, and the plan is charged to it each month." />
                                        <x-forms.button type="submit" class="w-full justify-center">
                                            {{ $trialDays > 0 ? 'Start trial, then pay from credits' : 'Pay '.$plan['price_label'].' from credits' }}
                                        </x-forms.button>
                                    </form>
                                @else
                                    <x-forms.button class="w-full justify-center" wire:click="chooseCredits('{{ $key }}')">
                                        Pay with gateway credits
                                    </x-forms.button>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="mt-5 text-[11px] leading-5 text-neutral-500 dark:text-fg-faint">
            @if ($trialDays > 0)
                Every plan starts with a {{ $trialDays }}-day free trial, once per team.
            @endif
            @if ($plans->contains(fn ($plan) => $plan['by_card']))
                Card payment through Stripe.
            @endif
            @if ($plans->contains(fn ($plan) => $plan['by_credits']))
                Or pay from GridFrames gateway credits, which an agent can top up in SUI.
            @endif
            Prices exclude applicable taxes. Cancel any time from your subscription page.
        </p>
    </x-application.settings-section>
</div>
