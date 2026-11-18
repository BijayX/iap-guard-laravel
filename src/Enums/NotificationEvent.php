<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

/**
 * Store-independent meaning of an App Store Server Notification or a Play RTDN.
 */
enum NotificationEvent: string
{
    case Purchased = 'purchased';
    case Renewed = 'renewed';
    case Recovered = 'recovered';
    case RenewalFailed = 'renewal_failed';
    case GracePeriodStarted = 'grace_period_started';
    case AutoRenewEnabled = 'auto_renew_enabled';
    case AutoRenewDisabled = 'auto_renew_disabled';
    case PlanChanged = 'plan_changed';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Revoked = 'revoked';
    case RefundReversed = 'refund_reversed';
    case Paused = 'paused';
    case Canceled = 'canceled';
    case PriceChange = 'price_change';
    case ConsumptionRequest = 'consumption_request';
    case Test = 'test';
    case Other = 'other';

    /**
     * Events after which the app should take access (or granted consumables) away.
     */
    public function endsAccess(): bool
    {
        return in_array($this, [self::Refunded, self::Revoked, self::Expired, self::Canceled], true);
    }
}
