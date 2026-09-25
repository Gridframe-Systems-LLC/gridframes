<?php

namespace App\Livewire\Server\New;

use App\Actions\Machines\CreateMachine;
use App\Exceptions\MachineException;
use App\Models\Machine;
use App\Models\Server;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The GridFrames machine screen: a name, a size that still fits the plan's pool, and an optional
 * extra SSH key. Submitting records the machine and queues its provisioning; the screen then polls
 * the machine row's state until the machine is running or has failed.
 */
class ByGridFrames extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $size = '';

    public string $customer_ssh_key = '';

    #[Locked]
    public ?string $machine_uuid = null;

    #[Locked]
    public string $machine_state = '';

    #[Locked]
    public ?string $machine_failure = null;

    public function mount(): mixed
    {
        if (! CreateMachine::availableFor(currentTeam())) {
            return $this->redirectRoute('server.create', navigate: true);
        }

        try {
            $this->authorize('create', Server::class);
            $this->name = generate_random_name();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:63',
                'regex:/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/',
            ],
            'size' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! array_key_exists((string) $value, $this->fittingSizes())) {
                        $fail('That size does not fit the remaining pool. Pick one that does.');
                    }
                },
            ],
            'customer_ssh_key' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Please name your machine.',
            'name.regex' => 'The name may only contain lowercase letters, numbers and hyphens, and must start and end with a letter or number.',
            'size.required' => 'Please pick a size.',
        ];
    }

    public function submit(): ?bool
    {
        $this->validate();

        try {
            $this->authorize('create', Server::class);

            $machine = (new CreateMachine)->handle(
                currentTeam(),
                $this->name,
                $this->size,
                acceptOverage: false,
                customerPublicKey: filled($this->customer_ssh_key) ? trim($this->customer_ssh_key) : null,
            );

            $this->machine_uuid = $machine->uuid;
            $this->machine_state = $machine->state;
            $this->machine_failure = null;
        } catch (MachineException $e) {
            $this->dispatch('error', $e->getMessage());
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    /**
     * The provisioning runs in a queue; the screen follows the machine row until it lands as a
     * running server, or shows the reason it failed.
     */
    public function poll(): mixed
    {
        if (blank($this->machine_uuid)) {
            return null;
        }

        $machine = Machine::where('uuid', $this->machine_uuid)
            ->where('team_id', currentTeam()->id)
            ->first();
        if (! $machine) {
            return null;
        }

        $this->machine_state = $machine->state;
        $this->machine_failure = $machine->failure;

        if ($machine->state === 'running' && $machine->server) {
            return redirectRoute($this, 'server.show', [$machine->server->uuid]);
        }

        return null;
    }

    /**
     * A failed provisioning leaves the reason on the screen and the form ready for another try.
     */
    public function resetForm(): void
    {
        $this->machine_uuid = null;
        $this->machine_state = '';
        $this->machine_failure = null;
        $this->name = generate_random_name();
        $this->size = '';
    }

    /**
     * The plan's pool and what the team's live machines hold of it, per resource.
     *
     * @return array{vcpu:array{used:int,total:int}, memory_mb:array{used:int,total:int}, disk_gb:array{used:int,total:int}, machines:array{used:int,total:int}}
     */
    public function getPoolProperty(): array
    {
        $team = currentTeam();
        $pool = CreateMachine::poolFor($team) ?? ['vcpu' => 0, 'memory_mb' => 0, 'disk_gb' => 0, 'machines' => 0];
        $usage = CreateMachine::poolUsage($team);

        return [
            'vcpu' => ['used' => $usage['vcpu'], 'total' => $pool['vcpu']],
            'memory_mb' => ['used' => $usage['memory_mb'], 'total' => $pool['memory_mb']],
            'disk_gb' => ['used' => $usage['disk_gb'], 'total' => $pool['disk_gb']],
            'machines' => ['used' => $usage['machines'], 'total' => $pool['machines']],
        ];
    }

    /**
     * Only the sizes the remaining pool can still fit, each with the share of the pool it would use.
     *
     * @return array<string, array{vcpu:int, memory_mb:int, disk_gb:int, shares:array{vcpu:int, memory:int, disk:int}}>
     */
    public function getFittingSizesProperty(): array
    {
        $pool = CreateMachine::poolFor(currentTeam());
        if ($pool === null) {
            return [];
        }
        $usage = CreateMachine::poolUsage(currentTeam());

        $sizes = [];
        foreach (config('machines.sizes', []) as $key => $size) {
            $size = [
                'vcpu' => (int) $size['vcpu'],
                'memory_mb' => (int) $size['memory_mb'],
                'disk_gb' => (int) $size['disk_gb'],
            ];
            if ($pool['vcpu'] < $usage['vcpu'] + $size['vcpu']
                || $pool['memory_mb'] < $usage['memory_mb'] + $size['memory_mb']
                || $pool['disk_gb'] < $usage['disk_gb'] + $size['disk_gb']) {
                continue;
            }
            $size['shares'] = [
                'vcpu' => $this->shareOf($size['vcpu'], $pool['vcpu']),
                'memory' => $this->shareOf($size['memory_mb'], $pool['memory_mb']),
                'disk' => $this->shareOf($size['disk_gb'], $pool['disk_gb']),
            ];
            $sizes[$key] = $size;
        }

        return $sizes;
    }

    public function render()
    {
        return view('livewire.server.new.by-gridframes', [
            'pool' => $this->pool,
            'sizes' => $this->fittingSizes,
        ]);
    }

    private function shareOf(int $amount, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) round($amount / $total * 100);
    }
}
