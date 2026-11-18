<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Data;

use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Enums\PurchaseStatus;
use BijayX\IapGuard\Support\Time;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A verified purchase, normalized across Apple and Google (SPEC §3). Immutable.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class Purchase implements Arrayable, JsonSerializable
{
    /**
     * @param  string  $key  Ownership key: stays the same across renewals, plan changes, restores and devices.
     * @param  list<string>  $productIds  All products (Google bundles can contain several line items).
     * @param  string  $transactionId  Apple transactionId (latest) / Google order id ("" for pending Google purchases).
     * @param  string|null  $purchaseToken  Google only: the current purchase token.
     * @param  string|null  $accountToken  Apple appAccountToken / Google obfuscatedExternalAccountId.
     * @param  CarbonImmutable  $signedAt  When the store produced this state (Apple signedDate, Google fetch time).
     * @param  array<string, mixed>  $raw  Decoded store payloads. Never contains our secrets.
     */
    public function __construct(
        public Platform $platform,
        public string $key,
        public string $productId,
        public array $productIds,
        public ProductType $type,
        public PurchaseStatus $status,
        public Environment $environment,
        public string $transactionId,
        public ?string $originalTransactionId,
        public ?string $purchaseToken,
        public ?string $linkedPurchaseToken,
        public CarbonImmutable $purchasedAt,
        public ?CarbonImmutable $expiresAt,
        public ?CarbonImmutable $gracePeriodExpiresAt,
        public ?bool $willRenew,
        public ?string $autoRenewProductId,
        public ?string $accountToken,
        public int $quantity,
        public bool $familyShared,
        public ?bool $acknowledged,
        public ?CarbonImmutable $revokedAt,
        public CarbonImmutable $signedAt,
        public array $raw = [],
    ) {}

    /**
     * Whether this purchase grants access at $now (SPEC §3). Time based, so a stored "active"
     * subscription stops granting access at expiresAt even if a webhook is missed.
     */
    public function isEntitled(?DateTimeInterface $now = null): bool
    {
        $nowMs = Time::toMs($now ?? Time::now());

        return match ($this->status) {
            PurchaseStatus::Active => $this->expiresAt === null || Time::toMs($this->expiresAt) > $nowMs,
            PurchaseStatus::GracePeriod => ($until = $this->gracePeriodExpiresAt ?? $this->expiresAt) === null || Time::toMs($until) > $nowMs,
            default => false,
        };
    }

    public function isFor(string $productId): bool
    {
        return $this->productId === $productId || in_array($productId, $this->productIds, true);
    }

    public function withKey(string $key): self
    {
        return self::fromArray(['key' => $key] + $this->toArray());
    }

    public function withAcknowledged(bool $acknowledged): self
    {
        return self::fromArray(['acknowledged' => $acknowledged] + $this->toArray());
    }

    /**
     * Mark revoked at $at (Google voided purchase / SUBSCRIPTION_REVOKED).
     */
    public function withRevoked(DateTimeInterface $at, ?DateTimeInterface $signedAt = null): self
    {
        return self::fromArray([
            'status' => PurchaseStatus::Revoked->value,
            'revokedAt' => Time::iso($at),
            'signedAt' => Time::iso($signedAt ?? $this->signedAt),
        ] + $this->toArray());
    }

    /**
     * JSON-friendly representation: camelCase keys, enum values, ISO-8601 UTC dates with milliseconds.
     * Identical to the Node.js package's JSON output; used for storage as well.
     *
     * @return array{platform: string, key: string, productId: string, productIds: list<string>, type: string, status: string, environment: string, transactionId: string, originalTransactionId: string|null, purchaseToken: string|null, linkedPurchaseToken: string|null, purchasedAt: string, expiresAt: string|null, gracePeriodExpiresAt: string|null, willRenew: bool|null, autoRenewProductId: string|null, accountToken: string|null, quantity: int, familyShared: bool, acknowledged: bool|null, revokedAt: string|null, signedAt: string, raw: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform->value,
            'key' => $this->key,
            'productId' => $this->productId,
            'productIds' => $this->productIds,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'environment' => $this->environment->value,
            'transactionId' => $this->transactionId,
            'originalTransactionId' => $this->originalTransactionId,
            'purchaseToken' => $this->purchaseToken,
            'linkedPurchaseToken' => $this->linkedPurchaseToken,
            'purchasedAt' => Time::iso($this->purchasedAt),
            'expiresAt' => Time::isoOrNull($this->expiresAt),
            'gracePeriodExpiresAt' => Time::isoOrNull($this->gracePeriodExpiresAt),
            'willRenew' => $this->willRenew,
            'autoRenewProductId' => $this->autoRenewProductId,
            'accountToken' => $this->accountToken,
            'quantity' => $this->quantity,
            'familyShared' => $this->familyShared,
            'acknowledged' => $this->acknowledged,
            'revokedAt' => Time::isoOrNull($this->revokedAt),
            'signedAt' => Time::iso($this->signedAt),
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

    /**
     * Rebuild from toArray() output (e.g. the stored JSON column).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $productIds = $data['productIds'] ?? [];

        return new self(
            platform: Platform::from(self::str($data, 'platform')),
            key: self::str($data, 'key'),
            productId: self::str($data, 'productId'),
            productIds: is_array($productIds) ? array_values(array_map(strval(...), array_filter($productIds, is_scalar(...)))) : [],
            type: ProductType::from(self::str($data, 'type')),
            status: PurchaseStatus::from(self::str($data, 'status')),
            environment: Environment::from(self::str($data, 'environment')),
            transactionId: self::str($data, 'transactionId'),
            originalTransactionId: self::nullableStr($data, 'originalTransactionId'),
            purchaseToken: self::nullableStr($data, 'purchaseToken'),
            linkedPurchaseToken: self::nullableStr($data, 'linkedPurchaseToken'),
            purchasedAt: self::date($data, 'purchasedAt') ?? throw new InvalidArgumentException('purchasedAt is required.'),
            expiresAt: self::date($data, 'expiresAt'),
            gracePeriodExpiresAt: self::date($data, 'gracePeriodExpiresAt'),
            willRenew: isset($data['willRenew']) ? (bool) $data['willRenew'] : null,
            autoRenewProductId: self::nullableStr($data, 'autoRenewProductId'),
            accountToken: self::nullableStr($data, 'accountToken'),
            quantity: is_numeric($data['quantity'] ?? null) ? (int) $data['quantity'] : 1,
            familyShared: (bool) ($data['familyShared'] ?? false),
            acknowledged: isset($data['acknowledged']) ? (bool) $data['acknowledged'] : null,
            revokedAt: self::date($data, 'revokedAt'),
            signedAt: self::date($data, 'signedAt') ?? throw new InvalidArgumentException('signedAt is required.'),
            raw: is_array($data['raw'] ?? null) ? self::stringKeys($data['raw']) : [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_scalar($value)) {
            throw new InvalidArgumentException("{$key} is required.");
        }

        return (string) $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function nullableStr(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        return Time::parse($data[$key] ?? null);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     * @return array<string, mixed>
     */
    private static function stringKeys(array $raw): array
    {
        $out = [];

        foreach ($raw as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
