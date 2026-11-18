<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Contracts;

use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;

/**
 * Persistence for purchase ownership (SPEC §9).
 *
 * insert(), replace() and rememberNotification() MUST be atomic: they are what guarantees a
 * purchase never has two owners and a notification is processed once, even when two devices
 * (or a device and a webhook) race.
 */
interface PurchaseStore
{
    public function findByKey(Platform $platform, string $key): ?PurchaseRecord;

    /**
     * Google: the record whose chain contains $token.
     */
    public function findByToken(Platform $platform, string $token): ?PurchaseRecord;

    /**
     * @return list<PurchaseRecord>
     */
    public function findByUser(string $userId): array;

    /**
     * Insert a new record. Returns false if a record with the same (platform, key) exists,
     * or one of its tokens already belongs to another record.
     */
    public function insert(PurchaseRecord $record): bool;

    /**
     * Replace the stored record with $record (whose version is $expectedVersion + 1).
     * Returns false if the stored version is not $expectedVersion.
     */
    public function replace(PurchaseRecord $record, int $expectedVersion): bool;

    /**
     * Returns false if the notification was already remembered.
     */
    public function rememberNotification(Platform $platform, string $id): bool;

    public function forgetNotification(Platform $platform, string $id): void;
}
