<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Events;

use BijayX\IapGuard\Data\NotificationResult;

/**
 * Dispatched once per processed App Store / Play notification (not for duplicates).
 *
 * $result->purchase is the stored state after processing and $result->userId its owner
 * (null when nobody has claimed it yet). If a synchronous listener throws, the webhook
 * answers 500 and the store retries the notification.
 */
final readonly class IapNotificationProcessed
{
    public function __construct(public NotificationResult $result) {}
}
