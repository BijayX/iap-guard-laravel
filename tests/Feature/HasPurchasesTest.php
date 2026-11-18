<?php

declare(strict_types=1);

use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Models\IapPurchase;
use BijayX\IapGuard\Tests\Support\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
    });

    // Account tokens in the fixtures are bound to "user-1"; these users have numeric ids.
    config()->set('iap-guard.ownership.account_token_secret', null);
});

it('relates a user to the purchases they own', function () {
    $alice = User::query()->create(['name' => 'Alice']);
    $bob = User::query()->create(['name' => 'Bob']);
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

    guard()->claim($alice->getKey(), PurchaseProof::appleSignedTransaction(appleJws('subscriptionNoToken')));
    guard()->claim($alice->iapUserId(), PurchaseProof::google('subscription', 'gp-sub-token-1'));
    guard()->claim($bob->getKey(), PurchaseProof::appleSignedTransaction(appleJws('nonConsumable')));

    expect($alice->iapPurchases()->count())->toBe(2)
        ->and($alice->iapPurchases()->pluck('product_id')->all())->toEqualCanonicalizing(['premium_monthly', 'premium_monthly'])
        ->and($bob->iapPurchases()->sole())->toBeInstanceOf(IapPurchase::class)
        ->and(User::query()->with('iapPurchases')->find($bob->getKey())->iapPurchases)->toHaveCount(1);
});

it('lists current entitlements and checks access to a product', function () {
    $user = User::query()->create(['name' => 'Alice']);
    fakePlay(subscriptions: ['gp-sub-token-bundle' => 'bundle']);

    expect($user->iapEntitlements())->toBe([])
        ->and($user->isEntitledTo('premium_monthly'))->toBeFalse();

    guard()->claim($user->getKey(), PurchaseProof::google('subscription', 'gp-sub-token-bundle'));
    guard()->claim($user->getKey(), PurchaseProof::appleSignedTransaction(appleJws('revoked')));

    expect($user->iapEntitlements())->toHaveCount(1)
        ->and($user->isEntitledTo('premium_monthly'))->toBeTrue()
        ->and($user->isEntitledTo('addon_storage'))->toBeTrue()
        ->and($user->isEntitledTo('lifetime_unlock'))->toBeFalse(); // revoked

    $this->travel(31)->days();

    expect($user->isEntitledTo('premium_monthly'))->toBeFalse();
});
