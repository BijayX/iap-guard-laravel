<?php

declare(strict_types=1);

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\PurchaseStatus;
use BijayX\IapGuard\Exceptions\ValidationException;
use Illuminate\Support\Carbon;

function purchaseWith(array $overrides): Purchase
{
    return Purchase::fromArray($overrides + appleFixture('transactions', 'subscriptionActive')['expected']);
}

it('round-trips through toArray() and fromArray()', function () {
    $expected = appleFixture('transactions', 'subscriptionActive')['expected'];
    $purchase = Purchase::fromArray($expected + ['raw' => ['transaction' => ['a' => 1]]]);

    expect(normalized($purchase))->toBe($expected)
        ->and(Purchase::fromArray($purchase->toArray()))->toEqual($purchase)
        ->and(json_decode((string) json_encode($purchase), true)['signedAt'])->toBe('2026-11-18T11:00:00.000Z');
});

it('implements isEntitled() per the spec', function (array $overrides, bool $entitled) {
    expect(purchaseWith($overrides)->isEntitled(Carbon::parse('2026-11-18T12:00:00Z')))->toBe($entitled);
})->with([
    'active, not expired' => [['status' => 'active', 'expiresAt' => '2026-11-18T12:00:00.001Z'], true],
    'active, expiring now' => [['status' => 'active', 'expiresAt' => '2026-11-18T12:00:00.000Z'], false],
    'active, no expiry' => [['status' => 'active', 'expiresAt' => null], true],
    'grace, grace date in the future' => [['status' => 'grace_period', 'expiresAt' => '2026-11-17T00:00:00.000Z', 'gracePeriodExpiresAt' => '2026-11-19T00:00:00.000Z'], true],
    'grace, grace date passed' => [['status' => 'grace_period', 'expiresAt' => '2026-12-01T00:00:00.000Z', 'gracePeriodExpiresAt' => '2026-11-18T00:00:00.000Z'], false],
    'grace, falls back to expiresAt' => [['status' => 'grace_period', 'expiresAt' => '2026-11-19T00:00:00.000Z', 'gracePeriodExpiresAt' => null], true],
    'grace, no dates' => [['status' => 'grace_period', 'expiresAt' => null, 'gracePeriodExpiresAt' => null], true],
    'billing retry' => [['status' => 'billing_retry'], false],
    'pending' => [['status' => 'pending'], false],
    'paused' => [['status' => 'paused'], false],
    'revoked' => [['status' => 'revoked', 'expiresAt' => null], false],
    'expired' => [['status' => 'expired'], false],
    'canceled' => [['status' => 'canceled'], false],
]);

it('uses the Laravel clock for isEntitled() by default', function () {
    $purchase = purchaseWith(['status' => 'active', 'expiresAt' => '2026-11-18T13:00:00.000Z']);

    expect($purchase->isEntitled())->toBeTrue();

    Carbon::setTestNow('2026-11-18T13:00:00Z');

    expect($purchase->isEntitled())->toBeFalse();
});

it('marks a purchase revoked', function () {
    $revoked = purchaseWith([])->withRevoked(Carbon::parse('2026-11-18T12:00:00Z'));

    expect($revoked->status)->toBe(PurchaseStatus::Revoked)
        ->and($revoked->toArray()['revokedAt'])->toBe('2026-11-18T12:00:00.000Z');
});

it('validates proofs', function (array $input, string $field) {
    try {
        PurchaseProof::fromArray($input);
    } catch (ValidationException $e) {
        expect($e->fields())->toHaveKey($field)
            ->and($e->errorCode())->toBe('VALIDATION_ERROR');

        return;
    }

    $this->fail('No ValidationException thrown.');
})->with([
    'unknown platform' => [['platform' => 'amazon'], 'platform'],
    'apple without proof' => [['platform' => 'apple'], 'signedTransaction'],
    'apple with both proofs' => [['platform' => 'apple', 'signedTransaction' => 'x', 'transactionId' => '1'], 'signedTransaction'],
    'apple non-numeric id' => [['platform' => 'apple', 'transactionId' => 'abc'], 'transactionId'],
    'google without type' => [['platform' => 'google', 'purchaseToken' => 't'], 'type'],
    'google bad type' => [['platform' => 'google', 'type' => 'inapp', 'purchaseToken' => 't'], 'type'],
    'google without token' => [['platform' => 'google', 'type' => 'subscription'], 'purchaseToken'],
    'google token not a string' => [['platform' => 'google', 'type' => 'subscription', 'purchaseToken' => ['x']], 'purchaseToken'],
]);

it('accepts snake_case proof keys', function () {
    $proof = PurchaseProof::fromArray(['platform' => 'google', 'type' => 'one_time', 'purchase_token' => 'tok', 'product_id' => 'coins_100']);

    expect($proof->purchaseToken)->toBe('tok')
        ->and($proof->productId)->toBe('coins_100')
        ->and($proof->isGoogleSubscription())->toBeFalse();
});
