<?php

declare(strict_types=1);

use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\VerificationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function testPush(): array
{
    return iapFixture('google/rtdn')['notifications']['test']['body'];
}

const OIDC_EXPECTATIONS = [
    'valid' => null,
    'validShortIssuer' => null,
    'wrongAudience' => 'INVALID_SIGNATURE',
    'expired' => 'INVALID_SIGNATURE',
    'issuedInFuture' => 'INVALID_SIGNATURE',
    'wrongIssuer' => 'INVALID_SIGNATURE',
    'wrongEmail' => 'INVALID_SIGNATURE',
    'emailNotVerified' => 'INVALID_SIGNATURE',
    'unknownKid' => 'INVALID_SIGNATURE',
    'signedByOtherKey' => 'INVALID_SIGNATURE',
    'tamperedClaims' => 'INVALID_SIGNATURE',
    'algNone' => 'INVALID_SIGNATURE',
    'algHS256' => 'INVALID_SIGNATURE',
];

it('covers every OIDC token in the fixtures', function () {
    expect(array_keys(OIDC_EXPECTATIONS))->toEqualCanonicalizing(array_keys(iapFixture('google/oidc')['tokens']));
});

it('authenticates Pub/Sub push OIDC tokens', function (string $name) {
    fakePlay();
    $expected = OIDC_EXPECTATIONS[$name];

    if ($expected === null) {
        expect(guard()->handleGoogleNotification(testPush(), oidcHeader($name))->event->value)->toBe('test');

        return;
    }

    $e = catchIap(fn () => guard()->handleGoogleNotification(testPush(), oidcHeader($name)));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe($expected);
})->with(array_keys(OIDC_EXPECTATIONS));

it('rejects a missing or non-bearer Authorization header', function (?string $header) {
    fakePlay();

    expect(catchIap(fn () => guard()->handleGoogleNotification(testPush(), $header))->errorCode())->toBe('INVALID_SIGNATURE');
})->with([null, '', 'Basic abc', 'Bearer']);

it('accepts any verified email when no service account email is configured', function () {
    fakePlay();

    expect(guard(['google.pubsub.service_account_email' => null])->handleGoogleNotification(testPush(), oidcHeader('wrongEmail'))->event->value)->toBe('test');
});

it('caches the JWKS between requests', function () {
    fakePlay();
    $guard = guard();

    $guard->handleGoogleNotification(pushBody(['testNotification' => []], '1'), oidcHeader());
    $guard->handleGoogleNotification(pushBody(['testNotification' => []], '2'), oidcHeader());

    expect(Http::recorded(fn (Request $r) => $r->url() === GOOGLE_JWKS_URL))->toHaveCount(1);
});

it('refetches the JWKS after its max-age', function () {
    fakePlay(overrides: [GOOGLE_JWKS_URL => Http::response(iapFixture('google/oidc')['jwks'], 200, ['Cache-Control' => 'max-age=120'])]);

    guard()->handleGoogleNotification(pushBody(['testNotification' => []], '1'), oidcHeader());
    $this->travel(121)->seconds();
    guard()->handleGoogleNotification(pushBody(['testNotification' => []], '2'), oidcHeader());

    expect(Http::recorded(fn (Request $r) => $r->url() === GOOGLE_JWKS_URL))->toHaveCount(2);
});

it('refetches the JWKS once when a token names an unknown kid (key rotation)', function () {
    $rotated = ['keys' => [['kid' => 'old-key', 'kty' => 'RSA', 'alg' => 'RS256', 'n' => 'AQAB', 'e' => 'AQAB']]];
    fakePlay(overrides: [GOOGLE_JWKS_URL => Http::sequence()->push($rotated)->push(iapFixture('google/oidc')['jwks'])]);

    expect(guard()->handleGoogleNotification(testPush(), oidcHeader())->event->value)->toBe('test');
    expect(Http::recorded(fn (Request $r) => $r->url() === GOOGLE_JWKS_URL))->toHaveCount(2);
});

it('refetches at most once for unknown kids, and not again within a minute', function () {
    fakePlay();

    expect(catchIap(fn () => guard()->handleGoogleNotification(testPush(), oidcHeader('unknownKid')))->errorCode())->toBe('INVALID_SIGNATURE');
    expect(catchIap(fn () => guard()->handleGoogleNotification(testPush(), oidcHeader('unknownKid')))->errorCode())->toBe('INVALID_SIGNATURE');

    expect(Http::recorded(fn (Request $r) => $r->url() === GOOGLE_JWKS_URL))->toHaveCount(2);
});

it('authenticates with the shared verification token instead', function () {
    fakePlay();
    $token = iapFixture('google/rtdn')['verificationToken'];
    $guard = guard(['google.pubsub.audience' => null, 'google.pubsub.verification_token' => $token]);

    expect($guard->handleGoogleNotification(testPush(), null, $token)->event->value)->toBe('test')
        ->and(catchIap(fn () => $guard->handleGoogleNotification(testPush(), null, 'wrong'))->errorCode())->toBe('INVALID_SIGNATURE')
        ->and(catchIap(fn () => $guard->handleGoogleNotification(testPush(), null, null))->errorCode())->toBe('INVALID_SIGNATURE');
});

it('requires an audience or a verification token', function () {
    guard(['google.pubsub.audience' => null, 'google.pubsub.verification_token' => null])->handleGoogleNotification(testPush());
})->throws(ConfigurationException::class);
