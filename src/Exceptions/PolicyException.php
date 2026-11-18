<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The purchase is genuine but your configuration does not accept it.
 */
final class PolicyException extends IapException
{
    public const ENVIRONMENT_NOT_ALLOWED = 'ENVIRONMENT_NOT_ALLOWED';

    public const UNKNOWN_PRODUCT = 'UNKNOWN_PRODUCT';

    public const FAMILY_SHARING_NOT_ALLOWED = 'FAMILY_SHARING_NOT_ALLOWED';

    public static function environmentNotAllowed(Platform $platform, string $environment): self
    {
        return new self("Purchases from the {$environment} environment are not allowed.", self::ENVIRONMENT_NOT_ALLOWED, $platform, ['environment' => $environment]);
    }

    public static function unknownProduct(Platform $platform, string $productId): self
    {
        return new self('The product is not in the configured product allowlist.', self::UNKNOWN_PRODUCT, $platform, ['product_id' => $productId]);
    }

    public static function familySharingNotAllowed(): self
    {
        return new self('Family Sharing purchases are not allowed.', self::FAMILY_SHARING_NOT_ALLOWED, Platform::Apple);
    }
}
