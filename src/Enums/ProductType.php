<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

enum ProductType: string
{
    case Subscription = 'subscription';
    case NonConsumable = 'non_consumable';
    case Consumable = 'consumable';
    case NonRenewingSubscription = 'non_renewing_subscription';

    public function isSubscription(): bool
    {
        return $this === self::Subscription;
    }
}
