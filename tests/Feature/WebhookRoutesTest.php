<?php

declare(strict_types=1);

use BijayX\IapGuard\Events\IapNotificationProcessed;
use BijayX\IapGuard\Events\PurchaseClaimed;
use BijayX\IapGuard\IapGuard;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

it('registers the webhook routes', function () {
    expect(route('iap-guard.webhooks.apple', absolute: false))->toBe('/iap/webhooks/apple')
        ->and(route('iap-guard.webhooks.google', absolute: false))->toBe('/iap/webhooks/google');
});

it('processes an App Store notification end to end', function () {
    Event::fake([IapNotificationProcessed::class]);

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'subscribed')['body'])
        ->assertOk()
        ->assertExactJson(['id' => '00000000-0000-4000-8000-000000000001', 'event' => 'purchased', 'duplicate' => false, 'stale' => false]);

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'subscribed')['body'])
        ->assertOk()
        ->assertJson(['duplicate' => true]);

    Event::assertDispatchedTimes(IapNotificationProcessed::class, 1);
    $this->assertDatabaseHas('iap_purchases', ['platform' => 'apple', 'key' => '2000000000000001', 'user_id' => null, 'status' => 'active']);
});

it('answers 200 for a stale App Store notification', function () {
    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'didRenew')['body'])->assertOk();

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'staleRenewal')['body'])
        ->assertOk()
        ->assertJson(['stale' => true]);
});

it('answers 400 to forged or malformed App Store notifications so Apple stops retrying', function (string $name, string $code) {
    Event::fake([IapNotificationProcessed::class]);

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', $name)['body'])
        ->assertStatus(400)
        ->assertExactJson(['error' => $code]);

    Event::assertNotDispatched(IapNotificationProcessed::class);
})->with([
    ['forgedSignature', 'INVALID_SIGNATURE'],
    ['forgedInnerTransaction', 'INVALID_SIGNATURE'],
    ['wrongBundle', 'WRONG_APP'],
    ['missingSignedPayload', 'INVALID_NOTIFICATION'],
    ['wrongVersion', 'INVALID_NOTIFICATION'],
]);

it('answers 400 to a non-JSON body', function () {
    $this->call('POST', '/iap/webhooks/apple', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'hello')
        ->assertStatus(400);
});

it('answers 500 on configuration or server errors so the store retries', function () {
    config()->set('iap-guard.apple.app_apple_id', null);
    app()->forgetInstance(IapGuard::class);

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'test')['body'])
        ->assertStatus(500)
        ->assertExactJson(['error' => 'CONFIGURATION_ERROR']);
});

it('processes a Play RTDN end to end with OIDC', function () {
    Event::fake([IapNotificationProcessed::class]);
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

    $this->postJson('/iap/webhooks/google', iapFixture('google/rtdn')['notifications']['subscriptionRenewed']['body'], ['Authorization' => oidcHeader()])
        ->assertOk()
        ->assertJson(['event' => 'renewed', 'duplicate' => false]);

    Event::assertDispatched(IapNotificationProcessed::class, fn ($e) => $e->result->purchase?->key === 'gp-sub-token-1');
});

it('answers 400 to Play pushes without a valid OIDC token', function () {
    fakePlay();

    $this->postJson('/iap/webhooks/google', iapFixture('google/rtdn')['notifications']['test']['body'])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'INVALID_SIGNATURE']);

    $this->postJson('/iap/webhooks/google', iapFixture('google/rtdn')['notifications']['test']['body'], ['Authorization' => oidcHeader('wrongAudience')])
        ->assertStatus(400);
});

it('accepts the shared ?token= secret when configured', function () {
    config()->set('iap-guard.google.pubsub.audience', null);
    config()->set('iap-guard.google.pubsub.verification_token', iapFixture('google/rtdn')['verificationToken']);
    app()->forgetInstance(IapGuard::class);

    $this->postJson('/iap/webhooks/google?token='.iapFixture('google/rtdn')['verificationToken'], iapFixture('google/rtdn')['notifications']['test']['body'])
        ->assertOk()
        ->assertJson(['event' => 'test']);

    $this->postJson('/iap/webhooks/google?token=nope', iapFixture('google/rtdn')['notifications']['test']['body'])
        ->assertStatus(400);
});

it('answers 400 for a Play notification for another package', function () {
    fakePlay();

    $this->postJson('/iap/webhooks/google', iapFixture('google/rtdn')['notifications']['wrongPackage']['body'], ['Authorization' => oidcHeader()])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'WRONG_APP']);
});

it('answers 500 when the Play API is down, and processes the redelivery', function () {
    fakePlay(overrides: ['subscriptionsv2' => Http::sequence()
        ->push([], 503)->push([], 503)->push([], 503)
        ->push(iapFixture('google/api')['subscriptions']['active']['body'])]);
    $body = iapFixture('google/rtdn')['notifications']['subscriptionRenewed']['body'];

    $this->postJson('/iap/webhooks/google', $body, ['Authorization' => oidcHeader()])
        ->assertStatus(500)
        ->assertExactJson(['error' => 'STORE_API_ERROR']);

    $this->postJson('/iap/webhooks/google', $body, ['Authorization' => oidcHeader()])
        ->assertOk()
        ->assertJson(['duplicate' => false]);
});

it('does not dispatch claim events from webhooks', function () {
    Event::fake([PurchaseClaimed::class]);

    $this->postJson('/iap/webhooks/apple', appleFixture('notifications', 'subscribed')['body'])->assertOk();

    Event::assertNotDispatched(PurchaseClaimed::class);
});

describe('configuration', function () {
    it('uses the configured prefix and middleware', function () {
        $this->rebootWith([
            'iap-guard.webhooks.prefix' => '/hooks/store/',
            'iap-guard.webhooks.middleware' => ['throttle:60,1'],
        ]);

        $route = Route::getRoutes()->getByName('iap-guard.webhooks.apple');

        expect($route?->uri())->toBe('hooks/store/apple')
            ->and($route?->methods())->toBe(['POST'])
            ->and($route?->middleware())->toBe(['throttle:60,1'])
            ->and(Route::getRoutes()->getByName('iap-guard.webhooks.google')?->uri())->toBe('hooks/store/google');
    });

    it('registers no routes when webhooks are disabled', function () {
        $this->rebootWith(['iap-guard.webhooks.enabled' => false]);

        expect(Route::getRoutes()->getByName('iap-guard.webhooks.apple'))->toBeNull();
        $this->postJson('/iap/webhooks/apple', [])->assertNotFound();
    });
});
