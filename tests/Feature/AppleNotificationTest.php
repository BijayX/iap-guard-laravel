<?php

declare(strict_types=1);

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Events\IapNotificationProcessed;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Facades\IapGuard;
use Illuminate\Support\Facades\Event;

function appleNotification(string $name): array
{
    return appleFixture('notifications', $name);
}

it('processes every notification exactly like the fixtures', function (string $name) {
    $fixture = appleNotification($name);

    $result = IapGuard::handleAppleNotification(json_encode($fixture['body']));

    expect($result->event->value)->toBe($fixture['expected']['event'])
        ->and($result->rawType)->toBe($fixture['expected']['rawType'])
        ->and($result->id)->toBe($fixture['expected']['id'])
        ->and(normalized($result->purchase))->toBe($fixture['expected']['purchase'])
        ->and($result->duplicate)->toBeFalse()
        ->and($result->stale)->toBeFalse()
        ->and($result->userId)->toBeNull()
        ->and($result->raw)->toBe($fixture['payload']);
})->with(fn () => array_keys(array_filter(iapFixture('apple/jws')['notifications'], fn ($n) => isset($n['expected']))));

it('rejects invalid notifications with the expected error code', function (string $name) {
    $fixture = appleNotification($name);

    $e = catchIap(fn () => IapGuard::handleAppleNotification($fixture['body']));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe($fixture['expectedError']);
})->with(fn () => array_keys(array_filter(iapFixture('apple/jws')['notifications'], fn ($n) => isset($n['expectedError']))));

it('rejects a body that is not JSON', function () {
    expect(catchIap(fn () => IapGuard::handleAppleNotification('signedPayload=abc'))->errorCode())->toBe('INVALID_NOTIFICATION');
});

it('requires app_apple_id while production is allowed', function () {
    guard(['apple.app_apple_id' => null])->handleAppleNotification(appleNotification('test')['body']);
})->throws(ConfigurationException::class, 'app_apple_id');

it('does not require app_apple_id for a sandbox-only server', function () {
    $result = guard(['apple.app_apple_id' => null, 'apple.environments' => ['sandbox']])
        ->handleAppleNotification(appleNotification('sandboxSubscribed')['body']);

    expect($result->purchase?->environment->value)->toBe('sandbox');
});

it('dedupes notifications by notificationUUID', function () {
    Event::fake([IapNotificationProcessed::class]);

    $first = IapGuard::handleAppleNotification(appleNotification('subscribed')['body']);
    $second = IapGuard::handleAppleNotification(appleNotification('subscribed')['body']);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->purchase)->toBeNull()
        ->and($second->event)->toBe(NotificationEvent::Purchased);
    Event::assertDispatchedTimes(IapNotificationProcessed::class, 1);
});

it('forgets the notification when processing throws, so Apple\'s retry is processed', function () {
    expect(catchIap(fn () => IapGuard::handleAppleNotification(appleNotification('forgedInnerTransaction')['body']))->errorCode())->toBe('INVALID_SIGNATURE')
        ->and(store()->rememberNotification(Platform::Apple, appleNotification('forgedInnerTransaction')['payload']['notificationUUID']))->toBeTrue();
});

it('forgets the notification when a listener throws', function () {
    Event::listen(IapNotificationProcessed::class, fn () => throw new RuntimeException('listener failed'));

    expect(fn () => IapGuard::handleAppleNotification(appleNotification('subscribed')['body']))->toThrow(RuntimeException::class);

    Event::forget(IapNotificationProcessed::class);

    expect(IapGuard::handleAppleNotification(appleNotification('subscribed')['body'])->duplicate)->toBeFalse();
});

it('never rolls stored state back with an older notification', function () {
    IapGuard::handleAppleNotification(appleNotification('didRenew')['body']);

    $result = IapGuard::handleAppleNotification(appleNotification('staleRenewal')['body']);

    expect($result->stale)->toBeTrue()
        ->and($result->purchase?->transactionId)->toBe('2000000000000004')
        ->and(store()->findByKey(Platform::Apple, '2000000000000001')?->purchase->transactionId)->toBe('2000000000000004');
});

it('applies a newer notification on top of a claimed purchase without changing the owner', function () {
    guard()->claim('user-1', PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));

    $result = IapGuard::handleAppleNotification(appleNotification('gracePeriod')['body']);

    expect($result->userId)->toBe('user-1')
        ->and($result->purchase?->status->value)->toBe('grace_period')
        ->and($result->purchase?->gracePeriodExpiresAt?->toIso8601ZuluString('millisecond'))->toBe('2026-11-21T12:00:00.000Z')
        ->and(guard()->entitlements('user-1'))->toHaveCount(1);
});

it('stores consumables by transaction id and revokes them on REFUND', function () {
    IapGuard::handleAppleNotification(appleNotification('oneTimeCharge')['body']);
    $refund = IapGuard::handleAppleNotification(appleNotification('refund')['body']);

    expect($refund->purchase?->key)->toBe('2000000000000300')
        ->and($refund->purchase?->status->value)->toBe('revoked')
        ->and(store()->findByKey(Platform::Apple, '2000000000000300')?->purchase->status->value)->toBe('revoked');
});

it('dispatches IapNotificationProcessed with the result', function () {
    Event::fake([IapNotificationProcessed::class]);

    $result = IapGuard::handleAppleNotification(appleNotification('revoke')['body']);

    Event::assertDispatched(IapNotificationProcessed::class, fn (IapNotificationProcessed $e) => $e->result === $result
        && $e->result->event === NotificationEvent::Revoked
        && $e->result->event->endsAccess());
});

it('records notifications in the store\'s notification table', function () {
    IapGuard::handleAppleNotification(appleNotification('test')['body']);

    expect(app(PurchaseStore::class)->rememberNotification(Platform::Apple, appleNotification('test')['payload']['notificationUUID']))->toBeFalse();
    $this->assertDatabaseHas('iap_notifications', ['platform' => 'apple', 'notification_id' => '00000000-0000-4000-8000-000000000019']);
});
