<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Support\ConfigReader;

/**
 * Validated Apple configuration (SPEC §5.1), built from config('iap-guard.apple').
 */
final readonly class AppleConfig
{
    public const BUNDLED_ROOT = __DIR__.'/../../resources/certs/AppleRootCA-G3.pem';

    public const PRODUCTION_URL = 'https://api.storekit.itunes.apple.com';

    public const SANDBOX_URL = 'https://api.storekit-sandbox.itunes.apple.com';

    /**
     * @param  list<Environment>  $environments
     * @param  list<string>  $rootCertificates  PEM or DER contents.
     * @param  array{production: string, sandbox: string}  $baseUrls
     */
    public function __construct(
        public string $bundleId,
        public ?int $appAppleId,
        public array $environments,
        public bool $allowFamilySharing,
        public array $rootCertificates,
        public ?string $issuerId = null,
        public ?string $keyId = null,
        public ?string $privateKey = null,
        public array $baseUrls = ['production' => self::PRODUCTION_URL, 'sandbox' => self::SANDBOX_URL],
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $reader = new ConfigReader($config, 'apple', Platform::Apple);

        $appAppleId = $reader->string('app_apple_id');

        if ($appAppleId !== null && preg_match('/^\d+$/', $appAppleId) !== 1) {
            throw ConfigurationException::invalid('apple.app_apple_id', 'the numeric App Store app id', Platform::Apple);
        }

        $environments = [];

        foreach ($reader->stringList('environments') ?? ['production', 'sandbox'] as $value) {
            $environments[] = Environment::tryFrom(strtolower($value))
                ?? throw ConfigurationException::invalid('apple.environments', '"production" and/or "sandbox"', Platform::Apple);
        }

        if ($environments === []) {
            throw ConfigurationException::invalid('apple.environments', 'at least one of "production" and "sandbox"', Platform::Apple);
        }

        $roots = [];

        $configuredRoots = $reader->get('root_certificates');
        $configuredRoots = is_string($configuredRoots) ? [$configuredRoots] : ($configuredRoots ?? [self::BUNDLED_ROOT]);

        foreach (is_array($configuredRoots) ? $configuredRoots : [null] as $root) {
            $roots[] = (is_string($root) ? ConfigReader::readMaybePath($root) : null)
                ?? throw ConfigurationException::invalid('apple.root_certificates', 'PEM/DER certificates or file paths', Platform::Apple);
        }

        $api = [
            'issuer_id' => $reader->string('api.issuer_id'),
            'key_id' => $reader->string('api.key_id'),
            'private_key' => $reader->contentsOrPath('api.private_key'),
        ];
        $configured = array_filter($api, static fn (?string $v): bool => $v !== null);

        if ($configured !== [] && count($configured) !== count($api)) {
            $missing = array_key_first(array_diff_key($api, $configured));

            throw ConfigurationException::missing("apple.api.{$missing}", Platform::Apple);
        }

        return new self(
            bundleId: $reader->requiredString('bundle_id'),
            appAppleId: $appAppleId === null ? null : (int) $appAppleId,
            environments: array_values(array_unique($environments, SORT_REGULAR)),
            allowFamilySharing: $reader->bool('allow_family_sharing', true),
            rootCertificates: $roots,
            issuerId: $api['issuer_id'],
            keyId: $api['key_id'],
            privateKey: $api['private_key'],
            baseUrls: [
                'production' => rtrim($reader->string('api.base_urls.production') ?? self::PRODUCTION_URL, '/'),
                'sandbox' => rtrim($reader->string('api.base_urls.sandbox') ?? self::SANDBOX_URL, '/'),
            ],
        );
    }

    public function hasApi(): bool
    {
        return $this->issuerId !== null && $this->keyId !== null && $this->privateKey !== null;
    }

    public function allows(Environment $environment): bool
    {
        return in_array($environment, $this->environments, true);
    }

    /**
     * Production first (Apple's documented lookup order), each only when allowed.
     *
     * @return list<Environment>
     */
    public function lookupOrder(): array
    {
        return array_values(array_filter(
            [Environment::Production, Environment::Sandbox],
            fn (Environment $e): bool => $this->allows($e),
        ));
    }
}
