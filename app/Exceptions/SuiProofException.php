<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Sui address proof that did not verify, with a message the customer can act on.
 *
 * `kind` separates "sign again" from "this address is taken": the first asks for a fresh challenge,
 * the second is final and no amount of retrying lifts it.
 */
class SuiProofException extends RuntimeException
{
    public const NO_CHALLENGE = 'no-challenge';

    public const WRONG_MESSAGE = 'wrong-message';

    public const BAD_SIGNATURE = 'bad-signature';

    public const ADDRESS_MISMATCH = 'address-mismatch';

    public const ADDRESS_TAKEN = 'address-taken';

    public const TOO_MANY = 'too-many';

    public function __construct(
        public readonly string $kind,
        string $message,
    ) {
        parent::__construct($message);
    }
}
