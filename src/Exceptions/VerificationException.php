<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The proof or webhook is not genuine, is for another app, or is malformed.
 */
final class VerificationException extends IapException
{
    public const INVALID_SIGNATURE = 'INVALID_SIGNATURE';

    public const WRONG_APP = 'WRONG_APP';

    public const PRODUCT_MISMATCH = 'PRODUCT_MISMATCH';

    public const INVALID_NOTIFICATION = 'INVALID_NOTIFICATION';

    /**
     * Messages are deliberately generic: they never echo the token or say which check failed
     * beyond a short reason useful for logs.
     */
    public static function invalidSignature(Platform $platform, string $reason = 'The signature could not be verified.'): self
    {
        return new self($reason, self::INVALID_SIGNATURE, $platform);
    }

    public static function wrongApp(Platform $platform, string $message = 'The purchase belongs to a different app.'): self
    {
        return new self($message, self::WRONG_APP, $platform);
    }

    public static function productMismatch(Platform $platform, string $expected): self
    {
        return new self('The purchase is not for the expected product.', self::PRODUCT_MISMATCH, $platform, ['expected_product_id' => $expected]);
    }

    public static function invalidNotification(Platform $platform, string $reason): self
    {
        return new self($reason, self::INVALID_NOTIFICATION, $platform);
    }
}
