<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Events;

use BijayX\IapGuard\Data\ClaimResult;

/**
 * Dispatched after every successful claim(). Grant consumables only when $result->isNew.
 */
final readonly class PurchaseClaimed
{
    public function __construct(public string $userId, public ClaimResult $result) {}
}
