<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Crypto\Keys;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\AuthenticationException;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Http\StoreHttpClient;
use BijayX\IapGuard\Support\Time;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * OAuth 2.0 service-account flow (JWT bearer grant, SPEC §6.2).
 *
 * The access token is cached in the Laravel cache until expires_in - 60 seconds. When the cache
 * store supports locks, concurrent requests share one token request.
 */
final readonly class ServiceAccountAuth
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private GoogleConfig $config,
        private StoreHttpClient $http,
        private Repository $cache,
        private string $cachePrefix = 'iap-guard',
    ) {}

    /**
     * @throws IapException
     */
    public function accessToken(): string
    {
        $key = $this->cacheKey();
        $cached = $this->cache->get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $store = $this->cache->getStore();

        if ($store instanceof LockProvider) {
            try {
                return $store->lock($key.':lock', 10)->block(10, function () use ($key): string {
                    $cached = $this->cache->get($key);

                    return is_string($cached) && $cached !== '' ? $cached : $this->fetch($key);
                });
            } catch (LockTimeoutException) {
                // Fall through and fetch without the lock.
            }
        }

        return $this->fetch($key);
    }

    /**
     * Drop the cached token (after the API answered 401).
     */
    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * @throws IapException
     */
    private function fetch(string $key): string
    {
        $account = $this->config->requireServiceAccount();
        $tokenUrl = $this->config->tokenUrl();
        $now = Time::now()->getTimestamp();

        try {
            $assertion = Jwt::signRs256(
                ['alg' => 'RS256', 'typ' => 'JWT'],
                [
                    'iss' => $account['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $tokenUrl,
                    'iat' => $now,
                    'exp' => $now + 3600,
                ],
                Keys::privateKey($account['private_key']),
            );
        } catch (CryptoException) {
            throw ConfigurationException::invalid('google.service_account', 'a service account with a valid RSA private_key', Platform::Google);
        }

        $response = $this->http->send(
            static fn (PendingRequest $request): Response => $request->asForm()->post($tokenUrl, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            retryable: false,
        );

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->status() === 400 || $response->status() === 401) {
            $error = is_string($body['error'] ?? null) ? $body['error'] : 'invalid_request';

            // Never include the assertion or the private key.
            throw new AuthenticationException("Google rejected the service-account credentials ({$error}).", Platform::Google, $response->status());
        }

        $token = $body['access_token'] ?? null;

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new StoreApiException(
                'The Google OAuth token endpoint returned an unexpected response.',
                Platform::Google,
                $response->status(),
                null,
                StoreHttpClient::isRetryable($response),
            );
        }

        $ttl = (is_numeric($body['expires_in'] ?? null) ? (int) $body['expires_in'] : 3600) - self::EXPIRY_MARGIN_SECONDS;

        if ($ttl > 0) {
            $this->cache->put($key, $token, $ttl);
        }

        return $token;
    }

    private function cacheKey(): string
    {
        $account = $this->config->serviceAccount['client_email'] ?? '';

        return $this->cachePrefix.':google:access-token:'.hash('sha256', $account.'|'.$this->config->tokenUrl());
    }
}
