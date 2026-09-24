<x-application.settings-section title="Soul-bound identity"
    description="Bind a Sui address you own to this team by signing the platform's challenge with its key. The key never leaves its owner; only the signature arrives. Agents do the same over the API — see the agent documentation at /llms.txt.">
    <div class="space-y-1 text-sm">
        @forelse ($suiIdentities as $identity)
            <div class="flex items-center justify-between gap-4 py-1" wire:key="sui-{{ $identity['id'] }}">
                <div class="min-w-0">
                    <span class="break-all font-mono text-xs dark:text-white">{{ $identity['sui_address'] }}</span>
                    @if ($identity['sui_ns_name'])
                        <span class="text-neutral-500">· {{ $identity['sui_ns_name'] }}</span>
                    @endif
                    <div class="text-xs text-neutral-500">verified {{ $identity['verified_at'] }}</div>
                </div>
                <x-forms.button wire:click="removeSuiIdentity({{ $identity['id'] }})">Unbind</x-forms.button>
            </div>
        @empty
            <p class="text-neutral-500">No address is bound to this team yet.</p>
        @endforelse
    </div>
    <div class="mt-4 space-y-3">
        @if ($suiChallenge)
            <div>
                <label class="mb-1.5 block text-xs font-bold uppercase tracking-wide text-neutral-500">1 · Sign this
                    message</label>
                <pre class="overflow-x-auto rounded-lg border border-neutral-200 p-3 text-xs dark:border-white/[0.08]">{{ $suiChallenge }}</pre>
                <p class="mt-1 text-xs text-neutral-500">Sign it as a Sui personal message with the wallet that owns the
                    address. The challenge expires {{ $suiChallengeExpiresAt }}, and one attempt consumes it — ask for a
                    fresh one after a failed try.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block">2 · Address</label>
                    <x-forms.input type="text" wire:model="suiAddress" placeholder="0x…" />
                </div>
                <div>
                    <label class="mb-1.5 block">3 · Signature</label>
                    <x-forms.input type="text" wire:model="suiSignature" placeholder="base64 flag ‖ signature ‖ public key" />
                </div>
            </div>
            <div class="flex gap-2">
                <x-forms.button isHighlighted wire:click="verifySuiIdentity">Prove this address</x-forms.button>
                <x-forms.button wire:click="startSuiChallenge">New challenge</x-forms.button>
            </div>
        @else
            <x-forms.button wire:click="startSuiChallenge">Bind a Sui address</x-forms.button>
        @endif
    </div>
</x-application.settings-section>
