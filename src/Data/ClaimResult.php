<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Data;

use BijayX\IapGuard\Enums\Ownership;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Outcome of IapGuard::claim() (SPEC §7.2).
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class ClaimResult implements Arrayable, JsonSerializable
{
    /**
     * @param  Purchase  $purchase  The stored state (may be newer than the proof the client sent).
     * @param  bool  $isEntitled  Whether $purchase grants access right now.
     * @param  bool  $isNew  True only the first time this transaction/token is claimed. Grant consumables only when true.
     * @param  string|null  $previousUserId  Set when ownership is "transferred": revoke access for that user.
     * @param  list<Purchase>  $otherActive  The user's OTHER entitled purchases (e.g. already subscribed on the other platform).
     */
    public function __construct(
        public Purchase $purchase,
        public bool $isEntitled,
        public bool $isNew,
        public Ownership $ownership,
        public ?string $previousUserId = null,
        public array $otherActive = [],
    ) {}

    /**
     * @return array{purchase: array<string, mixed>, isEntitled: bool, isNew: bool, ownership: string, previousUserId: string|null, otherActive: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'purchase' => $this->purchase->toArray(),
            'isEntitled' => $this->isEntitled,
            'isNew' => $this->isNew,
            'ownership' => $this->ownership->value,
            'previousUserId' => $this->previousUserId,
            'otherActive' => array_map(static fn (Purchase $p): array => $p->toArray(), $this->otherActive),
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
