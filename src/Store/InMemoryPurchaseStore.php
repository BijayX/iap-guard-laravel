<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Store;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;

/**
 * Process-local store for tests and local development. Not shared between requests.
 */
class InMemoryPurchaseStore implements PurchaseStore
{
    /** @var array<string, PurchaseRecord> */
    protected array $records = [];

    /** @var array<string, string> token key → record key */
    protected array $tokens = [];

    /** @var array<string, true> */
    protected array $notifications = [];

    public function findByKey(Platform $platform, string $key): ?PurchaseRecord
    {
        return $this->records[self::id($platform, $key)] ?? null;
    }

    public function findByToken(Platform $platform, string $token): ?PurchaseRecord
    {
        $recordId = $this->tokens[self::id($platform, $token)] ?? null;

        return $recordId === null ? null : ($this->records[$recordId] ?? null);
    }

    public function findByUser(string $userId): array
    {
        return array_values(array_filter($this->records, static fn (PurchaseRecord $r): bool => $r->userId === $userId));
    }

    public function insert(PurchaseRecord $record): bool
    {
        $id = self::id($record->platform, $record->key);

        if (isset($this->records[$id]) || ! $this->tokensAvailable($record, $id)) {
            return false;
        }

        $this->records[$id] = $record;
        $this->syncTokens($record, $id);

        return true;
    }

    public function replace(PurchaseRecord $record, int $expectedVersion): bool
    {
        $id = self::id($record->platform, $record->key);
        $current = $this->records[$id] ?? null;

        if ($current === null || $current->version !== $expectedVersion || ! $this->tokensAvailable($record, $id)) {
            return false;
        }

        $this->records[$id] = $record;
        $this->syncTokens($record, $id);

        return true;
    }

    public function rememberNotification(Platform $platform, string $id): bool
    {
        $key = self::id($platform, $id);

        if (isset($this->notifications[$key])) {
            return false;
        }

        $this->notifications[$key] = true;

        return true;
    }

    public function forgetNotification(Platform $platform, string $id): void
    {
        unset($this->notifications[self::id($platform, $id)]);
    }

    /**
     * @return list<PurchaseRecord>
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    private function tokensAvailable(PurchaseRecord $record, string $recordId): bool
    {
        foreach ($record->tokens as $token) {
            $owner = $this->tokens[self::id($record->platform, $token)] ?? null;

            if ($owner !== null && $owner !== $recordId) {
                return false;
            }
        }

        return true;
    }

    private function syncTokens(PurchaseRecord $record, string $recordId): void
    {
        $this->tokens = array_filter($this->tokens, static fn (string $owner): bool => $owner !== $recordId);

        foreach ($record->tokens as $token) {
            $this->tokens[self::id($record->platform, $token)] = $recordId;
        }
    }

    private static function id(Platform $platform, string $value): string
    {
        return $platform->value."\0".$value;
    }
}
