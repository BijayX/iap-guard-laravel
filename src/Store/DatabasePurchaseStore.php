<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Store;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Support\Time;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use stdClass;

/**
 * PurchaseStore backed by the package migrations.
 *
 * Atomicity comes from the database, not from locks:
 * - insert(): the unique (platform, key) and (platform, token) indexes; a violation means another
 *   request won the race and false is returned.
 * - replace(): UPDATE ... WHERE platform = ? AND key = ? AND version = ? and the affected-row count.
 * - rememberNotification(): the unique (platform, notification_id) index.
 * Token rows are written in the same transaction as the purchase row.
 */
class DatabasePurchaseStore implements PurchaseStore
{
    public const PURCHASES = 'iap_purchases';

    public const TOKENS = 'iap_purchase_tokens';

    public const NOTIFICATIONS = 'iap_notifications';

    public function __construct(protected ConnectionInterface $db) {}

    public function findByKey(Platform $platform, string $key): ?PurchaseRecord
    {
        $row = $this->purchases()->where('platform', $platform->value)->where('key', $key)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByToken(Platform $platform, string $token): ?PurchaseRecord
    {
        $purchaseId = $this->db->table(self::TOKENS)
            ->where('platform', $platform->value)
            ->where('token', $token)
            ->value('purchase_id');

        if ($purchaseId === null) {
            return null;
        }

        $row = $this->purchases()->where('id', $purchaseId)->first();

        return $row instanceof stdClass ? $this->hydrate($row) : null;
    }

    public function findByUser(string $userId): array
    {
        return array_values($this->purchases()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (stdClass $row): PurchaseRecord => $this->hydrate($row))
            ->all());
    }

    public function insert(PurchaseRecord $record): bool
    {
        try {
            return $this->db->transaction(function () use ($record): bool {
                $id = $this->purchases()->insertGetId(['platform' => $record->platform->value, 'key' => $record->key] + $this->columns($record));

                $this->insertTokens((int) $id, $record->platform, $record->tokens);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function replace(PurchaseRecord $record, int $expectedVersion): bool
    {
        try {
            return $this->db->transaction(function () use ($record, $expectedVersion): bool {
                $affected = $this->purchases()
                    ->where('platform', $record->platform->value)
                    ->where('key', $record->key)
                    ->where('version', $expectedVersion)
                    ->update($this->columns($record));

                if ($affected !== 1) {
                    return false;
                }

                $id = (int) $this->purchases()->where('platform', $record->platform->value)->where('key', $record->key)->value('id');

                $existing = $this->db->table(self::TOKENS)->where('purchase_id', $id)->pluck('token')->map(strval(...))->all();

                $this->db->table(self::TOKENS)
                    ->where('purchase_id', $id)
                    ->whereNotIn('token', $record->tokens === [] ? [''] : $record->tokens)
                    ->delete();

                $this->insertTokens($id, $record->platform, array_values(array_diff($record->tokens, $existing)));

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            // A token of this chain already belongs to another record: the whole replace is rolled back.
            return false;
        }
    }

    public function rememberNotification(Platform $platform, string $id): bool
    {
        try {
            $this->db->table(self::NOTIFICATIONS)->insert([
                'platform' => $platform->value,
                'notification_id' => $id,
                'created_at' => Time::toDatabase(Time::now()),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function forgetNotification(Platform $platform, string $id): void
    {
        $this->db->table(self::NOTIFICATIONS)->where('platform', $platform->value)->where('notification_id', $id)->delete();
    }

    protected function purchases(): Builder
    {
        return $this->db->table(self::PURCHASES);
    }

    /**
     * Promoted columns (for querying with the IapPurchase model) plus the canonical JSON state.
     *
     * @return array<string, mixed>
     */
    protected function columns(PurchaseRecord $record): array
    {
        $p = $record->purchase;

        return [
            'user_id' => $record->userId,
            'product_id' => $p->productId,
            'type' => $p->type->value,
            'status' => $p->status->value,
            'environment' => $p->environment->value,
            'transaction_id' => $p->transactionId,
            'original_transaction_id' => $p->originalTransactionId,
            'purchase_token' => $p->purchaseToken,
            'account_token' => $p->accountToken,
            'purchased_at' => Time::toDatabase($p->purchasedAt),
            'expires_at' => Time::toDatabase($p->expiresAt),
            'grace_period_expires_at' => Time::toDatabase($p->gracePeriodExpiresAt),
            'revoked_at' => Time::toDatabase($p->revokedAt),
            'will_renew' => $p->willRenew,
            'family_shared' => $p->familyShared,
            'purchase' => json_encode($p->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_event_at' => Time::toDatabase($record->lastEventAt),
            'claimed_at' => Time::toDatabase($record->claimedAt),
            'version' => $record->version,
            'created_at' => Time::toDatabase($record->createdAt),
            'updated_at' => Time::toDatabase($record->updatedAt),
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function insertTokens(int $purchaseId, Platform $platform, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $this->db->table(self::TOKENS)->insert(array_map(static fn (string $token): array => [
            'purchase_id' => $purchaseId,
            'platform' => $platform->value,
            'token' => $token,
        ], $tokens));
    }

    protected function hydrate(stdClass $row): PurchaseRecord
    {
        $data = json_decode((string) $row->purchase, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Corrupt iap_purchases.purchase JSON.');
        }

        $tokens = $this->db->table(self::TOKENS)->where('purchase_id', $row->id)->orderBy('id')->pluck('token')->map(strval(...))->all();
        $now = Time::now();

        return new PurchaseRecord(
            platform: Platform::from((string) $row->platform),
            key: (string) $row->key,
            userId: $row->user_id === null ? null : (string) $row->user_id,
            purchase: Purchase::fromArray($data),
            tokens: array_values($tokens),
            lastEventAt: Time::fromDatabase($row->last_event_at) ?? $now,
            version: (int) $row->version,
            createdAt: Time::fromDatabase($row->created_at) ?? $now,
            updatedAt: Time::fromDatabase($row->updated_at) ?? $now,
            claimedAt: Time::fromDatabase($row->claimed_at),
        );
    }
}
