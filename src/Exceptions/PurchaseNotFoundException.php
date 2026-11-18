<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The store does not know the transaction id / purchase token (or it expired long ago).
 */
final class PurchaseNotFoundException extends IapException
{
    public const CODE = 'PURCHASE_NOT_FOUND';

    public function __construct(Platform $platform, ?int $statusCode = null)
    {
        parent::__construct('The store could not find this purchase.', self::CODE, $platform, ['status_code' => $statusCode]);
    }
}
