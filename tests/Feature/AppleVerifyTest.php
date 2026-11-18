<?php

declare(strict_types=1);

use BijayX\IapGuard\Apple\AppleConfig;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Facades\IapGuard;

it('normalizes every valid signed transaction exactly like the fixtures', function (string $name) {
    $purchase = IapGuard::verify(['platform' => 'apple', 'signedTransaction' => appleJws($name)]);

    expect(normalized($purchase))->toBe(appleFixture('transactions', $name)['expected'])
        ->and($purchase->raw['transaction'])->toBe(appleFixture('transactions', $name)['payload']);
})->with(fn () => array_keys(array_filter(iapFixture('apple/jws')['transactions'], fn ($t) => isset($t['expected']))));

it('rejects invalid transactions with the expected error code', function (string $name) {
    $e = catchIap(fn () => guard()->verify(PurchaseProof::appleSignedTransaction(appleJws($name))));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe(appleFixture('transactions', $name)['expectedError']);
})->with(fn () => array_keys(array_filter(iapFixture('apple/jws')['transactions'], fn ($t) => isset($t['expectedError']))));

it('rejects every forged, tampered or malformed JWS with INVALID_SIGNATURE', function (string $name) {
    $e = catchIap(fn () => guard()->verify(PurchaseProof::appleSignedTransaction(iapFixture('apple/jws')['invalidSignatures'][$name])));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe('INVALID_SIGNATURE')
        ->and($e->getMessage())->not->toContain('eyJ');
})->with(fn () => array_keys(iapFixture('apple/jws')['invalidSignatures']));

it('rejects Apple-signed data when the bundled Apple root is the trust anchor', function () {
    // The fixtures are signed by a test root: with the real Apple Root CA - G3 they must fail.
    $e = catchIap(fn () => guard(['apple.root_certificates' => null])->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive'))));

    expect($e->errorCode())->toBe('INVALID_SIGNATURE');
});

it('accepts root certificates as PEM contents or DER', function () {
    $pem = (string) file_get_contents(dirname(__DIR__).'/fixtures/apple/certs/root.pem');
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));

    expect(guard(['apple.root_certificates' => [$pem]])->verify(PurchaseProof::appleSignedTransaction(appleJws('nonConsumable')))->key)->toBe('2000000000000200')
        ->and(guard(['apple.root_certificates' => $der])->verify(PurchaseProof::appleSignedTransaction(appleJws('nonConsumable')))->key)->toBe('2000000000000200');
});

it('rejects signed data from a disallowed environment', function () {
    $e = catchIap(fn () => guard(['apple.environments' => ['production']])->verify(PurchaseProof::appleSignedTransaction(appleJws('sandbox'))));

    expect($e)->toBeInstanceOf(PolicyException::class)
        ->and($e->errorCode())->toBe('ENVIRONMENT_NOT_ALLOWED');
});

it('rejects Family Sharing when disabled', function () {
    $e = catchIap(fn () => guard(['apple.allow_family_sharing' => false])->verify(PurchaseProof::appleSignedTransaction(appleJws('familyShared'))));

    expect($e->errorCode())->toBe('FAMILY_SHARING_NOT_ALLOWED');
});

it('enforces the product allowlist', function () {
    $guard = guard(['products' => ['premium_monthly']]);

    expect($guard->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')))->productId)->toBe('premium_monthly');

    $e = catchIap(fn () => $guard->verify(PurchaseProof::appleSignedTransaction(appleJws('nonConsumable'))));

    expect($e)->toBeInstanceOf(PolicyException::class)
        ->and($e->errorCode())->toBe('UNKNOWN_PRODUCT');
});

it('requires the App Store Server API to verify by transaction id', function () {
    guard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);
})->throws(ConfigurationException::class);

it('requires a bundle id', function () {
    guard(['apple.bundle_id' => null])->verify(PurchaseProof::appleSignedTransaction(appleJws('nonConsumable')));
})->throws(ConfigurationException::class, 'apple.bundle_id');

it('defaults to the bundled Apple root and both environments', function () {
    $config = AppleConfig::fromArray(['bundle_id' => 'com.example.app']);

    expect($config->rootCertificates[0])->toContain('BEGIN CERTIFICATE')
        ->and(array_map(fn ($e) => $e->value, $config->environments))->toBe(['production', 'sandbox'])
        ->and($config->hasApi())->toBeFalse();
});

it('reports a partially configured App Store Server API', function () {
    AppleConfig::fromArray(['bundle_id' => 'x', 'api' => ['issuer_id' => 'i', 'key_id' => 'k']]);
})->throws(ConfigurationException::class, 'apple.api.private_key');
