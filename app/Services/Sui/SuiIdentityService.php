<?php

namespace App\Services\Sui;

use App\Exceptions\SuiProofException;
use App\Models\SuiIdentity;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Challenge and proof: the platform writes a challenge only this team has been handed, the customer
 * signs it with a Sui key it owns, and the address lands bound to the team.
 *
 * The challenge is a nonce in the cache; one outstanding challenge per team, single use, and a proof
 * consumes it whatever the outcome so a guess cannot be ground against it. An address binds to one
 * team ever — that is the soul in soul-bound; unbinding frees it only for nobody: a taken address
 * stays taken.
 */
class SuiIdentityService
{
    public function challenge(Team $team): array
    {
        $nonce = bin2hex(random_bytes(16));
        $message = "GridFrames identity proof\nTeam: {$team->name} (#{$team->id})\nNonce: {$nonce}";

        Cache::put($this->cacheKey($team), hash('sha256', $message), now()->addMinutes((int) config('sui.challenge_ttl')));

        return [
            'message' => $message,
            'expires_at' => now()->addMinutes((int) config('sui.challenge_ttl')),
        ];
    }

    /**
     * @throws SuiProofException When no challenge is outstanding, the answer does not match, the
     *                            signature does not verify, or the address is spoken for.
     */
    public function verify(Team $team, string $address, string $message, string $wireSignature): SuiIdentity
    {
        $address = SignatureVerifier::normalizeAddress($address);

        $expected = Cache::pull($this->cacheKey($team));
        if (! $expected) {
            throw new SuiProofException(SuiProofException::NO_CHALLENGE, 'No challenge is waiting. Ask for one, sign it, and send the proof.');
        }
        if (! hash_equals($expected, hash('sha256', $message))) {
            throw new SuiProofException(SuiProofException::WRONG_MESSAGE, 'That is not the challenge this team was given. Request a fresh one.');
        }

        $proof = SignatureVerifier::verify($message, $wireSignature);
        if ($proof['address'] !== $address) {
            throw new SuiProofException(SuiProofException::ADDRESS_MISMATCH, 'The signature belongs to a different address than the one claimed.');
        }

        $existing = SuiIdentity::where('sui_address', $address)->first();
        if ($existing && $existing->team_id !== $team->id) {
            throw new SuiProofException(SuiProofException::ADDRESS_TAKEN, 'That address is already bound, or has been bound, to another team. A soul-bound address binds one team, ever.');
        }

        if (! $existing && $team->suiIdentities()->whereNull('unbound_at')->count() >= (int) config('sui.max_per_team')) {
            throw new SuiProofException(SuiProofException::TOO_MANY, 'This team holds as many addresses as it may. Unbind one first.');
        }

        $identity = SuiIdentity::updateOrCreate(
            ['sui_address' => $address],
            [
                'team_id' => $team->id,
                'public_key' => bin2hex($proof['public_key']),
                'sui_ns_name' => null,
                'verified_at' => now(),
                'unbound_at' => null,
            ],
        );

        $name = $this->resolveName($address);
        if ($name) {
            $identity->update(['sui_ns_name' => $name]);
        }

        return $identity;
    }

    /**
     * The address's SuiNS name, for display only — never proof. A silent network reads as no name.
     */
    public function resolveName(string $address): ?string
    {
        return Cache::remember('sui-ns:'.$address, now()->addHour(), function () use ($address) {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->post((string) config('sui.graphql_url'), [
                        'query' => 'query ($address: SuiAddress!) { address(address: $address) { suinsRegistration { name } } }',
                        'variables' => ['address' => $address],
                        'operationName' => null,
                    ]);
                $name = $response->json('data.address.suinsRegistration.name');

                return is_string($name) && $name !== '' ? $name : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private function cacheKey(Team $team): string
    {
        return "sui-challenge:team:{$team->id}";
    }
}
