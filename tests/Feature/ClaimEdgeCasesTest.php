<?php

declare(strict_types=1);

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\ClaimResult;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Ownership;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Events\IapNotificationProcessed;
use BijayX\IapGuard\Events\PurchaseClaimed;
use BijayX\IapGuard\Events\PurchaseOwnershipTransferred;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\OwnershipException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\ValidationException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\IapGuard;
use BijayX\IapGuard\Tests\Support\InterleavingStore;
use Illuminate\Support\Facades\Event;

function claimApple(string $userId, string $transaction, ?IapGuard $guard = null): ClaimResult
{
    return ($guard ?? guard())->claim($userId, PurchaseProof::appleSignedTransaction(appleJws($transaction)));
}

function claimGoogleSub(string $userId, string $token, ?IapGuard $guard = null): ClaimResult
{
    return ($guard ?? guard())->claim($userId, ['platform' => 'google', 'type' => 'subscription', 'purchaseToken' => $token]);
}

function useInterleavingStore(): InterleavingStore
{
    $store = new InterleavingStore(app(PurchaseStore::class));
    app()->instance(PurchaseStore::class, $store);
    app()->forgetInstance(IapGuard::class);

    return $store;
}

it('#1 restores on a second device of the same user: existing, not new, entitled', function () {
    $first = claimApple('user-1', 'subscriptionActive');
    $second = claimApple('user-1', 'subscriptionActive');

    expect($first->ownership)->toBe(Ownership::Created)
        ->and($first->isNew)->toBeTrue()
        ->and($second->ownership)->toBe(Ownership::Existing)
        ->and($second->isNew)->toBeFalse()
        ->and($second->isEntitled)->toBeTrue()
        ->and($second->previousUserId)->toBeNull();
});

it('#2 gives an iOS subscriber access in the Android app without a Google purchase', function () {
    claimApple('user-1', 'subscriptionActive');

    $entitlements = guard()->entitlements('user-1');

    expect($entitlements)->toHaveCount(1)
        ->and($entitlements[0]->platform)->toBe(Platform::Apple)
        ->and($entitlements[0]->productId)->toBe('premium_monthly');
});

it('#3 rejects a second app account restoring the same store purchase (reject policy)', function () {
    claimApple('user-1', 'subscriptionNoToken');

    $e = catchIap(fn () => claimApple('user-2', 'subscriptionNoToken'));

    expect($e)->toBeInstanceOf(OwnershipException::class)
        ->and($e->errorCode())->toBe('ALREADY_OWNED')
        ->and($e->getMessage())->not->toContain('user-1')
        ->and(json_encode($e->context()))->not->toContain('user-1')
        ->and(store()->findByKey(Platform::Apple, '2000000000000100')?->userId)->toBe('user-1');
});

it('#3 moves the purchase to the second app account (transfer policy)', function () {
    Event::fake([PurchaseOwnershipTransferred::class, PurchaseClaimed::class]);
    $guard = guard(['ownership.conflict_policy' => 'transfer']);
    claimApple('user-1', 'subscriptionNoToken', $guard);

    $result = claimApple('user-2', 'subscriptionNoToken', $guard);

    expect($result->ownership)->toBe(Ownership::Transferred)
        ->and($result->previousUserId)->toBe('user-1')
        ->and($result->isNew)->toBeFalse()
        ->and($guard->entitlements('user-1'))->toBe([])
        ->and($guard->entitlements('user-2'))->toHaveCount(1);
    Event::assertDispatched(PurchaseOwnershipTransferred::class, fn ($e) => $e->fromUserId === 'user-1' && $e->toUserId === 'user-2' && $e->purchase->key === '2000000000000100');
    Event::assertDispatchedTimes(PurchaseClaimed::class, 2);
});

it('#4 rejects a purchase bound to another user\'s account token (reject policy)', function () {
    claimApple('user-1', 'subscriptionActive');

    expect(catchIap(fn () => claimApple('user-2', 'subscriptionActive'))->errorCode())->toBe('ACCOUNT_MISMATCH');
});

it('#4 rejects a mismatched account token even before anyone claimed it', function () {
    expect(catchIap(fn () => claimApple('user-2', 'subscriptionActive'))->errorCode())->toBe('ACCOUNT_MISMATCH')
        ->and(store()->findByKey(Platform::Apple, '2000000000000001'))->toBeNull();
});

it('#4 transfers despite the account token under the transfer policy', function () {
    $guard = guard(['ownership.conflict_policy' => 'transfer']);
    claimApple('user-1', 'subscriptionActive', $guard);

    $result = claimApple('user-2', 'subscriptionActive', $guard);

    expect($result->ownership)->toBe(Ownership::Transferred)
        ->and($result->previousUserId)->toBe('user-1');
});

it('#4 skips the account-token check when no secret is configured', function () {
    $guard = guard(['ownership.account_token_secret' => null]);

    expect(claimApple('user-2', 'subscriptionActive', $guard)->ownership)->toBe(Ownership::Created);
});

it('#4 matches account tokens case-insensitively', function () {
    // subscriptionUser2 carries user-2's token in lowercase; claiming as user-2 succeeds.
    expect(claimApple('user-2', 'subscriptionUser2')->ownership)->toBe(Ownership::Created);
});

it('#5 lets one of two devices that claim at the same moment create the record (lost insert race)', function () {
    $store = useInterleavingStore();
    $other = guard()->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));

    // The other device's insert lands between our findByKey() and insert().
    $store->beforeInsert[] = fn (PurchaseStore $inner) => $inner->insert(PurchaseRecord::create($other, 'user-1', [], now()->toImmutable()));

    $result = claimApple('user-1', 'subscriptionActive');

    expect($store->insertResults)->toBe([false])
        ->and($store->replaceResults)->toBe([true])
        ->and($result->ownership)->toBe(Ownership::Existing)
        ->and($result->isNew)->toBeFalse()
        ->and(store()->findByUser('user-1'))->toHaveCount(1);
});

it('#5 never gives a purchase two owners when another user wins the insert race', function () {
    $store = useInterleavingStore();
    $other = guard()->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionNoToken')));
    $store->beforeInsert[] = fn (PurchaseStore $inner) => $inner->insert(PurchaseRecord::create($other, 'user-1', [], now()->toImmutable()));

    expect(catchIap(fn () => claimApple('user-2', 'subscriptionNoToken'))->errorCode())->toBe('ALREADY_OWNED')
        ->and(store()->findByKey(Platform::Apple, '2000000000000100')?->userId)->toBe('user-1');
});

it('#5 retries a versioned replace that lost a race', function () {
    claimApple('user-1', 'subscriptionActive');
    $store = useInterleavingStore();

    // A webhook bumps the version between our read and our replace.
    $store->beforeReplace[] = function (PurchaseStore $inner) {
        $record = $inner->findByKey(Platform::Apple, '2000000000000001');
        $inner->replace($record->next(now()->toImmutable()), $record->version);
    };

    $result = claimApple('user-1', 'subscriptionActive');

    expect($store->replaceResults)->toBe([false, true])
        ->and($result->ownership)->toBe(Ownership::Existing)
        ->and(store()->findByKey(Platform::Apple, '2000000000000001')?->version)->toBe(3); // insert, webhook, retried claim
});

it('#5 gives up with CONCURRENT_MODIFICATION after three lost races', function () {
    claimApple('user-1', 'subscriptionActive');
    $store = useInterleavingStore();
    $bump = function (PurchaseStore $inner) {
        $record = $inner->findByKey(Platform::Apple, '2000000000000001');
        $inner->replace($record->next(now()->toImmutable()), $record->version);
    };
    $store->beforeReplace = [$bump, $bump, $bump];

    $e = catchIap(fn () => claimApple('user-1', 'subscriptionActive'));

    expect($e)->toBeInstanceOf(IapException::class)
        ->and($e->errorCode())->toBe('CONCURRENT_MODIFICATION')
        ->and($store->replaceResults)->toBe([false, false, false]);
});

it('#6 reports a replayed consumable proof as not new (Apple)', function () {
    $first = claimApple('user-1', 'consumable');
    $replay = claimApple('user-1', 'consumable');

    expect($first->isNew)->toBeTrue()
        ->and($first->purchase->key)->toBe('2000000000000300')
        ->and($first->purchase->quantity)->toBe(2)
        ->and($replay->isNew)->toBeFalse()
        ->and($replay->ownership)->toBe(Ownership::Existing);
});

it('#6 reports a replayed consumable token as not new (Google)', function () {
    fakePlay(products: ['gp-prod-token-coins' => 'consumable']);

    $first = guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-coins'));
    $replay = guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-coins'));

    expect($first->isNew)->toBeTrue()
        ->and($replay->isNew)->toBeFalse();
});

it('#7 runs ownership checks on a stale subscription JWS but keeps the newer stored state', function () {
    claimApple('user-1', 'subscriptionActive');

    $stale = claimApple('user-1', 'subscriptionOld');
    $record = store()->findByKey(Platform::Apple, '2000000000000001');

    expect($stale->ownership)->toBe(Ownership::Existing)
        ->and($stale->isNew)->toBeFalse()
        ->and($stale->purchase->transactionId)->toBe('2000000000000002')
        ->and($stale->isEntitled)->toBeTrue()
        ->and($record?->purchase->transactionId)->toBe('2000000000000002')
        ->and($record?->lastEventAt->toIso8601ZuluString('millisecond'))->toBe('2026-11-18T11:00:00.000Z');

    expect(catchIap(fn () => guard(['ownership.account_token_secret' => null])->claim('user-2', PurchaseProof::appleSignedTransaction(appleJws('subscriptionOld'))))->errorCode())
        ->toBe('ALREADY_OWNED');
});

it('#8 keeps a Google upgrade chain (token-1 → token-2 → token-3) as one record with one owner', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active', 'gp-sub-token-2' => 'upgraded', 'gp-sub-token-3' => 'upgradedAgain']);

    $one = claimGoogleSub('user-1', 'gp-sub-token-1');
    $two = claimGoogleSub('user-1', 'gp-sub-token-2');
    $three = claimGoogleSub('user-1', 'gp-sub-token-3');

    expect([$one->purchase->key, $two->purchase->key, $three->purchase->key])->toBe(['gp-sub-token-1', 'gp-sub-token-1', 'gp-sub-token-1'])
        ->and([$one->isNew, $two->isNew, $three->isNew])->toBe([true, true, true])
        ->and($three->ownership)->toBe(Ownership::Existing)
        ->and($three->purchase->productId)->toBe('premium_family')
        ->and($three->purchase->purchaseToken)->toBe('gp-sub-token-3')
        ->and(store()->findByUser('user-1'))->toHaveCount(1)
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->tokens)->toBe(['gp-sub-token-1', 'gp-sub-token-2', 'gp-sub-token-3'])
        ->and(store()->findByToken(Platform::Google, 'gp-sub-token-3')?->key)->toBe('gp-sub-token-1');
});

it('#8 does not let another user claim an old token of an owned chain', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active', 'gp-sub-token-2' => 'upgraded', 'gp-sub-token-3' => 'upgradedAgain']);
    claimGoogleSub('user-1', 'gp-sub-token-1');
    claimGoogleSub('user-1', 'gp-sub-token-2');
    claimGoogleSub('user-1', 'gp-sub-token-3');

    // Ownership rule (no account-token binding): ALREADY_OWNED.
    $withoutTokens = guard(['ownership.account_token_secret' => null]);
    expect(catchIap(fn () => claimGoogleSub('user-2', 'gp-sub-token-2', $withoutTokens))->errorCode())->toBe('ALREADY_OWNED')
        ->and(catchIap(fn () => claimGoogleSub('user-2', 'gp-sub-token-1', $withoutTokens))->errorCode())->toBe('ALREADY_OWNED');

    // With account tokens, the purchase is also bound to user-1 at purchase time.
    expect(catchIap(fn () => claimGoogleSub('user-2', 'gp-sub-token-2', guard(['ownership.account_token_secret' => iapFixture('account-tokens')['secret']])))->errorCode())->toBe('ACCOUNT_MISMATCH');

    expect(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->userId)->toBe('user-1');
});

it('#9 keeps an Apple upgrade within a subscription group in the same record', function () {
    claimApple('user-1', 'subscriptionActive');
    $upgraded = claimApple('user-1', 'subscriptionUpgraded');

    expect($upgraded->purchase->key)->toBe('2000000000000001')
        ->and($upgraded->purchase->productId)->toBe('premium_yearly')
        ->and($upgraded->isNew)->toBeTrue()
        ->and($upgraded->ownership)->toBe(Ownership::Existing)
        ->and(store()->findByUser('user-1'))->toHaveCount(1);
});

it('#10 records Family Sharing purchases under their own original transaction id', function () {
    $shared = claimApple('user-2', 'familyShared');

    expect($shared->purchase->familyShared)->toBeTrue()
        ->and($shared->purchase->key)->toBe('2000000000000400')
        ->and($shared->ownership)->toBe(Ownership::Created);

    $e = catchIap(fn () => claimApple('user-3', 'familyShared', guard(['apple.allow_family_sharing' => false])));

    expect($e)->toBeInstanceOf(PolicyException::class)
        ->and($e->errorCode())->toBe('FAMILY_SHARING_NOT_ALLOWED');
});

it('#11 lets the first claim take a purchase stored by a webhook that arrived first', function () {
    Event::fake([IapNotificationProcessed::class]);
    $webhook = guard()->handleAppleNotification(appleFixture('notifications', 'subscribedNoToken')['body']);

    expect($webhook->userId)->toBeNull()
        ->and(store()->findByKey(Platform::Apple, '2000000000000100')?->userId)->toBeNull();

    $claim = claimApple('user-1', 'subscriptionNoToken');

    expect($claim->ownership)->toBe(Ownership::Created)
        ->and($claim->isNew)->toBeTrue()
        ->and($claim->isEntitled)->toBeTrue()
        ->and(store()->findByKey(Platform::Apple, '2000000000000100')?->userId)->toBe('user-1');
});

it('#11 grants a consumable whose webhook arrived before the claim exactly once', function () {
    guard()->handleAppleNotification(appleFixture('notifications', 'oneTimeCharge')['body']);

    expect(claimApple('user-1', 'consumable')->isNew)->toBeTrue()
        ->and(claimApple('user-1', 'consumable')->isNew)->toBeFalse();
});

it('#12 reports the other platform\'s subscription when a user subscribes on both', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);
    $apple = claimApple('user-1', 'subscriptionActive');

    $google = claimGoogleSub('user-1', 'gp-sub-token-1');

    expect($apple->otherActive)->toBe([])
        ->and($google->otherActive)->toHaveCount(1)
        ->and($google->otherActive[0]->platform)->toBe(Platform::Apple)
        ->and(guard()->entitlements('user-1'))->toHaveCount(2);
});

it('#13 records a pending purchase without entitling or acknowledging it', function () {
    fakePlay(subscriptions: ['gp-sub-token-pending' => 'pending'], products: ['gp-prod-token-pending' => 'pending']);

    $sub = claimGoogleSub('user-1', 'gp-sub-token-pending');
    $product = guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-pending'));

    expect($sub->purchase->status->value)->toBe('pending')
        ->and($sub->isEntitled)->toBeFalse()
        ->and($product->purchase->status->value)->toBe('pending')
        ->and($product->isEntitled)->toBeFalse()
        ->and($product->purchase->acknowledged)->toBeFalse()
        ->and(playWrites())->toBe([])
        ->and(guard()->entitlements('user-1'))->toBe([]);
});

it('#14 ends the entitlement on refund and emits an event to claw back consumables', function () {
    Event::fake([IapNotificationProcessed::class]);
    claimApple('user-1', 'nonConsumable');
    expect(guard()->entitlements('user-1'))->toHaveCount(1);

    $revoke = guard()->handleAppleNotification(appleFixture('notifications', 'revoke')['body']);
    expect($revoke->purchase?->key)->toBe('2000000000000500'); // a different (revoked) transaction

    claimApple('user-1', 'consumable');
    $refund = guard()->handleAppleNotification(appleFixture('notifications', 'refund')['body']);

    expect($refund->userId)->toBe('user-1')
        ->and($refund->purchase?->status->value)->toBe('revoked')
        ->and($refund->purchase?->isEntitled())->toBeFalse()
        ->and($refund->purchase?->quantity)->toBe(2);
    Event::assertDispatched(IapNotificationProcessed::class, fn ($e) => $e->result->event->value === 'refunded' && $e->result->userId === 'user-1');
});

it('#15 releases a deleted account\'s purchases so they can be restored into a new account', function () {
    claimApple('user-1', 'subscriptionNoToken');
    claimApple('user-1', 'nonConsumable');
    claimApple('user-1', 'consumable');

    expect(guard()->releaseUser('user-1'))->toBe(3)
        ->and(guard()->entitlements('user-1'))->toBe([])
        ->and(store()->findByUser('user-1'))->toBe([])
        ->and(guard()->releaseUser('user-1'))->toBe(0);

    $restored = claimApple('user-9', 'subscriptionNoToken');
    $replayed = claimApple('user-9', 'consumable', guard(['ownership.account_token_secret' => null]));

    expect($restored->ownership)->toBe(Ownership::Created)
        ->and($restored->isNew)->toBeFalse()
        ->and($replayed->ownership)->toBe(Ownership::Created)
        ->and($replayed->isNew)->toBeFalse(); // released consumables are never granted twice
});

it('#15 retries a release that lost a race', function () {
    claimApple('user-1', 'subscriptionNoToken');
    $store = useInterleavingStore();
    $store->beforeReplace[] = function (PurchaseStore $inner) {
        $record = $inner->findByKey(Platform::Apple, '2000000000000100');
        $inner->replace($record->next(now()->toImmutable()), $record->version);
    };

    expect(guard()->releaseUser('user-1'))->toBe(1)
        ->and($store->replaceResults)->toBe([false, true]);
});

it('#16 accepts sandbox purchases on a production server (App Review)', function () {
    $result = claimApple('user-1', 'sandbox');

    expect($result->purchase->environment->value)->toBe('sandbox')
        ->and($result->isEntitled)->toBeTrue();
});

it('#17 rejects a purchase for another app', function () {
    $e = catchIap(fn () => claimApple('user-1', 'wrongBundle'));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe('WRONG_APP')
        ->and(store()->findByUser('user-1'))->toBe([]);
});

it('#18 rejects forged, tampered, self-signed and Xcode transactions', function (string $jws) {
    $e = catchIap(fn () => guard()->claim('user-1', PurchaseProof::appleSignedTransaction($jws)));

    expect($e->errorCode())->toBe('INVALID_SIGNATURE')
        ->and(store()->findByUser('user-1'))->toBe([]);
})->with(fn () => [
    'tampered' => iapFixture('apple/jws')['invalidSignatures']['tampered'],
    'rogue chain' => iapFixture('apple/jws')['invalidSignatures']['rogueChain'],
    'xcode' => iapFixture('apple/jws')['transactions']['xcode']['jws'],
]);

it('validates the user id', function (string $userId) {
    guard()->claim($userId, PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));
})->with(['', str_repeat('x', 192)])->throws(ValidationException::class);

it('accepts integer user ids and stores them as strings', function () {
    $result = guard(['ownership.account_token_secret' => null])->claim(42, PurchaseProof::appleSignedTransaction(appleJws('nonConsumable')));

    expect($result->ownership)->toBe(Ownership::Created)
        ->and(store()->findByUser('42'))->toHaveCount(1);
});

it('dispatches PurchaseClaimed with the result', function () {
    Event::fake([PurchaseClaimed::class]);

    $result = claimApple('user-1', 'subscriptionActive');

    Event::assertDispatched(PurchaseClaimed::class, fn (PurchaseClaimed $e) => $e->userId === 'user-1' && $e->result === $result);
});

it('serializes the claim result', function () {
    $array = claimApple('user-1', 'subscriptionActive')->toArray();

    expect($array)->toHaveKeys(['purchase', 'isEntitled', 'isNew', 'ownership', 'previousUserId', 'otherActive'])
        ->and($array['ownership'])->toBe('created')
        ->and($array['purchase']['signedAt'])->toBe('2026-11-18T11:00:00.000Z');
});

it('sorts entitlements by expiry, non-expiring first', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);
    claimApple('user-1', 'subscriptionActive');        // expires +28d
    claimApple('user-1', 'nonConsumable');             // never expires
    claimApple('user-1', 'subscriptionUser2', guard(['ownership.account_token_secret' => null])); // +29d
    claimGoogleSub('user-1', 'gp-sub-token-1');        // +28d

    $keys = array_map(fn ($p) => $p->key, guard()->entitlements('user-1'));

    expect($keys[0])->toBe('2000000000000200')
        ->and($keys[1])->toBe('2000000000000700')
        ->and(guard()->entitlements('user-1', now()->addDays(365)))->toHaveCount(1);
});
