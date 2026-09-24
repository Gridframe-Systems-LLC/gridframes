<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A credit payment that did not happen, with a message a customer can act on.
 *
 * `kind` separates "the gateway said no" from "we could not ask": an account that does not hold enough
 * credit and a gateway that did not answer call for opposite next steps, so they are never one error.
 */
class CreditsPaymentException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not-configured';

    public const UNKNOWN_KEY = 'unknown-key';

    public const INSUFFICIENT = 'insufficient';

    public const UNAVAILABLE = 'unavailable';

    public const REFUSED = 'refused';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $balanceMicros = null,
        public readonly ?int $requiredMicros = null,
    ) {
        parent::__construct($message);
    }
}
