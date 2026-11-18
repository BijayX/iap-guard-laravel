<?php

declare(strict_types=1);

use BijayX\IapGuard\Crypto\Jws;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Exceptions\AuthenticationException;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\NetworkException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Facades\IapGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function googleError(string $name)
{
    $error = iapFixture('google/api')['errors'][$name];

    return Http::response($error['body'], $error['status']);
}

it('normalizes every subscriptionsv2 response exactly like the fixtures', function (string $name) {
    $fixture = iapFixture('google/api')['subscriptions'][$name];
    fakePlay(subscriptions: [$fixture['token'] => $name]);

    $purchase = IapGuard::verify(['platform' => 'google', 'type' => 'subscription', 'purchaseToken' => $fixture['token']]);

    expect(normalized($purchase))->toBe($fixture['expected'])
        ->and($purchase->raw)->toBe($fixture['body']);
})->with(fn () => array_keys(iapFixture('google/api')['subscriptions']));

it('normalizes every productsv2 response exactly like the fixtures', function (string $name) {
    $fixture = iapFixture('google/api')['products'][$name];
    fakePlay(products: [$fixture['token'] => $name]);

    $purchase = IapGuard::verify(['platform' => 'google', 'type' => 'one_time', 'purchaseToken' => $fixture['token']]);

    expect(normalized($purchase))->toBe($fixture['expected']);
})->with(fn () => array_keys(iapFixture('google/api')['products']));

it('calls the documented, URL-encoded endpoints', function () {
    fakePlay(subscriptions: ['a/b c' => 'active']);

    IapGuard::verify(PurchaseProof::google('subscription', 'a/b c'));

    Http::assertSent(fn (Request $r) => $r->url() === PLAY_BASE.'subscriptionsv2/tokens/a%2Fb%20c'
        && $r->method() === 'GET'
        && $r->header('Authorization') === ['Bearer ya29.test-access-token']);
});

it('rejects a purchase for another product', function () {
    fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

    $e = catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1', 'premium_yearly')));

    expect($e)->toBeInstanceOf(VerificationException::class)
        ->and($e->errorCode())->toBe('PRODUCT_MISMATCH');
});

it('accepts a matching product id, including any line item of a bundle', function () {
    fakePlay(subscriptions: ['gp-sub-token-bundle' => 'bundle']);

    expect(IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-bundle', 'addon_storage'))->productIds)
        ->toBe(['premium_monthly', 'addon_storage']);
});

it('rejects license-tester purchases when test purchases are not allowed', function (string $type, string $token, string $fixture) {
    $type === 'subscription' ? fakePlay(subscriptions: [$token => $fixture]) : fakePlay(products: [$token => $fixture]);

    $e = catchIap(fn () => guard(['google.allow_test_purchases' => false])->verify(PurchaseProof::google($type, $token)));

    expect($e)->toBeInstanceOf(PolicyException::class)
        ->and($e->errorCode())->toBe('ENVIRONMENT_NOT_ALLOWED');
})->with([
    ['subscription', 'gp-sub-token-test', 'testPurchase'],
    ['one_time', 'gp-prod-token-test', 'testPurchase'],
]);

it('enforces the product allowlist on every line item', function () {
    fakePlay(subscriptions: ['gp-sub-token-bundle' => 'bundle']);

    $e = catchIap(fn () => guard(['products' => 'premium_monthly'])->verify(PurchaseProof::google('subscription', 'gp-sub-token-bundle')));

    expect($e->errorCode())->toBe('UNKNOWN_PRODUCT')
        ->and($e->details())->toBe(['product_id' => 'addon_storage']);
});

it('maps Play API errors', function (string $error, string $class, int $requests) {
    fakePlay(overrides: ['subscriptionsv2' => googleError($error)]);

    $e = catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1')));

    expect($e)->toBeInstanceOf($class);
    Http::assertSentCount(1 + $requests); // + the OAuth token request
})->with([
    'not found' => ['notFound', PurchaseNotFoundException::class, 1],
    'gone' => ['gone', PurchaseNotFoundException::class, 1],
    'malformed token' => ['invalidToken', PurchaseNotFoundException::class, 1],
    'unauthorized' => ['unauthorized', AuthenticationException::class, 1],
    'forbidden' => ['forbidden', AuthenticationException::class, 1],
    'rate limited (retried)' => ['rateLimited', StoreApiException::class, 3],
    'server error (retried)' => ['serverError', StoreApiException::class, 3],
]);

it('marks 429 and 5xx as retryable and backs off exponentially', function () {
    fakePlay(overrides: ['subscriptionsv2' => googleError('serverError')]);

    $e = catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1')));

    expect($e->retryable())->toBeTrue()
        ->and($e->statusCode())->toBe(503);
    Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(400)->milliseconds()]);
});

it('drops the cached access token after a 401', function () {
    fakePlay(overrides: ['subscriptionsv2' => googleError('unauthorized')]);

    catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1')));
    catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1')));

    Http::assertSentCount(4);
});

describe('service-account OAuth', function () {
    it('requests a token with an RS256 assertion signed by the service account', function () {
        fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

        IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));

        $publicKey = openssl_pkey_get_public((string) file_get_contents(dirname(__DIR__).'/fixtures/google/service-account.public.pem'));
        $account = iapFixture('google/service-account');

        Http::assertSent(function (Request $request) use ($publicKey, $account) {
            if ($request->url() !== GOOGLE_TOKEN_URL) {
                return false;
            }

            expect($request->method())->toBe('POST')
                ->and($request->isForm())->toBeTrue()
                ->and($request['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');

            $jwt = Jws::parse($request['assertion']);

            expect($jwt->header)->toBe(['alg' => 'RS256', 'typ' => 'JWT'])
                ->and(Jwt::verifyRs256($jwt->signingInput, $jwt->signature, $publicKey))->toBeTrue()
                ->and($jwt->payload)->toBe([
                    'iss' => $account['client_email'],
                    'scope' => iapFixture('google/api')['oauth']['scope'],
                    'aud' => GOOGLE_TOKEN_URL,
                    'iat' => now()->getTimestamp(),
                    'exp' => now()->getTimestamp() + 3600,
                ]);

            return true;
        });
    });

    it('caches the access token until expires_in - 60 seconds', function () {
        fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);
        $tokenRequests = fn () => Http::recorded(fn (Request $r) => $r->url() === GOOGLE_TOKEN_URL)->count();

        IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));
        IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));
        expect($tokenRequests())->toBe(1);

        $this->travel(3599 - 60 - 1)->seconds();
        IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));
        expect($tokenRequests())->toBe(1);

        $this->travel(2)->seconds();
        IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));
        expect($tokenRequests())->toBe(2);
    });

    it('shares the cached token between IapGuard instances', function () {
        fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);

        guard()->verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));
        app()->forgetInstance(BijayX\IapGuard\IapGuard::class);
        guard()->verify(PurchaseProof::google('subscription', 'gp-sub-token-1'));

        expect(Http::recorded(fn (Request $r) => $r->url() === GOOGLE_TOKEN_URL))->toHaveCount(1);
    });

    it('maps token endpoint 400 to AuthenticationException without leaking the assertion', function () {
        $error = iapFixture('google/api')['oauth']['tokenError'];
        fakePlay(overrides: [GOOGLE_TOKEN_URL => Http::response($error['body'], $error['status'])]);

        $e = catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'gp-sub-token-1')));

        expect($e)->toBeInstanceOf(AuthenticationException::class)
            ->and($e->getMessage())->toContain('invalid_grant')
            ->and($e->getMessage())->not->toContain('eyJ')
            ->and(json_encode($e->details()))->not->toContain('PRIVATE KEY');
    });

    it('accepts the service account as a JSON string or array', function () {
        fakePlay(subscriptions: ['gp-sub-token-1' => 'active']);
        $json = (string) file_get_contents(dirname(__DIR__).'/fixtures/google/service-account.json');

        expect(guard(['google.service_account' => $json])->verify(PurchaseProof::google('subscription', 'gp-sub-token-1'))->key)->toBe('gp-sub-token-1')
            ->and(guard(['google.service_account' => json_decode($json, true)])->verify(PurchaseProof::google('subscription', 'gp-sub-token-1'))->key)->toBe('gp-sub-token-1');
    });

    it('rejects an invalid service account without echoing it', function () {
        $e = catchIap(fn () => guard(['google.service_account' => '{"private_key":"-----BEGIN PRIVATE KEY-----secret"}'])->verify(PurchaseProof::google('subscription', 'x')));

        expect($e)->toBeInstanceOf(ConfigurationException::class)
            ->and($e->getMessage())->not->toContain('secret');
    });

    it('requires a service account for API calls', function () {
        guard(['google.service_account' => null])->verify(PurchaseProof::google('subscription', 'x'));
    })->throws(ConfigurationException::class, 'google.service_account');
});

it('maps connection failures to NetworkException without leaking the token in the URL', function () {
    fakePlay(overrides: ['subscriptionsv2' => fn () => throw new ConnectionException('cURL error 28: Operation timed out for '.PLAY_BASE.'subscriptionsv2/tokens/secret-token')]);

    $e = catchIap(fn () => IapGuard::verify(PurchaseProof::google('subscription', 'secret-token')));

    expect($e)->toBeInstanceOf(NetworkException::class)
        ->and($e->errorCode())->toBe('TIMEOUT')
        ->and($e->getPrevious())->toBeNull()
        ->and($e->getMessage())->not->toContain('secret-token')
        ->and(json_encode($e->details()))->not->toContain('secret-token')
        ->and($e->details()['reason'])->toContain('[url]');
    Http::assertSentCount(1); // the OAuth token; the three API attempts threw before being recorded
});
