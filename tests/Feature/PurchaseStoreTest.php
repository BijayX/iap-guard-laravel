<?php

declare(strict_types=1);

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Models\IapPurchase;
use BijayX\IapGuard\Store\DatabasePurchaseStore;
use BijayX\IapGuard\Store\InMemoryPurchaseStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function googlePurchase(string $token = 'gp-sub-token-1', string $fixture = 'active'): Purchase
{
    $expected = iapFixture('google/api')['subscriptions'][$fixture]['expected'];

    return Purchase::fromArray(['key' => $token, 'purchaseToken' => $token] + $expected);
}

function record(Purchase $purchase, ?string $userId = 'user-1', ?array $tokens = null): PurchaseRecord
{
    return PurchaseRecord::create($purchase, $userId, $tokens ?? array_values(array_filter([$purchase->purchaseToken])), CarbonImmutable::parse('2026-11-18T12:00:00Z'));
}

dataset('stores', [
    'database' => fn () => app(PurchaseStore::class),
    'memory' => fn () => new InMemoryPurchaseStore,
]);

it('uses the database store by default', function () {
    expect(app(PurchaseStore::class))->toBeInstanceOf(DatabasePurchaseStore::class);
});

it('round-trips a record', function (PurchaseStore $store) {
    $record = record(googlePurchase(), 'user-1', ['gp-sub-token-1', 'gp-sub-token-0']);

    expect($store->insert($record))->toBeTrue();

    $found = $store->findByKey(Platform::Google, 'gp-sub-token-1');

    expect($found)->not->toBeNull()
        ->and($found->purchase->toArray())->toBe($record->purchase->toArray())
        ->and($found->tokens)->toBe(['gp-sub-token-1', 'gp-sub-token-0'])
        ->and($found->userId)->toBe('user-1')
        ->and($found->version)->toBe(1)
        ->and($found->lastEventAt->getTimestampMs())->toBe($record->lastEventAt->getTimestampMs())
        ->and($found->claimedAt?->getTimestampMs())->toBe($record->claimedAt?->getTimestampMs())
        ->and($store->findByToken(Platform::Google, 'gp-sub-token-0')?->key)->toBe('gp-sub-token-1')
        ->and($store->findByToken(Platform::Apple, 'gp-sub-token-0'))->toBeNull()
        ->and($store->findByUser('user-1'))->toHaveCount(1)
        ->and($store->findByUser('user-2'))->toBe([]);
})->with('stores');

it('keeps millisecond precision for lastEventAt', function (PurchaseStore $store) {
    $purchase = Purchase::fromArray(['signedAt' => '2026-11-18T11:59:59.123Z'] + googlePurchase()->toArray());
    $store->insert(record($purchase));

    expect($store->findByKey(Platform::Google, 'gp-sub-token-1')?->lastEventAt->format('Y-m-d\TH:i:s.v'))->toBe('2026-11-18T11:59:59.123');
})->with('stores');

it('refuses a second insert for the same (platform, key)', function (PurchaseStore $store) {
    expect($store->insert(record(googlePurchase(), 'user-1')))->toBeTrue()
        ->and($store->insert(record(googlePurchase(), 'user-2')))->toBeFalse()
        ->and($store->findByKey(Platform::Google, 'gp-sub-token-1')?->userId)->toBe('user-1');
})->with('stores');

it('allows the same key on different platforms', function (PurchaseStore $store) {
    $apple = Purchase::fromArray(['platform' => 'apple', 'key' => 'same', 'purchaseToken' => null] + googlePurchase()->toArray());
    $google = Purchase::fromArray(['key' => 'same', 'purchaseToken' => null] + googlePurchase()->toArray());

    expect($store->insert(record($apple, tokens: [])))->toBeTrue()
        ->and($store->insert(record($google, tokens: [])))->toBeTrue();
})->with('stores');

it('refuses an insert whose token already belongs to another record, atomically', function (PurchaseStore $store) {
    $store->insert(record(googlePurchase('gp-sub-token-1')));

    expect($store->insert(record(googlePurchase('gp-sub-token-2'), 'user-2', ['gp-sub-token-2', 'gp-sub-token-1'])))->toBeFalse()
        ->and($store->findByKey(Platform::Google, 'gp-sub-token-2'))->toBeNull()
        ->and($store->findByToken(Platform::Google, 'gp-sub-token-2'))->toBeNull();
})->with('stores');

it('replaces only when the stored version matches', function (PurchaseStore $store) {
    $record = record(googlePurchase());
    $store->insert($record);
    $now = CarbonImmutable::parse('2026-11-18T12:01:00Z');

    $next = $record->next($now, tokens: ['gp-sub-token-2'], changeUser: true, userId: 'user-2');

    expect($store->replace($next, 1))->toBeTrue()
        ->and($store->replace($record->next($now, changeUser: true, userId: 'user-3'), 1))->toBeFalse() // stale version
        ->and($store->replace($next->next($now), 5))->toBeFalse();

    $found = $store->findByKey(Platform::Google, 'gp-sub-token-1');

    expect($found?->version)->toBe(2)
        ->and($found?->userId)->toBe('user-2')
        ->and($found?->tokens)->toBe(['gp-sub-token-1', 'gp-sub-token-2'])
        ->and($store->findByToken(Platform::Google, 'gp-sub-token-2')?->key)->toBe('gp-sub-token-1');
})->with('stores');

it('refuses to replace a record that does not exist', function (PurchaseStore $store) {
    expect($store->replace(record(googlePurchase())->next(CarbonImmutable::now()), 1))->toBeFalse();
})->with('stores');

it('rolls back a replace whose new token belongs to another record', function (PurchaseStore $store) {
    $first = record(googlePurchase('gp-sub-token-1'));
    $store->insert($first);
    $store->insert(record(googlePurchase('gp-sub-token-9'), 'user-2'));

    expect($store->replace($first->next(CarbonImmutable::now(), tokens: ['gp-sub-token-9'], changeUser: true, userId: 'user-3'), 1))->toBeFalse();

    $found = $store->findByKey(Platform::Google, 'gp-sub-token-1');

    expect($found?->version)->toBe(1)
        ->and($found?->userId)->toBe('user-1')
        ->and($store->findByToken(Platform::Google, 'gp-sub-token-9')?->key)->toBe('gp-sub-token-9');
})->with('stores');

it('remembers each notification once and can forget it', function (PurchaseStore $store) {
    expect($store->rememberNotification(Platform::Apple, 'n-1'))->toBeTrue()
        ->and($store->rememberNotification(Platform::Apple, 'n-1'))->toBeFalse()
        ->and($store->rememberNotification(Platform::Google, 'n-1'))->toBeTrue();

    $store->forgetNotification(Platform::Apple, 'n-1');

    expect($store->rememberNotification(Platform::Apple, 'n-1'))->toBeTrue();
})->with('stores');

it('relies on the unique indexes, not on a prior read', function () {
    app(PurchaseStore::class)->insert(record(googlePurchase()));
    $row = (array) DB::table('iap_purchases')->select(['platform', 'key', 'product_id', 'type', 'status', 'environment', 'purchase', 'last_event_at'])->first();

    // Writes that bypass the store are refused by the database itself.
    expect(fn () => DB::table('iap_purchases')->insert($row))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('iap_purchase_tokens')->insert(['purchase_id' => 1, 'platform' => 'google', 'token' => 'gp-sub-token-1']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('iap_notifications')->insert([['platform' => 'apple', 'notification_id' => 'x'], ['platform' => 'apple', 'notification_id' => 'x']]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('promotes queryable columns and exposes them through the IapPurchase model', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);
    guard()->claim('user-1', PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));
    guard()->claim('user-1', PurchaseProof::google('subscription', 'gp-sub-token-1'));

    $apple = IapPurchase::query()->forUser('user-1')->platform(Platform::Apple)->sole();

    expect($apple->product_id)->toBe('premium_monthly')
        ->and($apple->status->value)->toBe('active')
        ->and($apple->type->value)->toBe('subscription')
        ->and($apple->environment->value)->toBe('production')
        ->and($apple->original_transaction_id)->toBe('2000000000000001')
        ->and($apple->account_token)->toBe(accountToken('user-1'))
        ->and($apple->expires_at?->toIso8601ZuluString())->toBe('2026-12-16T12:00:00Z')
        ->and($apple->will_renew)->toBeNull()
        ->and($apple->version)->toBe(1)
        ->and($apple->toPurchase()->key)->toBe('2000000000000001')
        ->and($apple->isEntitled())->toBeTrue()
        ->and(IapPurchase::query()->active()->count())->toBe(2)
        ->and(IapPurchase::query()->platform(Platform::Google)->value('purchase_token'))->toBe('gp-sub-token-1');
});

it('resolves a custom store class from config', function () {
    config()->set('iap-guard.store.driver', InMemoryPurchaseStore::class);
    app()->forgetInstance(PurchaseStore::class);

    expect(app(PurchaseStore::class))->toBeInstanceOf(InMemoryPurchaseStore::class);

    config()->set('iap-guard.store.driver', 'memory');
    app()->forgetInstance(PurchaseStore::class);

    expect(app(PurchaseStore::class))->toBeInstanceOf(InMemoryPurchaseStore::class);
});

it('rejects an unknown store driver', function () {
    config()->set('iap-guard.store.driver', 'redis');
    app()->forgetInstance(PurchaseStore::class);

    app(PurchaseStore::class);
})->throws(ConfigurationException::class, 'store.driver');
