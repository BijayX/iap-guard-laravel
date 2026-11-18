<?php

declare(strict_types=1);

use BijayX\IapGuard\Crypto\Jws;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Exceptions\AuthenticationException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\IapGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function appleApiGuard(array $overrides = []): IapGuard
{
    $api = iapFixture('apple/api');

    return guard([
        'apple.api.issuer_id' => $api['credentials']['issuerId'],
        'apple.api.key_id' => $api['credentials']['keyId'],
        'apple.api.private_key' => dirname(__DIR__).'/fixtures/'.$api['credentials']['privateKeyFile'],
    ] + $overrides);
}

function appleResponse(string $name)
{
    $response = iapFixture('apple/api')['responses'][$name];

    return Http::response($response['body'], $response['status'], $response['headers'] ?? []);
}

it('authenticates with an ES256 JWT that verifies with the .p8 public key', function () {
    Http::fake([APPLE_PRODUCTION.'/inApps/v1/transactions/*' => appleResponse('transactionInfo')]);

    appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);

    $expected = iapFixture('apple/api')['expectedJwt'];
    $privateKey = openssl_pkey_get_private((string) file_get_contents(dirname(__DIR__).'/fixtures/apple/keys/api-private-key.p8'));
    $publicKey = openssl_pkey_get_public(openssl_pkey_get_details($privateKey)['key']);

    Http::assertSent(function (Request $request) use ($expected, $publicKey) {
        expect($request->url())->toBe(APPLE_PRODUCTION.'/inApps/v1/transactions/2000000000000200')
            ->and($request->method())->toBe('GET')
            ->and($request->header('User-Agent')[0])->toStartWith('iap-guard-laravel/');

        $jwt = Jws::parse(substr($request->header('Authorization')[0], strlen('Bearer ')));

        expect($jwt->header)->toBe($expected['header'])
            ->and(Jwt::verifyEs256($jwt->signingInput, $jwt->signature, $publicKey))->toBeTrue()
            ->and($jwt->payload['iss'])->toBe($expected['claims']['iss'])
            ->and($jwt->payload['aud'])->toBe($expected['claims']['aud'])
            ->and($jwt->payload['bid'])->toBe($expected['claims']['bid'])
            ->and($jwt->payload['iat'])->toBe(now()->getTimestamp())
            ->and($jwt->payload['exp'] - $jwt->payload['iat'])->toBe($expected['claims']['expMinusIat']);

        return true;
    });
});

it('verifies by transaction id and checks the returned JWS like client input', function () {
    Http::fake([APPLE_PRODUCTION.'/inApps/v1/transactions/2000000000000200' => appleResponse('transactionInfo')]);

    $purchase = appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);

    expect(normalized($purchase))->toBe(appleFixture('transactions', 'nonConsumable')['expected']);
});

it('rejects a forged signedTransactionInfo returned by the API', function () {
    Http::fake([APPLE_PRODUCTION.'/*' => Http::response(['signedTransactionInfo' => iapFixture('apple/jws')['invalidSignatures']['rogueChain']])]);

    expect(catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000002']))->errorCode())
        ->toBe('INVALID_SIGNATURE');
});

it('falls back from production to sandbox on 4040010', function () {
    Http::fake([
        APPLE_PRODUCTION.'/*' => appleResponse('transactionNotFound'),
        APPLE_SANDBOX.'/inApps/v1/transactions/2000000000000200' => appleResponse('transactionInfo'),
    ]);

    expect(appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000200'])->productId)->toBe('lifetime_unlock');

    Http::assertSentInOrder([
        fn (Request $r) => str_starts_with($r->url(), APPLE_PRODUCTION),
        fn (Request $r) => str_starts_with($r->url(), APPLE_SANDBOX),
    ]);
});

it('throws PURCHASE_NOT_FOUND when neither environment knows the transaction', function () {
    Http::fake(['*' => appleResponse('transactionNotFound')]);

    $e = catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '1']));

    expect($e)->toBeInstanceOf(PurchaseNotFoundException::class);
    Http::assertSentCount(2);
});

it('does not fall back to sandbox when only production is allowed', function () {
    Http::fake(['*' => appleResponse('transactionNotFound')]);

    expect(fn () => appleApiGuard(['apple.environments' => ['production']])->verify(['platform' => 'apple', 'transactionId' => '1']))
        ->toThrow(PurchaseNotFoundException::class);

    Http::assertSentCount(1);
});

it('refreshes subscription state from the Server API', function (string $response) {
    Http::fake([APPLE_PRODUCTION.'/inApps/v1/subscriptions/2000000000000002' => appleResponse($response)]);

    $purchase = appleApiGuard()->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')));

    expect(normalized($purchase))->toBe(iapFixture('apple/api')['responses'][$response]['expected']);
})->with(['subscriptionStatusGrace', 'subscriptionStatusActive']);

it('falls back to the client transaction when the Server API has no matching subscription', function () {
    Http::fake([APPLE_PRODUCTION.'/inApps/v1/subscriptions/*' => Http::response(['data' => []])]);

    expect(normalized(appleApiGuard()->verify(PurchaseProof::appleSignedTransaction(appleJws('subscriptionActive')))))
        ->toBe(appleFixture('transactions', 'subscriptionActive')['expected']);
});

it('maps 401 to AuthenticationException without retrying', function () {
    Http::fake(['*' => appleResponse('unauthorized')]);

    $e = catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '1']));

    expect($e)->toBeInstanceOf(AuthenticationException::class)
        ->and($e->errorCode())->toBe('AUTHENTICATION_FAILED')
        ->and($e->getMessage())->not->toContain('eyJ');
    Http::assertSentCount(1);
});

it('retries 429 honouring Retry-After, then throws a retryable StoreApiException', function () {
    Http::fake(['*' => appleResponse('rateLimited')]);

    $e = catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '1']));

    expect($e)->toBeInstanceOf(StoreApiException::class)
        ->and($e->statusCode())->toBe(429)
        ->and($e->retryable())->toBeTrue();
    Http::assertSentCount(3);
    Sleep::assertSlept(fn ($duration) => (int) $duration->totalSeconds === 1, 2);
});

it('retries 5xx with exponential backoff, then throws a retryable StoreApiException', function () {
    Http::fake(['*' => appleResponse('serverError')]);

    $e = catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '1']));

    expect($e)->toBeInstanceOf(StoreApiException::class)
        ->and($e->statusCode())->toBe(500)
        ->and($e->retryable())->toBeTrue()
        ->and($e->responseBody())->toBe(iapFixture('apple/api')['responses']['serverError']['body']);
    Http::assertSentCount(3);
    Sleep::assertSequence([Sleep::for(200)->milliseconds(), Sleep::for(400)->milliseconds()]);
});

it('recovers when a retry succeeds', function () {
    Http::fake(['*' => Http::sequence()->push(['errorCode' => 5000000], 503)->pushResponse(appleResponse('transactionInfo'))]);

    expect(appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '2000000000000200'])->key)->toBe('2000000000000200');
});

it('does not retry other 4xx errors', function () {
    Http::fake(['*' => Http::response(['errorCode' => 4000006, 'errorMessage' => 'Invalid transaction id.'], 400)]);

    $e = catchIap(fn () => appleApiGuard()->verify(['platform' => 'apple', 'transactionId' => '1']));

    expect($e)->toBeInstanceOf(StoreApiException::class)
        ->and($e->retryable())->toBeFalse();
    Http::assertSentCount(1);
});

it('reuses the API JWT for up to five minutes', function () {
    Http::fake(['*' => appleResponse('transactionInfo')]);
    $guard = appleApiGuard();

    $guard->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);
    $this->travel(3)->minutes();
    $guard->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);
    $this->travel(2)->minutes();
    $guard->verify(['platform' => 'apple', 'transactionId' => '2000000000000200']);

    $tokens = Http::recorded()->map(fn ($pair) => $pair[0]->header('Authorization')[0])->unique();

    expect($tokens)->toHaveCount(2);
});
