<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;
use Throwable;

/**
 * Apple or Google rejected our credentials (App Store Server API key, Google service account).
 */
final class AuthenticationException extends IapException
{
    public const CODE = 'AUTHENTICATION_FAILED';

    public function __construct(string $message, Platform $platform, ?int $statusCode = null, ?Throwable $previous = null)
    {
        parent::__construct($message, self::CODE, $platform, ['status_code' => $statusCode], $previous);
    }
}
