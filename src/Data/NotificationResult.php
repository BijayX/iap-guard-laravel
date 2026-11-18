<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Data;

use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Enums\Platform;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Outcome of handling an App Store Server Notification or a Play RTDN (SPEC §8).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class NotificationResult implements Arrayable, JsonSerializable
{
    /**
     * @param  string  $id  Apple notificationUUID / Google Pub/Sub messageId.
     * @param  string  $rawType  e.g. "DID_RENEW/BILLING_RECOVERY" or "SUBSCRIPTION_RENEWED".
     * @param  bool  $duplicate  Already processed: nothing else was done and $purchase is null.
     * @param  bool  $stale  Older than the stored state: the stored state was kept.
     * @param  Purchase|null  $purchase  Stored state after processing (null for test/duplicate/unknown).
     * @param  string|null  $userId  Current owner (null when nobody has claimed the purchase yet).
     * @param  array<string, mixed>  $raw  The decoded notification.
     */
    public function __construct(
        public Platform $platform,
        public string $id,
        public NotificationEvent $event,
        public string $rawType,
        public bool $duplicate = false,
        public bool $stale = false,
        public ?Purchase $purchase = null,
        public ?string $userId = null,
        public array $raw = [],
    ) {}

    /**
     * @return array{platform: string, id: string, event: string, rawType: string, duplicate: bool, stale: bool, purchase: array<string, mixed>|null, userId: string|null, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform->value,
            'id' => $this->id,
            'event' => $this->event->value,
            'rawType' => $this->rawType,
            'duplicate' => $this->duplicate,
            'stale' => $this->stale,
            'purchase' => $this->purchase?->toArray(),
            'userId' => $this->userId,
            'raw' => $this->raw,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
