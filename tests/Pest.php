<?php

declare(strict_types=1);

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\IapGuard;
use BijayX\IapGuard\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

uses(TestCase::class, RefreshDatabase::class)->in('Unit', 'Feature');

const PLAY_BASE = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.example.app/purchases/';
const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const GOOGLE_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
const APPLE_PRODUCTION = 'https://api.storekit.itunes.apple.com';
const APPLE_SANDBOX = 'https://api.storekit-sandbox.itunes.apple.com';

/**
 * Load a JSON fixture shared with the Node.js package (tests/fixtures).
 *
 * @return array<string, mixed>
 */
function iapFixture(string $path): array
{
    static $cache = [];

    return $cache[$path] ??= json_decode((string) file_get_contents(TestCase::FIXTURES."/{$path}.json"), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function appleFixture(string $section, string $name): array
{
    return iapFixture('apple/jws')[$section][$name];
}

function appleJws(string $name): string
{
    return appleFixture('transactions', $name)['jws'];
}

/**
 * The IapGuard singleton, rebuilt after applying config overrides.
 *
 * @param  array<string, mixed>  $config  iap-guard config keys in dot notation
 */
function guard(array $config = []): IapGuard
{
    foreach ($config as $key => $value) {
        config()->set("iap-guard.{$key}", $value);
    }

    if ($config !== []) {
        app()->forgetInstance(IapGuard::class);
    }

    return app(IapGuard::class);
}

function store(): PurchaseStore
{
    return app(PurchaseStore::class);
}

/**
 * Normalized purchase without the raw payload: the shape of the fixtures' "expected" objects.
 *
 * @return array<string, mixed>
 */
function normalized(?Purchase $purchase): ?array
{
    return $purchase === null ? null : Arr::except($purchase->toArray(), ['raw']);
}

function accountToken(string $userId): string
{
    foreach (iapFixture('account-tokens')['cases'] as $case) {
        if ($case['userId'] === $userId) {
            return $case['token'];
        }
    }

    throw new RuntimeException("No account token fixture for {$userId}.");
}

/**
 * Run $callback and return the IapException it throws.
 */
function catchIap(Closure $callback): IapException
{
    try {
        $callback();
    } catch (IapException $e) {
        return $e;
    }

    throw new RuntimeException('Expected an IapException, none was thrown.');
}

/**
 * Fake the Google endpoints: OAuth token, JWKS, subscriptionsv2/productsv2 and acknowledge/consume.
 *
 * @param  array<string, string|array<string, mixed>>  $subscriptions  token => api.json subscription fixture name, or a body
 * @param  array<string, string|array<string, mixed>>  $products  token => api.json product fixture name, or a body
 * @param  array<string, mixed>  $overrides  URL substring => Http::response() / sequence (checked first)
 */
function fakePlay(array $subscriptions = [], array $products = [], array $overrides = []): void
{
    $api = iapFixture('google/api');
    $resolve = static fn (string|array $value, string $section): array => is_string($value) ? $api[$section][$value]['body'] : $value;

    Http::fake(function (Request $request) use ($subscriptions, $products, $overrides, $api, $resolve) {
        $url = $request->url();

        foreach ($overrides as $needle => $response) {
            if (str_contains($url, $needle)) {
                return is_callable($response) ? $response($request) : $response;
            }
        }

        if ($url === GOOGLE_TOKEN_URL) {
            return Http::response($api['oauth']['tokenResponse']);
        }

        if ($url === GOOGLE_JWKS_URL) {
            return Http::response(iapFixture('google/oidc')['jwks'], 200, ['Cache-Control' => 'public, max-age=3600']);
        }

        if (! str_starts_with($url, PLAY_BASE)) {
            throw new RuntimeException("Unexpected request to {$url}");
        }

        $path = substr($url, strlen(PLAY_BASE));
        $notFound = Http::response($api['errors']['notFound']['body'], 404);

        if (preg_match('#^subscriptionsv2/tokens/([^/]+)$#', $path, $m) === 1) {
            $value = $subscriptions[rawurldecode($m[1])] ?? null;

            return $value === null ? $notFound : Http::response($resolve($value, 'subscriptions'));
        }

        if (preg_match('#^productsv2/tokens/([^/]+)$#', $path, $m) === 1) {
            $value = $products[rawurldecode($m[1])] ?? null;

            return $value === null ? $notFound : Http::response($resolve($value, 'products'));
        }

        if (preg_match('#:(acknowledge|consume)$#', $path) === 1) {
            return Http::response('{}');
        }

        throw new RuntimeException("Unexpected Play request {$path}");
    });
}

/**
 * Requests sent to acknowledge/consume endpoints, as "subscriptions/{id}/tokens/{t}:acknowledge" etc.
 *
 * @return list<string>
 */
function playWrites(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === 'POST' && str_starts_with($r->url(), PLAY_BASE))
        ->map(fn (Request $r) => substr($r->url(), strlen(PLAY_BASE)))
        ->values()
        ->all();
}

/**
 * A Pub/Sub push body like Google sends, for a custom developer notification.
 *
 * @param  array<string, mixed>  $developerNotification
 * @return array<string, mixed>
 */
function pushBody(array $developerNotification, string $messageId = '9100000001'): array
{
    return [
        'message' => [
            'data' => base64_encode(json_encode(['version' => '1.0', 'packageName' => 'com.example.app', 'eventTimeMillis' => '1795003140000'] + $developerNotification)),
            'messageId' => $messageId,
            'publishTime' => '2026-11-18T12:00:00.000Z',
        ],
        'subscription' => 'projects/iap-guard-test/subscriptions/play-rtdn-push',
    ];
}

function oidcHeader(string $name = 'valid'): string
{
    return 'Bearer '.iapFixture('google/oidc')['tokens'][$name];
}
