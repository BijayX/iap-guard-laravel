<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Crypto\Jws;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Crypto\Keys;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Http\StoreHttpClient;
use BijayX\IapGuard\Support\Time;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Authenticates Pub/Sub push requests (SPEC §8.2).
 *
 * With pubsub.audience: the push subscription's OIDC token (Authorization: Bearer <JWT>), RS256,
 * signed by a key from Google's JWKS. The JWKS is cached for its Cache-Control max-age (default 1h)
 * and refetched once when a token names an unknown kid (at most once a minute, so forged kids
 * cannot hammer Google).
 *
 * Otherwise pubsub.verification_token: a shared secret in the push URL's ?token=.
 */
final readonly class PubSubVerifier
{
    public const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public const CLOCK_SKEW_SECONDS = 60;

    public const DEFAULT_JWKS_TTL = 3600;

    public const REFETCH_COOLDOWN_SECONDS = 60;

    public function __construct(
        private GoogleConfig $config,
        private StoreHttpClient $http,
        private Repository $cache,
        private string $cachePrefix = 'iap-guard',
    ) {}

    /**
     * @throws VerificationException|ConfigurationException|IapException
     */
    public function authenticate(?string $authorization, ?string $queryToken): void
    {
        if ($this->config->pubsubAudience !== null) {
            $this->verifyOidc($authorization);

            return;
        }

        if ($this->config->pubsubVerificationToken !== null) {
            if ($queryToken === null || ! hash_equals($this->config->pubsubVerificationToken, $queryToken)) {
                throw VerificationException::invalidSignature(Platform::Google, 'The push verification token is invalid.');
            }

            return;
        }

        throw new ConfigurationException(
            'Configure [iap-guard.google.pubsub.audience] or [iap-guard.google.pubsub.verification_token] to receive Google notifications.',
            Platform::Google,
            'google.pubsub',
        );
    }

    /**
     * @throws VerificationException|IapException
     */
    private function verifyOidc(?string $authorization): void
    {
        if ($authorization === null || preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m) !== 1) {
            throw self::invalid('The push request has no bearer token.');
        }

        try {
            $jwt = Jws::parse($m[1]);
        } catch (CryptoException) {
            throw self::invalid('The push token is malformed.');
        }

        $kid = $jwt->header['kid'] ?? null;

        if ($jwt->alg() !== 'RS256' || ! is_string($kid) || $kid === '') {
            throw self::invalid('The push token must be RS256 with a key id.');
        }

        $jwk = $this->jwk($kid) ?? throw self::invalid('The push token was signed by an unknown key.');

        try {
            $key = Keys::rsaPublicKeyFromJwk((string) $jwk['n'], (string) $jwk['e']);
        } catch (CryptoException) {
            throw self::invalid('The signing key is invalid.');
        }

        if (! Jwt::verifyRs256($jwt->signingInput, $jwt->signature, $key)) {
            throw self::invalid('The push token signature is invalid.');
        }

        $claims = $jwt->payload;
        $now = Time::now()->getTimestamp();

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw self::invalid('The push token issuer is invalid.');
        }

        if (($claims['aud'] ?? null) !== $this->config->pubsubAudience) {
            throw self::invalid('The push token audience is invalid.');
        }

        if (! is_int($claims['exp'] ?? null) || $claims['exp'] <= $now - self::CLOCK_SKEW_SECONDS) {
            throw self::invalid('The push token has expired.');
        }

        if (! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + self::CLOCK_SKEW_SECONDS) {
            throw self::invalid('The push token was issued in the future.');
        }

        if ($this->config->pubsubServiceAccountEmail !== null
            && (($claims['email'] ?? null) !== $this->config->pubsubServiceAccountEmail || ($claims['email_verified'] ?? null) !== true)) {
            throw self::invalid('The push token was not issued to the configured service account.');
        }
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws IapException
     */
    private function jwk(string $kid): ?array
    {
        $key = $this->cachePrefix.':google:jwks:'.hash('sha256', $this->config->jwksUrl);
        $keys = $this->cache->get($key);

        if (! is_array($keys)) {
            $keys = $this->fetchJwks($key);
        }

        $found = self::find($keys, $kid);

        // Unknown kid: Google may have rotated its keys. Refetch once (rate limited).
        if ($found === null && $this->cache->add($key.':refetch', true, self::REFETCH_COOLDOWN_SECONDS)) {
            $found = self::find($this->fetchJwks($key), $kid);
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $keys
     * @return array<string, mixed>|null
     */
    private static function find(array $keys, string $kid): ?array
    {
        foreach ($keys as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid && ($jwk['kty'] ?? null) === 'RSA'
                && is_string($jwk['n'] ?? null) && is_string($jwk['e'] ?? null)) {
                /** @var array<string, mixed> $jwk */
                return $jwk;
            }
        }

        return null;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws IapException
     */
    private function fetchJwks(string $cacheKey): array
    {
        $url = $this->config->jwksUrl;
        $response = $this->http->send(static fn (PendingRequest $request): Response => $request->get($url));
        $keys = $response->json('keys');

        if (! $response->successful() || ! is_array($keys)) {
            throw new StoreApiException(
                'Could not load Google\'s OIDC signing keys.',
                Platform::Google,
                $response->status(),
                null,
                StoreHttpClient::isRetryable($response),
            );
        }

        $ttl = self::DEFAULT_JWKS_TTL;

        if (preg_match('/max-age=(\d+)/i', (string) $response->header('Cache-Control'), $m) === 1) {
            $ttl = (int) $m[1];
        }

        if ($ttl > 0) {
            $this->cache->put($cacheKey, $keys, $ttl);
        }

        return $keys;
    }

    private static function invalid(string $reason): VerificationException
    {
        return VerificationException::invalidSignature(Platform::Google, $reason);
    }
}
