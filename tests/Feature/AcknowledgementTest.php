<?php

declare(strict_types=1);

use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\Platform;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('acknowledges, consumes or skips according to the matrix', function (string $type, string $fixture, array $expectedWrites, bool $acknowledged) {
    $section = $type === 'subscription' ? 'subscriptions' : 'products';
    $token = iapFixture('google/api')[$section][$fixture]['token'];
    $type === 'subscription' ? fakePlay(subscriptions: [$token => $fixture]) : fakePlay(products: [$token => $fixture]);

    $result = guard()->claim('user-1', PurchaseProof::google($type, $token));

    expect(playWrites())->toBe($expectedWrites)
        ->and($result->purchase->acknowledged)->toBe($acknowledged)
        ->and(store()->findByKey(Platform::Google, $token)?->purchase->acknowledged)->toBe($acknowledged);
})->with([
    'active subscription, pending acknowledgement' => ['subscription', 'active', ['subscriptions/premium_monthly/tokens/gp-sub-token-1:acknowledge'], true],
    'active subscription, already acknowledged' => ['subscription', 'activeAcknowledged', [], true],
    'grace period subscription, already acknowledged' => ['subscription', 'gracePeriod', [], true],
    'pending subscription' => ['subscription', 'pending', [], false],
    'expired subscription' => ['subscription', 'expired', [], true],
    'non-consumable, pending acknowledgement' => ['one_time', 'nonConsumable', ['products/lifetime_unlock/tokens/gp-prod-token-lifetime:acknowledge'], true],
    'non-consumable, already acknowledged' => ['one_time', 'nonConsumableAcknowledged', [], true],
    'consumable' => ['one_time', 'consumable', ['products/coins_100/tokens/gp-prod-token-coins:consume'], true],
    'pending one-time product' => ['one_time', 'pending', [], false],
    'canceled one-time product' => ['one_time', 'cancelled', [], false],
]);

it('sends an empty JSON object to acknowledge', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

    guard()->claim('user-1', PurchaseProof::google('subscription', 'gp-sub-token-1'));

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), ':acknowledge') && $r->body() === '{}' && $r->header('Authorization') === ['Bearer ya29.test-access-token']);
});

it('does not consume a consumable that Play reports as already consumed', function () {
    $body = iapFixture('google/api')['products']['consumable']['body'];
    $body['productLineItem'][0]['productOfferDetails']['consumptionState'] = 'CONSUMPTION_STATE_CONSUMED';
    fakePlay(products: ['gp-prod-token-coins' => $body]);

    guard()->claim('user-1', PurchaseProof::google('one_time', 'gp-prod-token-coins'));

    expect(playWrites())->toBe([]);
});

it('still succeeds when acknowledge fails, logs without secrets, and retries on the next claim', function () {
    Log::spy();
    fakePlay(
        subscriptions: ['gp-sub-token-1' => 'active'],
        overrides: [':acknowledge' => Http::sequence()
            ->push(['error' => ['code' => 503]], 503)->push(['error' => ['code' => 503]], 503)->push(['error' => ['code' => 503]], 503)
            ->push('{}')],
    );

    $first = guard()->claim('user-1', PurchaseProof::google('subscription', 'gp-sub-token-1'));

    expect($first->isEntitled)->toBeTrue()
        ->and($first->purchase->acknowledged)->toBeFalse()
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->purchase->acknowledged)->toBeFalse()
        ->and(store()->findByKey(Platform::Google, 'gp-sub-token-1')?->userId)->toBe('user-1');

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
        $logged = $message.json_encode($context);

        return str_contains($message, 'acknowledge')
            && $context['iap_guard_code'] === 'STORE_API_ERROR'
            && ! str_contains($logged, 'gp-sub-token-1')
            && ! str_contains($logged, 'user-1')
            && ! str_contains($logged, 'ya29');
    });

    $second = guard()->claim('user-1', PurchaseProof::google('subscription', 'gp-sub-token-1'));

    expect($second->purchase->acknowledged)->toBeTrue()
        ->and(count(playWrites()))->toBe(4);
});

it('never acknowledges Apple purchases', function () {
    Http::fake();

    guard()->claim('user-1', PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));

    Http::assertNothingSent();
});
