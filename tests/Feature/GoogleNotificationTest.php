<?php

declare(strict_types=1);

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Events\IapNotificationProcessed;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\IapGuard;
use BijayX\IapGuard\Tests\Support\InterleavingStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function rtdn(string $name): array
{
    return iapFixture('google/rtdn')['notifications'][$name];
}

function handleRtdn(string $name): NotificationResult
{
    return guard()->handleGoogleNotification(rtdn($name)['body'], oidcHeader());
}

/**
 * Serve the "natural" API fixture for every token used by rtdn.json.
 */
function fakePlayForRtdn(array $subscriptions = [], array $products = []): void
{
    fakePlay(
        subscriptions: $subscriptions + ['gp-sub-token-1' => 'active', 'gp-sub-token-2' => 'upgraded', 'gp-sub-token-pending' => 'pendingCanceled'],
        products: $products + ['gp-prod-token-coins' => 'consumable', 'gp-prod-token-pending' => 'cancelled', 'gp-prod-token-lifetime' => 'nonConsumable'],
    );
}

it('maps every RTDN to its event and raw type and fetches the right API', function (string $name) {
    fakePlayForRtdn();
    $expected = rtdn($name)['expected'];

    $result = handleRtdn($name);

    expect($result->event->value)->toBe($expected['event'] === 'plan_changed' ? 'purchased' : $expected['event'])
        ->and($result->rawType)->toBe($expected['rawType'])
        ->and($result->id)->toBe(rtdn($name)['body']['message']['messageId'])
        ->and($result->platform)->toBe(Platform::Google)
        ->and($result->duplicate)->toBeFalse();

    $fetched = Http::recorded(fn (Request $r) => $r->method() === 'GET' && str_starts_with($r->url(), PLAY_BASE))
        ->map(fn ($pair) => substr($pair[0]->url(), strlen(PLAY_BASE)))->values()->all();

    expect($fetched)->toBe(match ($expected['fetch']) {
        'subscription' => ['subscriptionsv2/tokens/'.$expected['token']],
        'product' => ['productsv2/tokens/'.$expected['token']],
        default => [],
    });

    if ($expected['fetch'] !== null) {
        expect($result->purchase?->purchaseToken)->toBe($expected['token'])
            ->and($result->userId)->toBeNull();
    } else {
        expect($result->purchase)->toBeNull();
    }
})->with(fn () => array_keys(array_filter(iapFixture('google/rtdn')['notifications'], fn ($n) => isset($n['expected']))));

it('rejects malformed or foreign notifications', function (string $name) {
    fakePlay();

    $e = catchIap(fn () => handleRtdn($name));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe(rtdn($name)['expectedError']);
})->with(fn () => array_keys(array_filter(iapFixture('google/rtdn')['notifications'], fn ($n) => isset($n['expectedError']))));

it('stores the fetched state, never the notification content, and keeps the record unowned', function () {
    fakePlayForRtdn();

    $result = handleRtdn('subscriptionRenewed');

    expect(normalized($result->purchase))->toBe(iapFixture('google/api')['subscriptions']['active']['expected'])
        ->and(store()->findByToken(Platform::Google, 'gp-sub-token-1')?->userId)->toBeNull();
});

it('reports plan_changed when the linked token belongs to a stored chain', function () {
    fakePlayForRtdn();

    handleRtdn('subscriptionPurchased');
    $result = handleRtdn('subscriptionUpgradePurchased');

    expect($result->event)->toBe(NotificationEvent::PlanChanged)
        ->and($result->purchase?->key)->toBe('gp-sub-token-1')
        ->and($result->purchase?->productId)->toBe('premium_yearly')
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->tokens)->toBe(['gp-sub-token-1', 'gp-sub-token-2']);
});

it('forces revoked on SUBSCRIPTION_REVOKED', function () {
    fakePlayForRtdn();

    $purchase = handleRtdn('subscriptionRevoked')->purchase;

    expect($purchase?->status->value)->toBe('revoked')
        ->and($purchase?->toArray()['revokedAt'])->toBe('2026-11-18T12:00:00.000Z')
        ->and($purchase?->isEntitled())->toBeFalse();
});

it('revokes a stored purchase on a full voided-purchase notification', function () {
    fakePlayForRtdn();
    guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-lifetime'));

    $result = handleRtdn('voidedFull');

    expect($result->event)->toBe(NotificationEvent::Refunded)
        ->and($result->purchase?->status->value)->toBe('revoked')
        ->and($result->purchase?->toArray()['revokedAt'])->toBe('2026-11-18T12:00:00.000Z')
        ->and($result->userId)->toBe('user-1')
        ->and(guard()->entitlements('user-1'))->toBe([]);
});

it('keeps the status on a partial (quantity) refund but still reports it', function () {
    fakePlayForRtdn();
    guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-coins'));

    $result = handleRtdn('voidedPartial');

    expect($result->event)->toBe(NotificationEvent::Refunded)
        ->and($result->purchase?->status->value)->toBe('active')
        ->and($result->userId)->toBe('user-1');
});

it('does not fail the webhook when the API no longer knows the token', function (string $error) {
    fakePlay(overrides: ['subscriptionsv2' => Http::response(iapFixture('google/api')['errors'][$error]['body'], iapFixture('google/api')['errors'][$error]['status'])]);

    $result = handleRtdn('subscriptionRenewed');

    expect($result->purchase)->toBeNull()
        ->and($result->event)->toBe(NotificationEvent::Renewed);
})->with(['notFound', 'gone']);

it('dedupes notifications by Pub/Sub message id', function () {
    fakePlayForRtdn();
    Event::fake([IapNotificationProcessed::class]);

    $first = handleRtdn('subscriptionRenewed');
    $second = handleRtdn('subscriptionRenewed');

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->purchase)->toBeNull();
    Event::assertDispatchedTimes(IapNotificationProcessed::class, 1);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), 'subscriptionsv2')))->toHaveCount(1);
});

it('forgets the notification when processing fails, so the redelivery is processed', function () {
    fakePlay(overrides: ['subscriptionsv2' => Http::sequence()
        ->push(['error' => ['code' => 503]], 503)->push(['error' => ['code' => 503]], 503)->push(['error' => ['code' => 503]], 503)
        ->push(iapFixture('google/api')['subscriptions']['active']['body'])]);

    expect(catchIap(fn () => handleRtdn('subscriptionRenewed'))->errorCode())->toBe('STORE_API_ERROR');

    $retry = handleRtdn('subscriptionRenewed');

    expect($retry->duplicate)->toBeFalse()
        ->and($retry->purchase?->status->value)->toBe('active');
});

it('acknowledges an owned purchase when a notification activates it', function () {
    $api = iapFixture('google/api')['subscriptions'];
    fakePlay(overrides: ['subscriptionsv2/tokens/gp-sub-token-pending' => Http::sequence()
        ->push($api['pending']['body'])
        ->push($api['active']['body'])]);

    $claim = guard()->claim('user-1', PurchaseProof::google('subscription', 'gp-sub-token-pending'));

    expect($claim->purchase->status->value)->toBe('pending')
        ->and($claim->isEntitled)->toBeFalse()
        ->and(playWrites())->toBe([]);

    $result = guard()->handleGoogleNotification(
        pushBody(['subscriptionNotification' => ['version' => '1.0', 'notificationType' => 4, 'purchaseToken' => 'gp-sub-token-pending']]),
        oidcHeader(),
    );

    expect($result->purchase?->status->value)->toBe('active')
        ->and($result->purchase?->acknowledged)->toBeTrue()
        ->and($result->userId)->toBe('user-1')
        ->and(playWrites())->toBe(['subscriptions/premium_monthly/tokens/gp-sub-token-pending:acknowledge'])
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-pending')?->purchase->acknowledged)->toBeTrue();
});

it('does not acknowledge purchases nobody has claimed yet', function () {
    fakePlayForRtdn();

    handleRtdn('subscriptionPurchased');
    handleRtdn('oneTimePurchased');

    expect(playWrites())->toBe([]);
});

it('retries the upsert when a claim inserts the record first, and never changes the owner', function () {
    fakePlayForRtdn();
    $owned = guard()->verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));

    $store = new InterleavingStore(store());
    $this->app->instance(PurchaseStore::class, $store);
    app()->forgetInstance(IapGuard::class);

    // Another request (a claim by user-1) inserts the record right before the webhook does.
    $store->beforeInsert[] = fn (PurchaseStore $inner) => $inner->insert(PurchaseRecord::create($owned, 'user-1', ['gp-sub-token-1'], now()->toImmutable()));

    $result = handleRtdn('subscriptionRenewed');

    expect($store->insertResults)->toBe([false])
        ->and($store->replaceResults)->toBe([true, true]) // upsert, then acknowledge (owned + pending acknowledgement)
        ->and($result->userId)->toBe('user-1')
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->version)->toBe(3);
});
