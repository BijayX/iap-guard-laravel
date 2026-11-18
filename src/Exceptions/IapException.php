<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;
use RuntimeException;
use Throwable;

/**
 * Base class for every exception thrown by this package.
 *
 * errorCode() returns a stable, machine-readable code shared with the Node.js package
 * (SPEC §10). Messages and details never contain private keys, access tokens, JWTs,
 * the account-token secret or another user's id.
 */
class IapException extends RuntimeException
{
    public const CONCURRENT_MODIFICATION = 'CONCURRENT_MODIFICATION';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly ?Platform $platform = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Another request kept modifying the same purchase; claim() gave up after three attempts.
     */
    public static function concurrentModification(Platform $platform): self
    {
        return new self(
            'The purchase was modified concurrently by another request. Please retry.',
            self::CONCURRENT_MODIFICATION,
            $platform,
        );
    }

    /**
     * Stable error code, e.g. "INVALID_SIGNATURE" or "ALREADY_OWNED".
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function platform(): ?Platform
    {
        return $this->platform;
    }

    /**
     * Structured, secret-free details.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Log context; Laravel's exception handler merges it into the log entry.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'iap_guard_code' => $this->errorCode,
            'platform' => $this->platform?->value,
            'details' => $this->details,
        ];
    }
}
