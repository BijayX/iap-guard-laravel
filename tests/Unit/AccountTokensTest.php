<?php

declare(strict_types=1);

use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Ownership\AccountTokens;

it('derives the shared account-token fixtures', function () {
    $fixture = iapFixture('account-tokens');
    $tokens = new AccountTokens($fixture['secret']);

    foreach ($fixture['cases'] as $case) {
        expect($tokens->for($case['userId']))->toBe($case['token'])
            ->and(guard()->accountTokenFor($case['userId']))->toBe($case['token']);
    }
});

it('produces RFC 4122 variant, version 8 UUIDs', function () {
    expect(guard()->accountTokenFor('user-1'))->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-8[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('compares tokens case-insensitively', function () {
    $tokens = new AccountTokens(iapFixture('account-tokens')['secret']);

    expect($tokens->matches(strtoupper(accountToken('user-1')), 'user-1'))->toBeTrue()
        ->and($tokens->matches(accountToken('user-2'), 'user-1'))->toBeFalse();
});

it('requires a secret of at least 32 characters', function () {
    (new AccountTokens('too-short'))->for('user-1');
})->throws(ConfigurationException::class);

it('requires a configured secret', function () {
    guard(['ownership.account_token_secret' => null])->accountTokenFor('user-1');
})->throws(ConfigurationException::class, 'account_token_secret');

it('accepts integer user ids', function () {
    expect(guard()->accountTokenFor(42))->toBe(accountToken('42'));
});
