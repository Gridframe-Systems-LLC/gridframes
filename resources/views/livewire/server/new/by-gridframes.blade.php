<div class="w-full">
    @if (filled($machine_uuid))
        <div wire:poll.2s="poll">
            <x-application.settings-section title="Creating your machine"
                description="Your machine is being built on GridFrames hardware. This usually takes under two minutes.">
                @if ($machine_state === 'failed')
                    <x-callout type="error" title="The machine could not be created">
                        <p class="whitespace-pre-wrap break-words text-[11px]">{{ $machine_failure }}</p>
                    </x-callout>
                    <div class="mt-4">
                        <button type="button" class="button" wire:click="resetForm">Try again</button>
                    </div>
                @else
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading text="Building the machine..." />
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    @else
        <form wire:submit="submit">
            <x-application.settings-section title="Create a GridFrames machine"
                description="A virtual machine on GridFrames hardware, counted against your plan's pool.">
                <x-slot:actions>
                    <button type="submit" class="button button-highlighted">
                        Create machine
                        <x-reicon name="arrow-right" class="size-3.5" />
                    </button>
                </x-slot:actions>

                <div class="mb-5 grid gap-3 border-b border-neutral-200 pb-5 sm:grid-cols-2 lg:grid-cols-4 dark:border-white/[0.08]">
                    <div>
                        <p class="text-[11px] text-neutral-500 dark:text-fg-faint">vCPU</p>
                        <p class="text-sm font-semibold">{{ $pool['vcpu']['used'] }} / {{ $pool['vcpu']['total'] }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-neutral-500 dark:text-fg-faint">Memory</p>
                        <p class="text-sm font-semibold">
                            {{ $pool['memory_mb']['used'] }} / {{ $pool['memory_mb']['total'] }} MB
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] text-neutral-500 dark:text-fg-faint">Disk</p>
                        <p class="text-sm font-semibold">
                            {{ $pool['disk_gb']['used'] }} / {{ $pool['disk_gb']['total'] }} GB
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] text-neutral-500 dark:text-fg-faint">Machines</p>
                        <p class="text-sm font-semibold">{{ $pool['machines']['used'] }} / {{ $pool['machines']['total'] }}</p>
                    </div>
                </div>

                <div class="mb-5">
                    <x-forms.input id="name" label="Name" required
                        helper="Lowercase letters, numbers and hyphens. The name becomes the machine's hostname." />
                </div>

                <div class="mb-5">
                    @php
                        $sizeOptions = collect($sizes)
                            ->map(fn ($size, $key) => [
                                'value' => $key,
                                'label' => "{$key} · {$size['vcpu']} vCPU · {$size['memory_mb']} MB memory ({$size['shares']['memory']}% of pool) · {$size['disk_gb']} GB disk ({$size['shares']['disk']}% of pool)",
                            ])
                            ->values()
                            ->all();
                    @endphp
                    <x-forms.listbox id="size" label="Size" required placeholder="Pick a size"
                        emptyText="No size fits your remaining pool."
                        helper="Only sizes your plan's remaining pool can still fit are listed."
                        :options="$sizeOptions" />
                </div>

                <div class="grid gap-4 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                    <x-forms.textarea id="customer_ssh_key" label="Your SSH public key (optional)"
                        helper="Added to the machine alongside the platform key, so you can log in with your own key." />
                </div>
            </x-application.settings-section>
        </form>
    @endif
</div>
