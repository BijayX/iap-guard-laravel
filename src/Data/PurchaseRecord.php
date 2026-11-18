<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Data;

use BijayX\IapGuard\Enums\Platform;
use Carbon\CarbonImmutable;

/**
 * What a PurchaseStore persists for one ownership key (SPEC §9).
 */
final readonly class PurchaseRecord
{
    /**
     * @param  string|null  $userId  Current owner; null until claimed (webhook first) or after releaseUser().
     * @param  list<string>  $tokens  Google: every purchase token of the chain. Apple: empty.
     * @param  CarbonImmutable  $lastEventAt  signedAt of the newest state seen; older proofs never roll state back.
     * @param  int  $version  Optimistic-concurrency version, incremented on every replace().
     * @param  CarbonImmutable|null  $claimedAt  First time any user claimed this record. Never cleared, so a
     *                                           released consumable is not reported as new again.
     */
    public function __construct(
        public Platform $platform,
        public string $key,
        public ?string $userId,
        public Purchase $purchase,
        public array $tokens,
        public CarbonImmutable $lastEventAt,
        public int $version,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
        public ?CarbonImmutable $claimedAt = null,
    ) {}

    /**
     * A new record (version 1) for a freshly verified purchase.
     *
     * @param  list<string>  $tokens
     */
    public static function create(Purchase $purchase, ?string $userId, array $tokens, CarbonImmutable $now): self
    {
        return new self(
            platform: $purchase->platform,
            key: $purchase->key,
            userId: $userId,
            purchase: $purchase,
            tokens: self::uniqueTokens($tokens),
            lastEventAt: $purchase->signedAt,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            claimedAt: $userId === null ? null : $now,
        );
    }

    /**
     * The next version of this record. Pass only what changes.
     *
     * @param  list<string>|null  $tokens
     */
    public function next(
        CarbonImmutable $now,
        ?Purchase $purchase = null,
        ?array $tokens = null,
        ?CarbonImmutable $lastEventAt = null,
        bool $changeUser = false,
        ?string $userId = null,
        ?CarbonImmutable $claimedAt = null,
    ): self {
        return new self(
            platform: $this->platform,
            key: $this->key,
            userId: $changeUser ? $userId : $this->userId,
            purchase: $purchase ?? $this->purchase,
            tokens: $tokens === null ? $this->tokens : self::uniqueTokens([...$this->tokens, ...$tokens]),
            lastEventAt: $lastEventAt ?? $this->lastEventAt,
            version: $this->version + 1,
            createdAt: $this->createdAt,
            updatedAt: $now,
            claimedAt: $this->claimedAt ?? $claimedAt,
        );
    }

    public function hasToken(string $token): bool
    {
        return in_array($token, $this->tokens, true);
    }

    /**
     * @param  array<array-key, string|null>  $tokens
     * @return list<string>
     */
    public static function uniqueTokens(array $tokens): array
    {
        return array_values(array_unique(array_filter($tokens, static fn (?string $t): bool => $t !== null && $t !== '')));
    }
}
