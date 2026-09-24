<?php

namespace App\Services\Sui;

use App\Exceptions\SuiProofException;

/**
 * The Sui pieces an identity proof needs: the personal-message digest a Sui key signs, the wire form
 * every wallet hands back, and the address a public key owns.
 *
 * A Sui wallet signs blake2b256(intent ‖ bcs(message)) with the ed25519 half of its key. The personal
 * message intent is [scope 3, version 0, app 0]; a message travels in BCS as its length in LEB128 and
 * then its bytes. The wire signature is flag ‖ signature ‖ public key, base64; only the plain ed25519
 * scheme (flag 0x00) is honoured here. An address is blake2b256(flag ‖ key). All of it is done with
 * libsodium's generichash, which is blake2b with a set digest length — the 256-bit form is not the
 * 512-bit one truncated.
 */
class SignatureVerifier
{
    public const SCHEME_ED25519 = 0x00;

    private const PERSONAL_MESSAGE_INTENT = "\x03\x00\x00";

    /**
     * The 32 bytes a Sui wallet's ed25519 half signs for this message.
     */
    public static function digest(string $message): string
    {
        return sodium_crypto_generichash(self::PERSONAL_MESSAGE_INTENT.self::leb128(strlen($message)).$message, '', 32);
    }

    /**
     * The address this public key owns: 0x and 64 hex characters.
     */
    public static function addressFromKey(string $publicKey): string
    {
        return '0x'.bin2hex(sodium_crypto_generichash(chr(self::SCHEME_ED25519).$publicKey, '', 32));
    }

    /**
     * Verify a wallet's answer to a challenge and name the address that signed it.
     *
     * @return array{address: string, public_key: string} The address is lowercase hex; the public key raw bytes.
     *
     * @throws SuiProofException When the wire form, the scheme, or the signature itself is no good.
     */
    public static function verify(string $message, string $wireSignature): array
    {
        $blob = self::decodeWire($wireSignature);
        if (strlen($blob) !== 97) {
            throw new SuiProofException(SuiProofException::BAD_SIGNATURE, 'That signature is not a Sui wallet signature (expected 97 bytes: flag, signature, public key).');
        }
        if (ord($blob[0]) !== self::SCHEME_ED25519) {
            throw new SuiProofException(SuiProofException::BAD_SIGNATURE, 'Only a plain ed25519 Sui key can prove an identity here.');
        }

        $signature = substr($blob, 1, 64);
        $publicKey = substr($blob, 65, 32);

        $ok = sodium_crypto_sign_verify_detached($signature, self::digest($message), $publicKey);
        if (! $ok) {
            throw new SuiProofException(SuiProofException::BAD_SIGNATURE, 'The signature does not match the message. Sign the challenge exactly as given.');
        }

        return [
            'address' => self::addressFromKey($publicKey),
            'public_key' => $publicKey,
        ];
    }

    /**
     * @throws SuiProofException When the address is not a Sui address at all.
     */
    public static function normalizeAddress(string $address): string
    {
        $trimmed = strtolower(trim($address));
        $bare = str_starts_with($trimmed, '0x') ? substr($trimmed, 2) : $trimmed;
        if (! preg_match('/^[0-9a-f]{64}$/', $bare)) {
            throw new SuiProofException(SuiProofException::ADDRESS_MISMATCH, 'That is not a Sui address (0x and 64 hex characters).');
        }

        return '0x'.$bare;
    }

    private static function decodeWire(string $wire): string
    {
        $decoded = base64_decode($wire, true);
        if ($decoded !== false) {
            return $decoded;
        }
        $hex = preg_replace('/^0x/i', '', trim($wire));
        if (is_string($hex) && ctype_xdigit($hex) && strlen($hex) % 2 === 0) {
            return (string) hex2bin($hex);
        }

        throw new SuiProofException(SuiProofException::BAD_SIGNATURE, 'The signature must be base64 or hex.');
    }

    private static function leb128(int $value): string
    {
        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($value > 0);

        return $out;
    }
}
