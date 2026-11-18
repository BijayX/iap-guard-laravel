<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Crypto\Keys;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\AuthenticationException;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Http\StoreHttpClient;
use BijayX\IapGuard\Support\Time;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * App Store Server API client (SPEC §5.3). Every signed value it returns must still be verified
 * with SignedDataVerifier before use.
 */
final class AppStoreServerApi
{
    public const TRANSACTION_ID_NOT_FOUND = 4040010;

    public const TOKEN_TTL_SECONDS = 300;

    /** Regenerate the JWT this long before it expires. */
    private const TOKEN_REFRESH_MARGIN_SECONDS = 60;

    private ?string $token = null;

    private int $tokenIssuedAt = 0;

    public function __construct(
        private readonly AppleConfig $config,
        private readonly StoreHttpClient $http,
    ) {
        if (! $config->hasApi()) {
            throw new ConfigurationException('The App Store Server API is not configured [iap-guard.apple.api].', Platform::Apple, 'apple.api');
        }
    }

    /**
     * GET /inApps/v1/transactions/{transactionId} → signedTransactionInfo (unverified JWS).
     *
     * @throws IapException
     */
    public function getTransactionInfo(string $transactionId, Environment $environment): string
    {
        $body = $this->get("/inApps/v1/transactions/{$this->segment($transactionId)}", $environment);
        $signed = $body['signedTransactionInfo'] ?? null;

        if (! is_string($signed) || $signed === '') {
            throw new StoreApiException('The App Store Server API returned no signedTransactionInfo.', Platform::Apple, 200);
        }

        return $signed;
    }

    /**
     * GET /inApps/v1/subscriptions/{transactionId} → the status response (signed values unverified).
     *
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    public function getAllSubscriptionStatuses(string $transactionId, Environment $environment): array
    {
        return $this->get("/inApps/v1/subscriptions/{$this->segment($transactionId)}", $environment);
    }

    /**
     * The bearer token: an ES256 JWT valid for 5 minutes, reused until shortly before expiry.
     *
     * @throws ConfigurationException
     */
    public function token(): string
    {
        $now = Time::now()->getTimestamp();

        if ($this->token !== null && $now < $this->tokenIssuedAt + self::TOKEN_TTL_SECONDS - self::TOKEN_REFRESH_MARGIN_SECONDS && $now >= $this->tokenIssuedAt) {
            return $this->token;
        }

        try {
            $this->token = Jwt::signEs256(
                ['alg' => 'ES256', 'kid' => $this->config->keyId, 'typ' => 'JWT'],
                [
                    'iss' => $this->config->issuerId,
                    'iat' => $now,
                    'exp' => $now + self::TOKEN_TTL_SECONDS,
                    'aud' => 'appstoreconnect-v1',
                    'bid' => $this->config->bundleId,
                ],
                Keys::privateKey((string) $this->config->privateKey),
            );
        } catch (CryptoException) {
            throw new ConfigurationException('Configuration [iap-guard.apple.api.private_key] is not a valid PKCS#8 EC private key (.p8).', Platform::Apple, 'apple.api.private_key');
        }

        $this->tokenIssuedAt = $now;

        return $this->token;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    private function get(string $path, Environment $environment): array
    {
        $url = $this->config->baseUrls[$environment->value].$path;
        $token = $this->token();

        $response = $this->http->send(
            static fn (PendingRequest $request): Response => $request->withToken($token)->get($url),
        );

        if ($response->successful()) {
            $json = $response->json();

            if (! is_array($json)) {
                throw new StoreApiException('The App Store Server API returned a malformed body.', Platform::Apple, $response->status());
            }

            /** @var array<string, mixed> $json */
            return $json;
        }

        throw $this->error($response);
    }

    private function error(Response $response): IapException
    {
        $status = $response->status();
        $body = $response->json();
        $body = is_array($body) ? $body : null;

        if ($status === 401) {
            return new AuthenticationException('The App Store Server API rejected our credentials (401).', Platform::Apple, 401);
        }

        if ($status === 404 && ($body['errorCode'] ?? null) === self::TRANSACTION_ID_NOT_FOUND) {
            return new PurchaseNotFoundException(Platform::Apple, 404);
        }

        $retryable = StoreHttpClient::isRetryable($response);
        $message = is_string($body['errorMessage'] ?? null) ? $body['errorMessage'] : 'Unexpected response.';

        return new StoreApiException(
            "App Store Server API error ({$status}): {$message}",
            Platform::Apple,
            $status,
            $body ?? $response->body(),
            $retryable,
        );
    }

    private function segment(string $value): string
    {
        return rawurlencode($value);
    }
}
