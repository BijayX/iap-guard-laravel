<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Events;

use BijayX\IapGuard\Data\Purchase;

/**
 * Dispatched when the "transfer" conflict policy moved a purchase to another user.
 * Revoke whatever you granted $fromUserId for this purchase.
 */
final readonly class PurchaseOwnershipTransferred
{
    public function __construct(
        public Purchase $purchase,
        public string $fromUserId,
        public string $toUserId,
    ) {}
}
