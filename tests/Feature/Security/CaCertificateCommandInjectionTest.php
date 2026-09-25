<?php

use App\Livewire\Server\CaCertificate\Show;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The settings layout the component renders reads the instance settings row;
    // id 0 is not fillable, so it must be force-created exactly like the installer does.
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // The server factory points private_key_id at id 1 without creating it; the save
    // path loads the key to build its SSH commands, so give the server a real one.
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    // The suite pins certificate sanitization, not SSH transport: skip the synchronous
    // multiplex precheck and keep the remote write/regeneration jobs off the queue.
    config(['constants.ssh.mux_enabled' => false]);
    Queue::fake();
});

function generateSelfSignedCert(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $csr = openssl_csr_new(['CN' => 'Test CA'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 365);
    openssl_x509_export($cert, $certPem);

    return $certPem;
}

test('saveCaCertificate sanitizes injected commands after certificate marker', function () {
    $validCert = generateSelfSignedCert();

    $caCert = SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => $validCert,
        'ssl_private_key' => 'test-key',
        'common_name' => 'Xlaunch CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    // Inject shell command after valid certificate
    $maliciousContent = $validCert."' ; id > /tmp/pwned ; echo '";

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', $maliciousContent)
        ->call('saveCaCertificate')
        ->assertDispatched('success');

    // After save, the certificate should be the clean re-exported PEM, not the malicious input
    $caCert->refresh();
    expect($caCert->ssl_certificate)->not->toContain('/tmp/pwned');
    expect($caCert->ssl_certificate)->not->toContain('; id');
    expect($caCert->ssl_certificate)->toContain('-----BEGIN CERTIFICATE-----');
    expect($caCert->ssl_certificate)->toEndWith("-----END CERTIFICATE-----\n");
});

test('saveCaCertificate rejects completely invalid certificate', function () {
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => 'placeholder',
        'ssl_private_key' => 'test-key',
        'common_name' => 'Xlaunch CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', "not-a-cert'; rm -rf /; echo '")
        ->call('saveCaCertificate')
        ->assertDispatched('error');
});

test('saveCaCertificate rejects empty certificate content', function () {
    SslCertificate::create([
        'server_id' => $this->server->id,
        'is_ca_certificate' => true,
        'ssl_certificate' => 'placeholder',
        'ssl_private_key' => 'test-key',
        'common_name' => 'Xlaunch CA Certificate',
        'valid_until' => now()->addYears(10),
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('certificateContent', '')
        ->call('saveCaCertificate')
        ->assertDispatched('error');
});
