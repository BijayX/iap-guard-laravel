<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The purchase belongs to (or was bound to) a different app user.
 * The message never reveals who that user is.
 */
final class OwnershipException extends IapException
{
    public const ALREADY_OWNED = 'ALREADY_OWNED';

    public const ACCOUNT_MISMATCH = 'ACCOUNT_MISMATCH';

    public static function alreadyOwned(Platform $platform): self
    {
        return new self('This purchase is already linked to another account.', self::ALREADY_OWNED, $platform);
    }

    public static function accountMismatch(Platform $platform): self
    {
        return new self('This purchase was made for another account.', self::ACCOUNT_MISMATCH, $platform);
    }
}
