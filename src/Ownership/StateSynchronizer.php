<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Ownership;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Support\Time;

/**
 * Store operations shared by claim() and the webhook handlers: Google chain resolution and the
 * "never roll back, never change the owner" upsert used for notifications (SPEC §8).
 */
final readonly class StateSynchronizer
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private PurchaseStore $store) {}

    /**
     * Google subscriptions: an upgrade/downgrade/resubscribe gets a new token whose
     * linkedPurchaseToken points to the previous one. Resolve the chain root (SPEC §7.3 step 2)
     * so the new token joins the existing record.
     */
    public function resolveKey(Purchase $purchase): Purchase
    {
        if ($purchase->platform !== Platform::Google || $purchase->type !== ProductType::Subscription) {
            return $purchase;
        }

        $record = $this->chainRecord($purchase);

        return $record === null || $record->key === $purchase->key ? $purchase : $purchase->withKey($record->key);
    }

    /**
     * The stored record of the chain this Google purchase belongs to (by linked token, then by token).
     */
    public function chainRecord(Purchase $purchase): ?PurchaseRecord
    {
        if ($purchase->linkedPurchaseToken !== null) {
            $record = $this->store->findByToken(Platform::Google, $purchase->linkedPurchaseToken);

            if ($record !== null) {
                return $record;
            }
        }

        return $purchase->purchaseToken === null ? null : $this->store->findByToken(Platform::Google, $purchase->purchaseToken);
    }

    /**
     * Insert or update the record for $purchase without touching its owner. Older state never
     * overwrites newer state (stale = true).
     *
     * @return array{record: PurchaseRecord, stale: bool}
     *
     * @throws IapException
     */
    public function upsert(Purchase $purchase): array
    {
        $purchase = $this->resolveKey($purchase);
        $tokens = self::tokensOf($purchase);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $now = Time::now();
            $record = $this->store->findByKey($purchase->platform, $purchase->key);

            if ($record === null) {
                $record = PurchaseRecord::create($purchase, null, $tokens, $now);

                if ($this->store->insert($record)) {
                    return ['record' => $record, 'stale' => false];
                }

                continue;
            }

            $stale = Time::toMs($purchase->signedAt) < Time::toMs($record->lastEventAt);
            $newTokens = array_diff($tokens, $record->tokens);

            if ($stale && $newTokens === []) {
                return ['record' => $record, 'stale' => true];
            }

            $next = $record->next(
                $now,
                purchase: $stale ? null : $purchase,
                tokens: $tokens,
                lastEventAt: Time::max($record->lastEventAt, $purchase->signedAt),
            );

            if ($this->store->replace($next, $record->version)) {
                return ['record' => $next, 'stale' => $stale];
            }
        }

        throw IapException::concurrentModification($purchase->platform);
    }

    /**
     * @return list<string>
     */
    public static function tokensOf(Purchase $purchase): array
    {
        return PurchaseRecord::uniqueTokens([$purchase->purchaseToken, $purchase->linkedPurchaseToken]);
    }
}
