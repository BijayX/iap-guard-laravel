<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

enum Ownership: string
{
    /** The purchase had no owner and now belongs to the claiming user. */
    case Created = 'created';

    /** The claiming user already owned the purchase (restore, second device). */
    case Existing = 'existing';

    /** The purchase belonged to another user and was moved (conflict policy "transfer"). */
    case Transferred = 'transferred';
}
