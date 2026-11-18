<?php

declare(strict_types=1);

use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Events\PurchaseClaimed;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Facades\IapGuard as IapGuardFacade;
use BijayX\IapGuard\IapGuard;
use BijayX\IapGuard\IapGuardServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('registers IapGuard as a singleton with an alias and a facade', function () {
    expect(app(IapGuard::class))->toBe(app('iap-guard'))
        ->and(IapGuardFacade::getFacadeRoot())->toBe(app(IapGuard::class));
});

it('runs the package migrations automatically', function () {
    expect(Schema::hasTable('iap_purchases'))->toBeTrue()
        ->and(Schema::hasTable('iap_purchase_tokens'))->toBeTrue()
        ->and(Schema::hasTable('iap_notifications'))->toBeTrue()
        ->and(Schema::hasColumns('iap_purchases', ['user_id', 'product_id', 'status', 'expires_at', 'grace_period_expires_at', 'account_token', 'purchase', 'last_event_at', 'version']))->toBeTrue();
});

it('publishes the config and migrations', function () {
    expect(ServiceProvider::pathsToPublish(IapGuardServiceProvider::class, 'iap-guard-config'))->toHaveCount(1)
        ->and(ServiceProvider::pathsToPublish(IapGuardServiceProvider::class, 'iap-guard-migrations'))->toHaveCount(1);
});

it('merges the default config', function () {
    expect(config('iap-guard.webhooks.prefix'))->toBe('iap/webhooks')
        ->and(config('iap-guard.store.driver'))->toBe('database')
        ->and(config('iap-guard.ownership.conflict_policy'))->toBe('reject');
});

it('rejects an unknown conflict policy', function () {
    guard(['ownership.conflict_policy' => 'share'])->releaseUser('user-1');
})->throws(ConfigurationException::class, 'conflict_policy');

it('dispatches through the current dispatcher, so Event::fake() works after IapGuard was resolved', function () {
    $guard = guard();
    Event::fake([PurchaseClaimed::class]);

    $guard->claim('user-1', PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));

    Event::assertDispatched(PurchaseClaimed::class);
});
