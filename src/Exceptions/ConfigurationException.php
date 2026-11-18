<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The package is missing configuration needed for the requested operation.
 */
final class ConfigurationException extends IapException
{
    public const CODE = 'CONFIGURATION_ERROR';

    public function __construct(string $message, ?Platform $platform = null, ?string $key = null)
    {
        parent::__construct($message, self::CODE, $platform, $key === null ? [] : ['key' => $key]);
    }

    public static function missing(string $key, ?Platform $platform = null): self
    {
        return new self("Configuration [iap-guard.{$key}] is required.", $platform, $key);
    }

    public static function invalid(string $key, string $expected, ?Platform $platform = null): self
    {
        return new self("Configuration [iap-guard.{$key}] is invalid: expected {$expected}.", $platform, $key);
    }
}
