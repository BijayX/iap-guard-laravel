<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Tests\Support;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;
use Closure;

/**
 * Decorates a real store and runs a callback right before insert()/replace(), to simulate
 * another device or webhook winning a race at exactly the wrong moment.
 */
final class InterleavingStore implements PurchaseStore
{
    /** @var list<Closure(PurchaseStore): void> */
    public array $beforeInsert = [];

    /** @var list<Closure(PurchaseStore): void> */
    public array $beforeReplace = [];

    public int $inserts = 0;

    public int $replaces = 0;

    /** @var list<bool> */
    public array $insertResults = [];

    /** @var list<bool> */
    public array $replaceResults = [];

    public function __construct(public readonly PurchaseStore $inner) {}

    public function findByKey(Platform $platform, string $key): ?PurchaseRecord
    {
        return $this->inner->findByKey($platform, $key);
    }

    public function findByToken(Platform $platform, string $token): ?PurchaseRecord
    {
        return $this->inner->findByToken($platform, $token);
    }

    public function findByUser(string $userId): array
    {
        return $this->inner->findByUser($userId);
    }

    public function insert(PurchaseRecord $record): bool
    {
        $this->inserts++;

        if ($hook = array_shift($this->beforeInsert)) {
            $hook($this->inner);
        }

        return $this->insertResults[] = $this->inner->insert($record);
    }

    public function replace(PurchaseRecord $record, int $expectedVersion): bool
    {
        $this->replaces++;

        if ($hook = array_shift($this->beforeReplace)) {
            $hook($this->inner);
        }

        return $this->replaceResults[] = $this->inner->replace($record, $expectedVersion);
    }

    public function rememberNotification(Platform $platform, string $id): bool
    {
        return $this->inner->rememberNotification($platform, $id);
    }

    public function forgetNotification(Platform $platform, string $id): void
    {
        $this->inner->forgetNotification($platform, $id);
    }
}
