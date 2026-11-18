<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Ownership;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\ClaimResult;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\ConflictPolicy;
use BijayX\IapGuard\Enums\Ownership;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Events\PurchaseClaimed;
use BijayX\IapGuard\Events\PurchaseOwnershipTransferred;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\OwnershipException;
use BijayX\IapGuard\Google\PurchaseAcknowledger;
use BijayX\IapGuard\Support\Time;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Ownership rules for claim() (SPEC §7.3): one owner per ownership key, enforced with an atomic
 * insert and a versioned replace, retried at most three times.
 */
final readonly class ClaimProcessor
{
    public const MAX_ATTEMPTS = 3;

    /**
     * @param  Closure(): PurchaseAcknowledger  $acknowledger  Resolved lazily (Google only).
     */
    public function __construct(
        private PurchaseStore $store,
        private StateSynchronizer $sync,
        private AccountTokens $accountTokens,
        private ConflictPolicy $policy,
        private Dispatcher $events,
        private Closure $acknowledger,
    ) {}

    /**
     * @param  Purchase  $verified  Output of verify(): genuine, policy checked.
     *
     * @throws IapException
     */
    public function claim(string $userId, Purchase $verified): ClaimResult
    {
        $purchase = $this->sync->resolveKey($verified);

        if ($this->accountTokens->enabled()
            && $purchase->accountToken !== null
            && ! $this->accountTokens->matches($purchase->accountToken, $userId)
            && $this->policy === ConflictPolicy::Reject) {
            throw OwnershipException::accountMismatch($purchase->platform);
        }

        [$record, $ownership, $isNew, $previousUserId] = $this->persist($userId, $purchase);

        $stored = $record->purchase->platform === Platform::Google
            ? ($this->acknowledger)()->acknowledgeIfNeeded($record)
            : $record->purchase;

        $now = Time::now();
        $otherActive = [];

        foreach ($this->store->findByUser($userId) as $other) {
            if (($other->platform !== $record->platform || $other->key !== $record->key) && $other->purchase->isEntitled($now)) {
                $otherActive[] = $other->purchase;
            }
        }

        $result = new ClaimResult(
            purchase: $stored,
            isEntitled: $stored->isEntitled($now),
            isNew: $isNew,
            ownership: $ownership,
            previousUserId: $previousUserId,
            otherActive: $otherActive,
        );

        $this->events->dispatch(new PurchaseClaimed($userId, $result));

        if ($previousUserId !== null) {
            $this->events->dispatch(new PurchaseOwnershipTransferred($stored, $previousUserId, $userId));
        }

        return $result;
    }

    /**
     * Release every record owned by $userId (account deletion) so the store account can be
     * restored into another app account. Returns the number of records released.
     *
     * @throws IapException
     */
    public function releaseUser(string $userId): int
    {
        $released = 0;

        foreach ($this->store->findByUser($userId) as $record) {
            for ($attempt = 0; ; $attempt++) {
                if ($this->store->replace($record->next(Time::now(), changeUser: true, userId: null), $record->version)) {
                    $released++;

                    break;
                }

                $record = $this->store->findByKey($record->platform, $record->key);

                if ($record === null || $record->userId !== $userId) {
                    break;
                }

                if ($attempt + 1 >= self::MAX_ATTEMPTS) {
                    throw IapException::concurrentModification($record->platform);
                }
            }
        }

        return $released;
    }

    /**
     * @return array{PurchaseRecord, Ownership, bool, string|null}
     *
     * @throws IapException
     */
    private function persist(string $userId, Purchase $purchase): array
    {
        $tokens = StateSynchronizer::tokensOf($purchase);

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $now = Time::now();
            $record = $this->store->findByKey($purchase->platform, $purchase->key);

            if ($record === null) {
                $record = PurchaseRecord::create($purchase, $userId, $tokens, $now);

                if (! $this->store->insert($record)) {
                    continue; // Another device won the race; re-read and apply the ownership rules.
                }

                return [$record, Ownership::Created, true, null];
            }

            $isNew = $record->claimedAt === null || $this->isNewTransaction($record, $purchase);
            $previousUserId = null;

            if ($record->userId === null || $record->userId === $userId) {
                $ownership = $record->userId === null ? Ownership::Created : Ownership::Existing;
            } elseif ($this->policy === ConflictPolicy::Reject) {
                throw OwnershipException::alreadyOwned($purchase->platform);
            } else {
                $ownership = Ownership::Transferred;
                $previousUserId = $record->userId;
            }

            // Stale proofs still prove ownership but never roll the stored state back.
            $newer = Time::toMs($purchase->signedAt) >= Time::toMs($record->lastEventAt);

            $next = $record->next(
                $now,
                purchase: $newer ? $purchase : null,
                tokens: $tokens,
                lastEventAt: Time::max($record->lastEventAt, $purchase->signedAt),
                changeUser: true,
                userId: $userId,
                claimedAt: $now,
            );

            if ($this->store->replace($next, $record->version)) {
                return [$next, $ownership, $isNew, $previousUserId];
            }
        }

        throw IapException::concurrentModification($purchase->platform);
    }

    private function isNewTransaction(PurchaseRecord $record, Purchase $purchase): bool
    {
        if ($purchase->platform === Platform::Apple) {
            return $record->purchase->transactionId !== $purchase->transactionId
                && Time::toMs($purchase->signedAt) > Time::toMs($record->lastEventAt);
        }

        return $purchase->purchaseToken !== null && ! $record->hasToken($purchase->purchaseToken);
    }
}
