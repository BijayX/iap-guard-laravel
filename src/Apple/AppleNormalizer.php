<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Enums\PurchaseStatus;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Support\Time;
use Carbon\CarbonImmutable;

/**
 * Builds a normalized Purchase from verified Apple payloads (SPEC §3, §4).
 */
final class AppleNormalizer
{
    private const TYPES = [
        'Auto-Renewable Subscription' => ProductType::Subscription,
        'Non-Consumable' => ProductType::NonConsumable,
        'Consumable' => ProductType::Consumable,
        'Non-Renewing Subscription' => ProductType::NonRenewingSubscription,
    ];

    private const STATUSES = [
        1 => PurchaseStatus::Active,
        2 => PurchaseStatus::Expired,
        3 => PurchaseStatus::BillingRetry,
        4 => PurchaseStatus::GracePeriod,
        5 => PurchaseStatus::Revoked,
    ];

    /**
     * @param  array<string, mixed>  $transaction  Verified JWSTransactionDecodedPayload.
     * @param  array<string, mixed>|null  $renewalInfo  Verified JWSRenewalInfoDecodedPayload.
     * @param  int|null  $numericStatus  Notification data.status / Server API lastTransactions[].status.
     * @param  CarbonImmutable|null  $signedAt  Override (notifications use the notification's signedDate).
     *
     * @throws VerificationException when required fields are missing
     */
    public function normalize(array $transaction, ?array $renewalInfo = null, ?int $numericStatus = null, ?CarbonImmutable $signedAt = null): Purchase
    {
        $type = self::TYPES[$transaction['type'] ?? ''] ?? throw self::malformed('type');
        $transactionId = self::requiredString($transaction, 'transactionId');
        $originalTransactionId = self::string($transaction, 'originalTransactionId') ?? $transactionId;
        $productId = self::requiredString($transaction, 'productId');

        $signedAt ??= Time::fromMs(max(self::ms($transaction, 'signedDate') ?? 0, self::ms($renewalInfo ?? [], 'signedDate') ?? 0));
        $isSubscription = $type === ProductType::Subscription;
        $renewal = $isSubscription ? $renewalInfo : null;

        return new Purchase(
            platform: Platform::Apple,
            key: $type === ProductType::Consumable ? $transactionId : $originalTransactionId,
            productId: $productId,
            productIds: [$productId],
            type: $type,
            status: self::status($type, $transaction, $renewalInfo, $numericStatus, $signedAt),
            environment: ($transaction['environment'] ?? null) === 'Sandbox' ? Environment::Sandbox : Environment::Production,
            transactionId: $transactionId,
            originalTransactionId: $originalTransactionId,
            purchaseToken: null,
            linkedPurchaseToken: null,
            purchasedAt: self::date($transaction, 'purchaseDate') ?? throw self::malformed('purchaseDate'),
            expiresAt: self::date($transaction, 'expiresDate'),
            gracePeriodExpiresAt: self::date($renewalInfo ?? [], 'gracePeriodExpiresDate'),
            willRenew: $renewal === null ? null : ($renewal['autoRenewStatus'] ?? null) === 1,
            autoRenewProductId: $renewal === null ? null : self::string($renewal, 'autoRenewProductId'),
            accountToken: self::string($transaction, 'appAccountToken'),
            quantity: is_int($transaction['quantity'] ?? null) ? $transaction['quantity'] : 1,
            familyShared: ($transaction['inAppOwnershipType'] ?? null) === 'FAMILY_SHARED',
            acknowledged: null,
            revokedAt: self::date($transaction, 'revocationDate'),
            signedAt: $signedAt,
            raw: ['transaction' => $transaction, 'renewalInfo' => $renewalInfo, 'status' => $numericStatus],
        );
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, mixed>|null  $r
     */
    private static function status(ProductType $type, array $t, ?array $r, ?int $numeric, CarbonImmutable $signedAt): PurchaseStatus
    {
        if (isset($t['revocationDate'])) {
            return PurchaseStatus::Revoked;
        }

        if ($type !== ProductType::Subscription) {
            return PurchaseStatus::Active;
        }

        if ($numeric !== null && isset(self::STATUSES[$numeric])) {
            return self::STATUSES[$numeric];
        }

        $at = Time::toMs($signedAt);
        $expires = self::ms($t, 'expiresDate');
        $grace = self::ms($r ?? [], 'gracePeriodExpiresDate');

        return match (true) {
            $expires !== null && $expires > $at => PurchaseStatus::Active,
            $grace !== null && $grace > $at => PurchaseStatus::GracePeriod,
            ($r['isInBillingRetryPeriod'] ?? null) === true => PurchaseStatus::BillingRetry,
            default => PurchaseStatus::Expired,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function ms(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function date(array $data, string $key): ?CarbonImmutable
    {
        $ms = self::ms($data, $key);

        return $ms === null ? null : Time::fromMs($ms);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : (is_int($value) ? (string) $value : null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $key): string
    {
        return self::string($data, $key) ?? throw self::malformed($key);
    }

    private static function malformed(string $field): VerificationException
    {
        return VerificationException::invalidSignature(Platform::Apple, "The signed transaction is missing {$field}.");
    }
}
