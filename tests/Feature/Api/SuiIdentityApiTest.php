<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Services\Sui\SignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    // The SuiNS display lookup must never leave the test.
    Http::fake([
        'graphql.mainnet.sui.io/*' => Http::response([
            'data' => ['address' => ['suinsRegistration' => ['name' => 'gridframes-agent.sui']]],
        ]),
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;
});

function suiKeyPair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        'public' => sodium_crypto_sign_publickey($keypair),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];
}

function suiAddressOf(array $keypair): string
{
    return '0x'.bin2hex(sodium_crypto_generichash(chr(0x00).$keypair['public'], '', 32));
}

function suiWire(array $keypair, string $message): string
{
    // What a Sui wallet does: sign the personal-message digest of the exact bytes.
    $signature = sodium_crypto_sign_detached(SignatureVerifier::digest($message), $keypair['secret']);

    return base64_encode(chr(0x00).$signature.$keypair['public']);
}

function challengeAndVerify(Team $team, array $keypair, ?string $token = null): Illuminate\Testing\TestResponse
{
    $headers = $token ? ['Authorization' => 'Bearer '.$token] : [];

    $challenge = test()->postJson('/api/v1/team/sui/challenge', [], $headers);
    if ($challenge->status() !== 200) {
        return $challenge;
    }
    $message = $challenge->json('message');

    return test()->postJson('/api/v1/team/sui/verify', [
        'address' => suiAddressOf($keypair),
        'message' => $message,
        'signature' => suiWire($keypair, $message),
    ], $headers);
}

it('refuses the identity API without a token', function () {
    $this->getJson('/api/v1/team/sui')->assertStatus(401);
});

it('hands out a challenge to sign', function () {
    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/team/sui/challenge')
        ->assertStatus(200)
        ->assertJsonStructure(['message', 'expires_at', 'how']);
});

it('binds a proven address to the team and names it', function () {
    $keypair = suiKeyPair();

    $response = challengeAndVerify($this->team, $keypair, $this->bearerToken);

    $response->assertStatus(201)
        ->assertJsonPath('sui_address', suiAddressOf($keypair))
        ->assertJsonPath('sui_ns_name', 'gridframes-agent.sui');

    $this->withToken($this->bearerToken)->getJson('/api/v1/team/sui')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment(['sui_address' => suiAddressOf($keypair)]);
});

it('lets a wallet re-prove the same address without duplicating it', function () {
    $keypair = suiKeyPair();

    challengeAndVerify($this->team, $keypair, $this->bearerToken)->assertStatus(201);
    challengeAndVerify($this->team, $keypair, $this->bearerToken)->assertStatus(201);

    $this->withToken($this->bearerToken)->getJson('/api/v1/team/sui')->assertJsonCount(1);
});

it('refuses a proof when no challenge is outstanding', function () {
    $keypair = suiKeyPair();
    $message = 'a challenge nobody handed out';

    $this->withToken($this->bearerToken)->postJson('/api/v1/team/sui/verify', [
        'address' => suiAddressOf($keypair),
        'message' => $message,
        'signature' => suiWire($keypair, $message),
    ])->assertStatus(422)->assertJsonPath('error.kind', 'no-challenge');
});

it('consumes the challenge on a failed proof', function () {
    $keypair = suiKeyPair();

    $message = $this->withToken($this->bearerToken)
        ->postJson('/api/v1/team/sui/challenge')
        ->json('message');

    $this->withToken($this->bearerToken)->postJson('/api/v1/team/sui/verify', [
        'address' => suiAddressOf($keypair),
        'message' => $message,
        'signature' => suiWire(suiKeyPair(), $message), // right shape, wrong wallet.
    ])->assertStatus(422)->assertJsonPath('error.kind', 'bad-signature');

    // The spent challenge does not accept the honest proof; a fresh one is needed.
    $this->withToken($this->bearerToken)->postJson('/api/v1/team/sui/verify', [
        'address' => suiAddressOf($keypair),
        'message' => $message,
        'signature' => suiWire($keypair, $message),
    ])->assertStatus(422)->assertJsonPath('error.kind', 'no-challenge');
});

it('binds a soul-bound address to one team ever', function () {
    $keypair = suiKeyPair();

    challengeAndVerify($this->team, $keypair, $this->bearerToken)->assertStatus(201);

    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);
    $otherToken = $otherUser->createToken('other-token', ['*'])->plainTextToken;

    challengeAndVerify($otherTeam, $keypair, $otherToken)
        ->assertStatus(422)
        ->assertJsonPath('error.kind', 'address-taken');
});

it('frees the address for its own team alone after unbinding', function () {
    $keypair = suiKeyPair();
    challengeAndVerify($this->team, $keypair, $this->bearerToken)->assertStatus(201);

    $identityId = $this->withToken($this->bearerToken)->getJson('/api/v1/team/sui')->json('0.id');
    $this->withToken($this->bearerToken)->deleteJson("/api/v1/team/sui/{$identityId}")->assertSuccessful();
    $this->withToken($this->bearerToken)->getJson('/api/v1/team/sui')->assertJsonCount(0);

    // The same team may bind the address again; the row was never deleted, only released.
    challengeAndVerify($this->team, $keypair, $this->bearerToken)->assertStatus(201);

    $this->withToken($this->bearerToken)->deleteJson('/api/v1/team/sui/9999')->assertStatus(404);
});

it('caps how many addresses a team may bind', function () {
    config()->set('sui.max_per_team', 1);

    $response = challengeAndVerify($this->team, suiKeyPair(), $this->bearerToken);
    dump($response->json());
    $response->assertStatus(201);
    challengeAndVerify($this->team, suiKeyPair(), $this->bearerToken)
        ->assertStatus(422)
        ->assertJsonPath('error.kind', 'too-many');
});

it('asks for the write ability before handing out challenges', function () {
    $readToken = $this->user->createToken('read-token', ['read'])->plainTextToken;

    $this->withToken($readToken)->postJson('/api/v1/team/sui/challenge')->assertStatus(403);
    $this->withToken($readToken)->getJson('/api/v1/team/sui')->assertSuccessful();
});
