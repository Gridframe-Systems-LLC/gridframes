<?php

namespace App\Jobs\Machines;

use App\Enums\ProxyTypes;
use App\Exceptions\MachineException;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\Machine;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\Machines\IncusClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Build the machine the CreateMachine action recorded: the Incus project, the instance, its
 * addresses, and finally the ordinary Server the rest of the platform deploys to. Anything that
 * fails leaves the machine in state failed with the reason, and takes the instance — and any half
 * made server — back down with it.
 */
class ProvisionMachineJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The bounded wait for the agent and an IPv4 is 120 seconds; the rest is Incus calls and the
    // server row, so three minutes covers the whole run with room to spare.
    public $timeout = 300;

    // A retried run would build a second instance next to the first, which the failure path has
    // already deleted or cannot see; provisioning starts exactly once.
    public int $tries = 1;

    public function __construct(
        public Machine $machine,
        public int $privateKeyId,
        public ?string $customerPublicKey = null,
    ) {}

    public function handle(IncusClient $incus): void
    {
        $machine = $this->machine;
        $instanceCreated = false;
        $server = null;

        try {
            $project = $incus->ensureProject($machine->team)['project'];

            $incus->waitForOperation(
                $incus->createInstance($project, $machine->incus_name, $machine->size_key, $this->cloudInit())['operation'],
                60,
            );
            $instanceCreated = true;

            $incus->waitForOperation(
                $incus->start($project, $machine->incus_name)['operation'],
                60,
            );

            ['ipv4' => $ipv4, 'ipv6' => $ipv6] = $this->waitForNetwork($incus, $project, $machine->incus_name);
            $machine->update([
                'ipv4_private' => $ipv4,
                'ipv6_public' => $ipv6,
            ]);

            $privateKey = PrivateKey::findOrFail($this->privateKeyId);

            // The same shape ByHetzner's Server::create makes: the platform reaches the machine over
            // its private IPv4 as root on port 22 with the key made at creation.
            $server = Server::create([
                'name' => $machine->name,
                'ip' => $ipv4,
                'user' => 'root',
                'port' => 22,
                'team_id' => $machine->team_id,
                'private_key_id' => $privateKey->id,
                'description' => "GridFrames machine ({$machine->size_key})",
            ]);
            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            $machine->update(['server_id' => $server->id]);

            // Docker ships in the golden image, so validation should pass without an install.
            ValidateAndInstallServerJob::dispatch($server);

            $machine->update(['state' => 'running']);
        } catch (\Throwable $e) {
            $this->fail($e, $server, $instanceCreated, $incus);
        }
    }

    /**
     * The platform's key for root, the customer's key if they brought one, the machine's name as the
     * hostname, and nothing else: the machine is Ubuntu with Docker already in the golden image.
     */
    private function cloudInit(): string
    {
        $publicKey = PrivateKey::findOrFail($this->privateKeyId)->getPublicKey();
        $keys = [$publicKey];
        if (filled($this->customerPublicKey)) {
            $keys[] = trim((string) $this->customerPublicKey);
        }
        $authorised = implode("\n", array_map(fn (string $key) => "  - {$key}", $keys));

        return "#cloud-config\nhostname: {$this->machine->name}\nssh_authorized_keys:\n{$authorised}\n";
    }

    /**
     * Wait, bounded, for the guest agent to answer and the tenant bridge to hand the machine its
     * addresses: a global-scope IPv4 is the agent reporting in, and the public IPv6 is the host's
     * routed /64. Nothing past 120 seconds is worth a queue slot; the caller is told it timed out.
     *
     * @return array{ipv4:string, ipv6:?string}
     */
    private function waitForNetwork(IncusClient $incus, string $project, string $name): array
    {
        $deadline = microtime(true) + 120;

        do {
            $state = $incus->state($project, $name);
            $ipv4 = $this->pickAddress($state['addresses'], 'inet');
            $ipv6 = $this->pickAddress($state['addresses'], 'inet6');

            if ($ipv4 !== null) {
                return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
            }

            sleep(2);
        } while (microtime(true) < $deadline);

        throw new MachineException(MachineException::TIMEOUT, "Machine '{$name}' did not come up on the network in time. Try again, and contact support if it keeps happening.");
    }

    /**
     * The machine's own address on the bridge: global scope, the right family, and for IPv6 not a
     * link-local one.
     *
     * @param  list<array{interface:string, family:string, address:string, scope:string}>  $addresses
     */
    private function pickAddress(array $addresses, string $family): ?string
    {
        foreach ($addresses as $address) {
            if ($address['family'] !== $family || $address['scope'] !== 'global') {
                continue;
            }
            if ($family === 'inet6' && str_starts_with(strtolower($address['address']), 'fe80:')) {
                continue;
            }

            return $address['address'];
        }

        return null;
    }

    /**
     * Record the failure with its reason, and take down everything the run left behind: the half
     * made server row, then the Incus instance if one was created. What gets removed is logged
     * before it is removed, and a host that will not answer for the cleanup is reported, not
     * swallowed.
     */
    private function fail(\Throwable $e, ?Server $server, bool $instanceCreated, IncusClient $incus): void
    {
        $machine = $this->machine;

        $reason = $e instanceof MachineException
            ? $e->getMessage()
            : 'The machine could not be created: '.$e->getMessage();
        $machine->update([
            'state' => 'failed',
            'failure' => $reason,
        ]);

        if ($server !== null) {
            Log::warning('ProvisionMachineJob: removing the half-made server', [
                'machine_id' => $machine->id,
                'server_id' => $server->id,
            ]);
            $server->delete();
        }

        if ($instanceCreated) {
            Log::warning('ProvisionMachineJob: removing the failed instance', [
                'machine_id' => $machine->id,
                'project' => $machine->incus_project,
                'instance' => $machine->incus_name,
            ]);
            try {
                $incus->waitForOperation(
                    $incus->delete($machine->incus_project, $machine->incus_name)['operation'],
                    60,
                );
            } catch (MachineException $cleanup) {
                // An instance already gone is the cleanup done; anything else must be seen.
                if ($cleanup->kind !== MachineException::NOT_FOUND) {
                    report($cleanup);
                }
            }
        }

        Log::error('ProvisionMachineJob: machine provisioning failed', [
            'machine_id' => $machine->id,
            'failure' => $reason,
        ]);
    }
}
