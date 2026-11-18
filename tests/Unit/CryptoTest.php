<?php

declare(strict_types=1);

use BijayX\IapGuard\Apple\AppleConfig;
use BijayX\IapGuard\Crypto\Asn1;
use BijayX\IapGuard\Crypto\Base64Url;
use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Crypto\EcdsaSignature;
use BijayX\IapGuard\Crypto\Jws;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Crypto\Keys;

it('ships the real Apple Root CA - G3', function () {
    $pem = (string) file_get_contents(AppleConfig::BUNDLED_ROOT);
    $fingerprint = strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($pem, 'sha256'), 2)));

    expect($fingerprint)->toBe('63:34:3A:BF:B8:9A:6A:03:EB:B5:7E:9B:3F:5F:A7:BE:7C:4F:5C:75:6F:30:17:B3:A8:C4:88:C3:65:3E:91:79')
        ->and(openssl_x509_parse($pem)['subject']['CN'])->toBe('Apple Root CA - G3');
});

it('encodes and strictly decodes base64url', function () {
    $bytes = random_bytes(37)."\xfb\xff";

    expect(Base64Url::decode(Base64Url::encode($bytes)))->toBe($bytes)
        ->and(Base64Url::encode("\xfb\xff"))->toBe('-_8');

    expect(fn () => Base64Url::decode('abc+'))->toThrow(CryptoException::class)
        ->and(fn () => Base64Url::decode('a'))->toThrow(CryptoException::class);
});

it('converts ES256 signatures between raw r||s and DER', function () {
    $key = Keys::privateKey((string) file_get_contents(dirname(__DIR__).'/fixtures/apple/keys/api-private-key.p8'));

    foreach (range(1, 20) as $i) {
        openssl_sign("message {$i}", $der, $key, OPENSSL_ALGO_SHA256);
        $raw = EcdsaSignature::derToRaw($der);

        expect(strlen($raw))->toBe(64)
            ->and(EcdsaSignature::rawToDer($raw))->toBe($der);
    }
});

it('rejects ES256 signatures that are not 64 bytes', function (int $length) {
    EcdsaSignature::rawToDer(str_repeat("\x01", $length));
})->with([0, 63, 65, 72])->throws(CryptoException::class);

it('encodes object identifiers in DER', function () {
    expect(bin2hex(Asn1::objectIdentifier('1.2.840.113549.1.1.1')))->toBe('06092a864886f70d010101')
        ->and(bin2hex(Asn1::length(300)))->toBe('82012c');
});

it('signs ES256 JWTs that verify with the public key', function () {
    $key = Keys::privateKey((string) file_get_contents(dirname(__DIR__).'/fixtures/apple/keys/api-private-key.p8'));
    $public = openssl_pkey_get_public(openssl_pkey_get_details($key)['key']);

    $jwt = Jwt::signEs256(['alg' => 'ES256'], ['a' => 1], $key);
    $parsed = Jws::parse($jwt);

    expect(strlen($parsed->signature))->toBe(64)
        ->and(Jwt::verifyEs256($parsed->signingInput, $parsed->signature, $public))->toBeTrue()
        ->and(Jwt::verifyEs256($parsed->signingInput.'x', $parsed->signature, $public))->toBeFalse();
});

it('builds an RSA public key from a JWK modulus and exponent', function () {
    $jwk = iapFixture('google/oidc')['jwks']['keys'][0];
    $key = Keys::rsaPublicKeyFromJwk($jwk['n'], $jwk['e']);
    $token = Jws::parse(iapFixture('google/oidc')['tokens']['valid']);

    expect(Jwt::verifyRs256($token->signingInput, $token->signature, $key))->toBeTrue()
        ->and(openssl_pkey_get_details($key)['bits'])->toBe(2048);
});

it('matches the service-account public key when built from the same modulus', function () {
    $details = openssl_pkey_get_details(openssl_pkey_get_public((string) file_get_contents(dirname(__DIR__).'/fixtures/google/service-account.public.pem')));
    $key = Keys::rsaPublicKeyFromJwk(Base64Url::encode($details['rsa']['n']), Base64Url::encode($details['rsa']['e']));

    expect(openssl_pkey_get_details($key)['key'])->toBe($details['key']);
});

it('parses only compact JWS with JSON object segments', function (string $token) {
    Jws::parse($token);
})->with([
    'two segments' => 'a.b',
    'four segments' => 'eyJhIjoxfQ.eyJhIjoxfQ.c.d',
    'array header' => Base64Url::encode('[1]').'.'.Base64Url::encode('{}').'.',
    'not json' => Base64Url::encode('nope').'.'.Base64Url::encode('{}').'.',
])->throws(CryptoException::class);
