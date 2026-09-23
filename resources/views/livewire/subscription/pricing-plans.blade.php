<div class="w-full">
    <x-application.settings-section title="Choose a plan"
        description="Pick the plan for what you run. Machines are counted per team; change plans any time.">

        @if ($plans->isEmpty())
            <p class="text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">
                No plan is on sale on this instance yet. Ask the operator.
            </p>
        @else
            <div class="grid gap-6 lg:grid-cols-{{ min($plans->count(), 3) }}">
                @foreach ($plans as $key => $plan)
                    <div
                        class="flex flex-col rounded-[10px] border border-neutral-200 bg-neutral-50 p-4 dark:border-white/[0.08] dark:bg-white/[0.05]">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">
                            {{ $plan['name'] }}</p>
                        <div class="mt-2 flex items-end gap-1.5">
                            <span class="text-2xl font-semibold tracking-tight">{{ $plan['price_label'] }}</span>
                            <span class="pb-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">
                                per month{{ $key === 'team' ? ', per member' : '' }}</span>
                        </div>
                        <p class="mt-2 text-[12px] leading-5 text-neutral-500 dark:text-fg-dim">{{ $plan['tagline'] }}</p>

                        <div class="mt-4 divide-y divide-neutral-200 dark:divide-white/[0.07]">
                            @foreach ([
                                'Up to '.$plan['servers'].' '.Str::plural('machine', $plan['servers']).' on this account',
                                'Deploy anything: containers, databases, agents',
                                'Automatic HTTPS for every address',
                                $key === 'team' ? 'Invite your team, one account' : 'Email notifications',
                                $key === 'team' ? 'Priority support by email' : 'Support by email',
                            ] as $feature)
                                <div class="flex min-h-9 items-center gap-2.5 py-1.5 text-[12px]">
                                    <x-reicon name="check-circle" class="size-4 shrink-0 text-emerald-500" />
                                    <span>{{ $feature }}</span>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-auto pt-4">
                            <x-forms.button class="w-full justify-center" wire:click="subscribeStripe('{{ $key }}')"
                                isHighlighted>
                                Start {{ $plan['name'] }}
                            </x-forms.button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <p class="mt-5 text-[11px] leading-5 text-neutral-500 dark:text-fg-faint">
            Card payment through Stripe. Prices exclude applicable taxes. Cancel any time from your subscription page.
        </p>
    </x-application.settings-section>
</div>
