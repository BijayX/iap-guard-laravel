<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Support;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Events\IapNotificationProcessed;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Dedupe + forget-on-failure around notification processing (SPEC §8).
 *
 * @internal
 */
final readonly class NotificationGuard
{
    public function __construct(private PurchaseStore $store, private Dispatcher $events) {}

    /**
     * @param  Closure(): NotificationResult  $duplicate  Result to return when already processed.
     * @param  Closure(): NotificationResult  $process
     */
    public function run(Platform $platform, string $id, Closure $duplicate, Closure $process): NotificationResult
    {
        if (! $this->store->rememberNotification($platform, $id)) {
            return $duplicate();
        }

        try {
            $result = $process();

            // Inside the try: if a listener throws, the store's retry is processed again.
            $this->events->dispatch(new IapNotificationProcessed($result));

            return $result;
        } catch (Throwable $e) {
            $this->store->forgetNotification($platform, $id);

            throw $e;
        }
    }
}
