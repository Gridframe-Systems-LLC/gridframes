<?php

namespace App\Services\Machines;

use App\Exceptions\MachineException;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Incus 7.0 host's REST API, spoken with the platform's client certificate against the pinned
 * server certificate. Thin on purpose: one method per Incus call, each returning the piece of the
 * answer the platform needs or throwing a MachineException whose kind says what went wrong and whose
 * message a customer can act on.
 */
class IncusClient
{
    /**
     * Create a virtual machine from the golden image, sized to the plan key, seeded with cloud-init.
     *
     * @return array{operation:string}
     */
    public function createInstance(string $project, string $name, string $sizeKey, string $cloudInit): array
    {
        $size = $this->size($sizeKey);

        // POST /1.0/instances?project=<project> — the body carries the instance type, the image alias,
        // the CPU, memory and cloud-init config, and the root disk on the pool.
        $response = $this->send(fn (PendingRequest $http) => $http->post('/1.0/instances?project='.rawurlencode($project), [
            'name' => $name,
            'type' => 'virtual-machine',
            'source' => ['type' => 'image', 'alias' => (string) config('machines.incus.image')],
            'config' => [
                'limits.cpu' => (string) $size['vcpu'],
                'limits.memory' => $size['memory_mb'].'MiB',
                'cloud-init.user-data' => $cloudInit,
            ],
            'devices' => [
                'root' => [
                    'type' => 'disk',
                    'pool' => (string) config('machines.incus.pool'),
                    'path' => '/',
                    'size' => $size['disk_gb'].'GiB',
                ],
            ],
        ]));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        return ['operation' => $this->operation($response)];
    }

    /**
     * @return array{operation:string}
     */
    public function start(string $project, string $name): array
    {
        return $this->changeState($project, $name, 'start');
    }

    /**
     * @return array{operation:string}
     */
    public function stop(string $project, string $name): array
    {
        return $this->changeState($project, $name, 'stop');
    }

    /**
     * @return array{operation:string}
     */
    public function restart(string $project, string $name): array
    {
        return $this->changeState($project, $name, 'restart');
    }

    /**
     * The instance's live readings. A reading the host does not have comes back as null, never zero:
     * what was not measured must not look like a quiet machine.
     *
     * @return array{status:string, memory_bytes:int|null, disk_bytes:int|null, addresses:list<array{interface:string, family:string, address:string, scope:string}>, network:array<string, array{bytes_received:int|null, bytes_sent:int|null, packets_received:int|null, packets_sent:int|null}>}
     */
    public function state(string $project, string $name): array
    {
        // GET /1.0/instances/{name}/state?project=<project> — the answer's metadata holds the status,
        // the memory and disk usage, and per-interface addresses and counters.
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->instanceUrl($project, $name, 'state')));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        $metadata = $response->json('metadata');
        if (! is_array($metadata)) {
            throw new MachineException(MachineException::REJECTED, "The host answered for machine '{$name}' without a state. Try again shortly.");
        }

        $addresses = [];
        $network = [];
        foreach ($metadata['network'] ?? [] as $interface => $interfaceState) {
            $interface = (string) $interface;
            foreach ($interfaceState['addresses'] ?? [] as $address) {
                $addresses[] = [
                    'interface' => $interface,
                    'family' => (string) ($address['family'] ?? ''),
                    'address' => (string) ($address['address'] ?? ''),
                    'scope' => (string) ($address['scope'] ?? ''),
                ];
            }
            $network[$interface] = [
                'bytes_received' => isset($interfaceState['counters']['bytes_received']) ? (int) $interfaceState['counters']['bytes_received'] : null,
                'bytes_sent' => isset($interfaceState['counters']['bytes_sent']) ? (int) $interfaceState['counters']['bytes_sent'] : null,
                'packets_received' => isset($interfaceState['counters']['packets_received']) ? (int) $interfaceState['counters']['packets_received'] : null,
                'packets_sent' => isset($interfaceState['counters']['packets_sent']) ? (int) $interfaceState['counters']['packets_sent'] : null,
            ];
        }

        return [
            'status' => (string) ($metadata['status'] ?? ''),
            'memory_bytes' => isset($metadata['memory']['usage']) ? (int) $metadata['memory']['usage'] : null,
            'disk_bytes' => isset($metadata['disk']['root']['usage']) ? (int) $metadata['disk']['root']['usage'] : null,
            'addresses' => $addresses,
            'network' => $network,
        ];
    }

    /**
     * Take a disk snapshot of the instance. An expiry, when given, is the Incus `expires_at` the
     * host itself honours: the snapshot outlives the instance exactly as long as it was told to.
     *
     * @return array{operation:string}
     */
    public function snapshot(string $project, string $name, string $snapshotName, ?CarbonInterface $expiresAt = null): array
    {
        // POST /1.0/instances/{name}/snapshots?project=<project> — `stateful` false keeps the disks,
        // not the machine's RAM, so it restores onto a stopped or running VM alike; `expires_at`
        // (an RFC 3339 timestamp) has the host prune the snapshot itself when the time is out.
        $body = [
            'name' => $snapshotName,
            'stateful' => false,
        ];
        if ($expiresAt !== null) {
            $body['expires_at'] = $expiresAt->toIso8601String();
        }
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->instanceUrl($project, $name, 'snapshots'), $body));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        return ['operation' => $this->operation($response)];
    }

    /**
     * Roll the instance back to one of its snapshots.
     *
     * @return array{operation:string}
     */
    public function restore(string $project, string $name, string $snapshotName): array
    {
        // PUT /1.0/instances/{name}?project=<project> — the `restore` field names the snapshot the
        // instance is rolled back to.
        $response = $this->send(fn (PendingRequest $http) => $http->put($this->instanceUrl($project, $name), [
            'restore' => $snapshotName,
        ]));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        return ['operation' => $this->operation($response)];
    }

    /**
     * Remove the instance and its disk. Nothing here snapshots first: a caller that deletes without a
     * final copy has already decided to.
     *
     * @return array{operation:string}
     */
    public function delete(string $project, string $name): array
    {
        // DELETE /1.0/instances/{name}?project=<project> — the removal runs as an operation the caller
        // can wait for.
        $response = $this->send(fn (PendingRequest $http) => $http->delete($this->instanceUrl($project, $name)));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        return ['operation' => $this->operation($response)];
    }

    /**
     * The team's project and its own bridge, created when the host does not have them yet and left
     * alone when it does. Networks are a per-project feature, so the bridge is looked up inside the
     * team's project: tenants cannot see each other's machines.
     *
     * @return array{project:string, network:string}
     */
    public function ensureProject(Team $team): array
    {
        $project = "team-{$team->id}";
        $network = "gf-{$project}";

        // GET /1.0/projects/{name} — check for the team's project before creating it.
        $found = $this->send(fn (PendingRequest $http) => $http->get("/1.0/projects/{$project}"));
        if ($found->status() === 404) {
            // POST /1.0/projects — a project per team, with the networks feature so the team gets its
            // own bridge instead of the host's default one.
            $this->tolerateExisting($this->send(fn (PendingRequest $http) => $http->post('/1.0/projects', [
                'name' => $project,
                'description' => "GridFrames machines for team {$team->id}",
                'config' => ['features.networks' => 'true'],
            ])), "project '{$project}'");
        } else {
            $this->refuseUnlessOk($found, "Project '{$project}'");
        }

        // GET /1.0/networks/{name}?project=<project> — the team's bridge, looked up in its own project.
        $found = $this->send(fn (PendingRequest $http) => $http->get("/1.0/networks/{$network}?project=".rawurlencode($project)));
        if ($found->status() === 404) {
            // POST /1.0/networks?project=<project> — the team's own bridge; Incus picks a free IPv4
            // subnet and NATs it, and the host routes the team's IPv6 out of its own /64.
            $this->tolerateExisting($this->send(fn (PendingRequest $http) => $http->post('/1.0/networks?project='.rawurlencode($project), [
                'name' => $network,
                'type' => 'bridge',
                'config' => ['ipv4.nat' => 'true'],
            ])), "network '{$network}'");
        } else {
            $this->refuseUnlessOk($found, "Network '{$network}'");
        }

        return ['project' => $project, 'network' => $network];
    }

    /**
     * Wait for an Incus operation to reach a final state. The host holds each call up to the timeout
     * it is given; this loops until the overall budget runs out.
     *
     * @return array{status:string, err:string|null}
     */
    public function waitForOperation(string $operationUrl, int $timeout): array
    {
        $deadline = microtime(true) + $timeout;
        do {
            // Each wait call stays under the client's own 10-second HTTP timeout, so a patient host
            // holding the call cannot be mistaken for a connection that died.
            $seconds = (int) max(1, ceil(min(8, $deadline - microtime(true))));

            // GET {operationUrl}/wait?timeout=<seconds> — a sync answer whose metadata is the
            // operation: status code 200 done, 400 failed, 401 cancelled, anything else in flight.
            $response = $this->send(fn (PendingRequest $http) => $http->get($operationUrl.'/wait', ['timeout' => $seconds]));
            $this->refuseUnlessOk($response, 'The host task');

            $statusCode = (int) ($response->json('metadata.status_code') ?? 0);
            if ($statusCode === 200) {
                return [
                    'status' => (string) ($response->json('metadata.status') ?? ''),
                    'err' => trim((string) ($response->json('metadata.err') ?? '')) ?: null,
                ];
            }
            if ($statusCode >= 400) {
                $err = trim((string) ($response->json('metadata.err') ?? ''));

                throw new MachineException(MachineException::REJECTED, $err === '' ? 'The host could not finish the work.' : "The host could not finish the work: {$err}");
            }
        } while (microtime(true) < $deadline);

        throw new MachineException(MachineException::TIMEOUT, 'The host did not finish the work in time. Try again, and contact support if it keeps happening.');
    }

    /**
     * @return array{operation:string}
     */
    private function changeState(string $project, string $name, string $action): array
    {
        // PUT /1.0/instances/{name}/state?project=<project> — the action is start, stop or restart;
        // the host gives up on the change itself after its 30 seconds and says so in the operation.
        $response = $this->send(fn (PendingRequest $http) => $http->put($this->instanceUrl($project, $name, 'state'), [
            'action' => $action,
            'timeout' => 30,
            'force' => false,
            'stateful' => false,
        ]));
        $this->refuseUnlessOk($response, "Machine '{$name}'");

        return ['operation' => $this->operation($response)];
    }

    private function instanceUrl(string $project, string $name, string $suffix = ''): string
    {
        $path = '/1.0/instances/'.rawurlencode($name).($suffix !== '' ? '/'.$suffix : '');

        return $path.'?project='.rawurlencode($project);
    }

    /**
     * The plan's size for a machine: its CPU, memory and root disk are Incus limits. Sizes are
     * configuration, so an unknown key is an instance that is not set up to sell it.
     *
     * @return array{vcpu:int, memory_mb:int, disk_gb:int}
     */
    private function size(string $sizeKey): array
    {
        $size = config("machines.sizes.{$sizeKey}");
        if (! is_array($size)) {
            throw new MachineException(MachineException::UNCONFIGURED, "Machine size '{$sizeKey}' is not offered on this instance. An operator has to add it to the sizes first.");
        }

        return [
            'vcpu' => (int) $size['vcpu'],
            'memory_mb' => (int) $size['memory_mb'],
            'disk_gb' => (int) $size['disk_gb'],
        ];
    }

    private function client(): PendingRequest
    {
        $this->unconfigured();

        return Http::baseUrl((string) config('machines.incus.url'))
            // The platform authenticates with its own certificate against the pinned server
            // certificate; Guzzle takes all three as paths. The server certificate is a self-signed
            // pin: it is the identity, so the hostname check it would otherwise fail against an
            // endpoint addressed by IP is switched off — the pin carries the whole trust decision.
            ->withOptions([
                'cert' => (string) config('machines.incus.client_cert'),
                'ssl_key' => (string) config('machines.incus.client_key'),
                'verify' => (string) config('machines.incus.server_cert'),
                'curl' => [CURLOPT_SSL_VERIFYHOST => 0],
            ])
            ->acceptJson()
            ->timeout(10);
    }

    /**
     * Fail before any request when this instance has no Incus endpoint or certificates to speak to it.
     */
    private function unconfigured(): void
    {
        foreach (['url', 'client_cert', 'client_key', 'server_cert'] as $key) {
            if (trim((string) config("machines.incus.{$key}")) === '') {
                throw new MachineException(MachineException::UNCONFIGURED, 'Machines are not set up on this instance yet. An operator has to connect the Incus host first.');
            }
        }
    }

    private function send(callable $call): Response
    {
        try {
            return $call($this->client());
        } catch (ConnectionException $e) {
            if (str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'timeout')) {
                throw new MachineException(MachineException::TIMEOUT, 'The host running the machines did not answer in time. Try again shortly.');
            }

            throw new MachineException(MachineException::TRANSPORT, 'The host running the machines could not be reached. Try again shortly, and contact support if it keeps happening.');
        }
    }

    /**
     * An Incus error answer names what went wrong in `error`; 404 means the thing is gone, and every
     * other refusal is the host saying no to a request that reached it.
     */
    private function refuseUnlessOk(Response $response, string $subject): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->status() === 404) {
            throw new MachineException(MachineException::NOT_FOUND, "{$subject} is no longer on the host. It may already have been deleted.");
        }
        $detail = trim((string) ($response->json('error') ?? ''));
        throw new MachineException(
            MachineException::REJECTED,
            $detail === '' ? "The host refused the request for {$subject}." : "The host refused the request for {$subject}: {$detail}",
        );
    }

    /**
     * A create call is idempotent in practice: two provisions racing on the same team both try to
     * make the project or the bridge, and the loser's "already exists" is a win.
     */
    private function tolerateExisting(Response $response, string $subject): void
    {
        $detail = strtolower(trim((string) ($response->json('error') ?? '')));
        if ($response->successful() || ($detail !== '' && str_contains($detail, 'already exists'))) {
            return;
        }
        $this->refuseUnlessOk($response, $subject);
    }

    /**
     * The URL of the operation a call started, so the caller can wait for it.
     */
    private function operation(Response $response): string
    {
        $operation = trim((string) ($response->json('operation') ?? ''));
        if ($operation === '') {
            throw new MachineException(MachineException::REJECTED, 'The host accepted the work but returned nothing to follow it. Try again shortly.');
        }

        return $operation;
    }
}
