<div>
    <x-slot:title>
        {{ $title }} | Xlaunch
    </x-slot>

    <livewire:server.create :selected-type="$type" :selected-token-uuid="$token_uuid" />
</div>
