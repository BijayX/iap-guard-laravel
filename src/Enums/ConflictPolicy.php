<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

/**
 * What claim() does when another app user already owns the purchase.
 */
enum ConflictPolicy: string
{
    /** Throw OwnershipException(ALREADY_OWNED). */
    case Reject = 'reject';

    /** Move the purchase to the claiming user and report the previous owner. */
    case Transfer = 'transfer';
}
