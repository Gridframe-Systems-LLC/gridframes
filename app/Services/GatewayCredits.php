<?php

namespace App\Services;

use App\Exceptions\CreditsPaymentException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Xlaunch gateway's platform door: whose account a key is, and charging that account.
 *
 * The platform holds a token the gateway issued for this purpose only. It never holds a customer's key:
 * the key is used once to find the account and is not stored.
 */
class GatewayCredits
{
    private function client(): PendingRequest
    {
        if (! creditsPaymentsEnabled()) {
            throw new CreditsPaymentException(CreditsPaymentException::NOT_CONFIGURED, 'Paying with credits is not available on this instance.');
        }

        return Http::baseUrl(config('subscription.credits.gateway_url').'/service-api')
            ->withToken((string) config('subscription.credits.token'))
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    /**
     * @return array{customerId:int, email:string, balanceMicros:int}
     */
    public function resolveKey(string $apiKey): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/accounts/resolve-key', ['apiKey' => $apiKey]));
        if ($response->status() === 404 || $response->status() === 400) {
            throw new CreditsPaymentException(CreditsPaymentException::UNKNOWN_KEY, 'That key does not belong to a gateway account, or it is disabled.');
        }
        $this->refuseUnlessOk($response);

        return [
            'customerId' => (int) $response->json('customerId'),
            'email' => (string) $response->json('email'),
            'balanceMicros' => (int) $response->json('balanceMicros'),
        ];
    }

    /**
     * Charge an account once under a reference the platform chooses. A retry with the same reference
     * returns the first result instead of charging again.
     *
     * @return array{applied:bool, chargeId:int, balanceMicros:int}
     */
    public function charge(int $customerId, int $amountCents, string $chargeRef, string $reason): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('/charges', [
            'customerId' => $customerId,
            'amountCents' => $amountCents,
            'chargeRef' => $chargeRef,
            'reason' => mb_substr($reason, 0, 200),
        ]));
        if ($response->status() === 402) {
            throw new CreditsPaymentException(
                CreditsPaymentException::INSUFFICIENT,
                'The gateway account does not hold enough credit. Top it up and try again.',
                (int) $response->json('balanceMicros'),
                (int) $response->json('requiredMicros'),
            );
        }
        $this->refuseUnlessOk($response);

        return [
            'applied' => (bool) $response->json('applied'),
            'chargeId' => (int) $response->json('chargeId'),
            'balanceMicros' => (int) $response->json('balanceMicros'),
        ];
    }

    private function send(callable $call): Response
    {
        try {
            return $call($this->client());
        } catch (ConnectionException $e) {
            throw new CreditsPaymentException(CreditsPaymentException::UNAVAILABLE, 'The gateway did not answer. Nothing was charged; try again shortly.');
        }
    }

    private function refuseUnlessOk(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        if ($response->serverError() || in_array($response->status(), [401, 429, 503], true)) {
            throw new CreditsPaymentException(CreditsPaymentException::UNAVAILABLE, 'The gateway could not take the payment right now. Nothing was charged; try again shortly.');
        }
        throw new CreditsPaymentException(CreditsPaymentException::REFUSED, (string) ($response->json('error.message') ?? 'The gateway refused the payment.'));
    }
}
