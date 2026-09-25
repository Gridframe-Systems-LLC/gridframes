<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An Incus call that did not do what the platform asked, with a message a customer can act on.
 *
 * `kind` separates "the host is not connected" from "we could not ask" and "the host said no": an
 * instance that was never set up, a network that dropped, and a full or busy host call for opposite
 * next steps, so they are never one error.
 */
class MachineException extends RuntimeException
{
    public const UNCONFIGURED = 'unconfigured';

    public const TRANSPORT = 'transport';

    public const TIMEOUT = 'timeout';

    public const REJECTED = 'rejected';

    public const NOT_FOUND = 'not-found';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}
