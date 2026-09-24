<?php

use App\Exceptions\SuiProofException;
use App\Services\Sui\SignatureVerifier;

// The constants below were computed once against libsodium by hand, outside this code: the ed25519
// keypair from the seed sha256('gridframes-test-vector'), the address that key owns, and a wallet
// signature over the message 'gridframes'. If the derivation or the digest input ever changes, these
// break — which is the point.
const VECTOR_ADDRESS = '0x510d398c51437466089e5069f3608dfaea091c120ef09534b7c49a8effa23df4';
const VECTOR_PUBLIC_KEY_HEX = '84465373ce05d420a26633a84ba8345feb276a4d9f8c6edeb6352e85b3e8a6f8';
const VECTOR_WIRE_SIGNATURE = 'ACvMGbV9y95UoT9W1/N+jFEwJhZjPaWEzuXR/cqIKv+PPTGjM6AaMGLvHC8QIaHDU+efybwaonCoA2Y8tIEj+AmERlNzzgXUIKJmM6hLqDRf6ydqTZ+Mbt62NS6Fs+im+A==';

function vectorKeyPair(): array
{
    $keypair = sodium_crypto_sign_seed_keypair(hash('sha256', 'gridframes-test-vector', true));

    return [
        'public' => sodium_crypto_sign_publickey($keypair),
        'secret' => sodium_crypto_sign_secretkey($keypair),
    ];
}

it('hashes with blake2b-256 exactly as RFC 7693 specifies', function () {
    expect(bin2hex(sodium_crypto_generichash('abc', '', 32)))
        ->toBe('bddd813c634239723171ef3fee98579b94964e3bb1cb3e427262c8c068d52319');
});

it('derives the address a public key owns', function () {
    expect(SignatureVerifier::addressFromKey(vectorKeyPair()['public']))
        ->toBe(VECTOR_ADDRESS);
});

it('verifies a wallet proof computed outside this code', function () {
    $proof = SignatureVerifier::verify('gridframes', VECTOR_WIRE_SIGNATURE);

    expect($proof['address'])->toBe(VECTOR_ADDRESS)
        ->and(bin2hex($proof['public_key']))->toBe(VECTOR_PUBLIC_KEY_HEX);
});

it('length-prefixes long messages with two LEB128 bytes', function () {
    $long = str_repeat('gridframes-', 20); // 220 bytes: 220 = 0xdc 0x01 in LEB128.
    $expected = sodium_crypto_generichash("\x03\x00\x00"."\xdc\x01".$long, '', 32);

    expect(bin2hex(SignatureVerifier::digest($long)))->toBe(bin2hex($expected));
});

it('round trips a long message end to end', function () {
    $long = str_repeat('gridframes-', 20);
    $signature = sodium_crypto_sign_detached(SignatureVerifier::digest($long), vectorKeyPair()['secret']);
    $wire = base64_encode(chr(0x00).$signature.vectorKeyPair()['public']);

    $proof = SignatureVerifier::verify($long, $wire);

    expect($proof['address'])->toBe(VECTOR_ADDRESS);
});

it('accepts a hex wire signature', function () {
    $raw = base64_decode(VECTOR_WIRE_SIGNATURE, true);
    $proof = SignatureVerifier::verify('gridframes', '0x'.bin2hex($raw));

    expect($proof['address'])->toBe(VECTOR_ADDRESS);
});

it('refuses a signature over a different message', function () {
    SignatureVerifier::verify('a different message', VECTOR_WIRE_SIGNATURE);
})->throws(SuiProofException::class);

it('refuses a tampered signature', function () {
    $raw = base64_decode(VECTOR_WIRE_SIGNATURE, true);
    $raw[10] = $raw[10] === 'a' ? 'b' : 'a';
    SignatureVerifier::verify('gridframes', base64_encode($raw));
})->throws(SuiProofException::class);

it('refuses a signature whose scheme is not plain ed25519', function () {
    $raw = base64_decode(VECTOR_WIRE_SIGNATURE, true);
    $raw[0] = "\x02"; // secp256r1.
    SignatureVerifier::verify('gridframes', base64_encode($raw));
})->throws(SuiProofException::class);

it('refuses a blob that is not a wallet signature', function () {
    SignatureVerifier::verify('gridframes', base64_encode('too short'));
})->throws(SuiProofException::class);

it('refuses input that is neither base64 nor hex', function () {
    SignatureVerifier::verify('gridframes', 'not a signature at all!!!');
})->throws(SuiProofException::class);

it('normalizes addresses and refuses things that are not addresses', function () {
    expect(SignatureVerifier::normalizeAddress('0X'.strtoupper(substr(VECTOR_ADDRESS, 2))))
        ->toBe(VECTOR_ADDRESS)
        ->and(SignatureVerifier::normalizeAddress(substr(VECTOR_ADDRESS, 2)))
        ->toBe(VECTOR_ADDRESS);

    SignatureVerifier::normalizeAddress('0x1234');
})->throws(SuiProofException::class);
