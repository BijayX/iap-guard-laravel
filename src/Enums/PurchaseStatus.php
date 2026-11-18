<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case GracePeriod = 'grace_period';
    case BillingRetry = 'billing_retry';
    case Paused = 'paused';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Canceled = 'canceled';

    /**
     * Statuses that may grant access (subject to the expiry checks in Purchase::isEntitled()).
     */
    public function grantsAccess(): bool
    {
        return $this === self::Active || $this === self::GracePeriod;
    }
}
