<?php

namespace App\Livewire\Server;

use App\Actions\Machines\CreateMachine;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Team;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public ?string $selectedType = null;

    public ?string $selectedTokenUuid = null;

    public bool $limit_reached = false;

    public bool $has_hetzner_tokens = false;

    public bool $machines_available = false;

    public function mount(?string $selectedType = null, ?string $selectedTokenUuid = null): void
    {
        $this->selectedType = in_array($selectedType, ['hetzner', 'vultr', 'digital-ocean', 'manual', 'gridframes'], true)
            ? $selectedType
            : null;
        $this->selectedTokenUuid = $this->selectedType && $this->selectedType !== 'manual' && $this->selectedType !== 'gridframes'
            ? $selectedTokenUuid
            : null;

        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
        $this->machines_available = currentTeam() instanceof Team && CreateMachine::availableFor(currentTeam());
        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();

        // Check if user has Hetzner tokens
        $this->has_hetzner_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->exists();
    }

    public function render()
    {
        return view('livewire.server.create');
    }
}
