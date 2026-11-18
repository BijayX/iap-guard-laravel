<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Support\ConfigReader;
use JsonException;

/**
 * Validated Google Play configuration (SPEC §6.1), built from config('iap-guard.google').
 */
final readonly class GoogleConfig
{
    public const BASE_URL = 'https://androidpublisher.googleapis.com/androidpublisher/v3/';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /**
     * @param  list<string>  $consumableProductIds
     * @param  array{client_email: string, private_key: string, token_uri: string|null}|null  $serviceAccount
     */
    public function __construct(
        public string $packageName,
        public ?array $serviceAccount,
        public array $consumableProductIds = [],
        public bool $allowTestPurchases = true,
        public ?string $pubsubAudience = null,
        public ?string $pubsubServiceAccountEmail = null,
        public ?string $pubsubVerificationToken = null,
        public string $baseUrl = self::BASE_URL,
        public ?string $tokenUrlOverride = null,
        public string $jwksUrl = self::JWKS_URL,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $reader = new ConfigReader($config, 'google', Platform::Google);

        return new self(
            packageName: $reader->requiredString('package_name'),
            serviceAccount: self::serviceAccount($config['service_account'] ?? null),
            consumableProductIds: $reader->stringList('consumable_product_ids') ?? [],
            allowTestPurchases: $reader->bool('allow_test_purchases', true),
            pubsubAudience: $reader->string('pubsub.audience'),
            pubsubServiceAccountEmail: $reader->string('pubsub.service_account_email'),
            pubsubVerificationToken: $reader->string('pubsub.verification_token'),
            baseUrl: rtrim($reader->string('base_url') ?? self::BASE_URL, '/').'/',
            tokenUrlOverride: $reader->string('token_url'),
            jwksUrl: $reader->string('jwks_url') ?? self::JWKS_URL,
        );
    }

    /**
     * The service account; required for every Play Developer API call.
     *
     * @return array{client_email: string, private_key: string, token_uri: string|null}
     *
     * @throws ConfigurationException
     */
    public function requireServiceAccount(): array
    {
        return $this->serviceAccount ?? throw ConfigurationException::missing('google.service_account', Platform::Google);
    }

    public function tokenUrl(): string
    {
        return $this->tokenUrlOverride ?? $this->serviceAccount['token_uri'] ?? self::TOKEN_URL;
    }

    public function isConsumable(string $productId): bool
    {
        return in_array($productId, $this->consumableProductIds, true);
    }

    /**
     * Accepts the decoded array, a JSON string, or a path to the JSON key file.
     *
     * @return array{client_email: string, private_key: string, token_uri: string|null}|null
     *
     * @throws ConfigurationException
     */
    private static function serviceAccount(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $json = ConfigReader::readMaybePath($value);

            try {
                $value = $json === null ? null : json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $value = null;
            }
        }

        if (! is_array($value) || ! is_string($value['client_email'] ?? null) || ! is_string($value['private_key'] ?? null)
            || $value['client_email'] === '' || $value['private_key'] === '') {
            // Never echo the value: it contains a private key.
            throw ConfigurationException::invalid('google.service_account', 'a service-account JSON string, array or file path with client_email and private_key', Platform::Google);
        }

        return [
            'client_email' => $value['client_email'],
            'private_key' => $value['private_key'],
            'token_uri' => is_string($value['token_uri'] ?? null) && $value['token_uri'] !== '' ? $value['token_uri'] : null,
        ];
    }
}
