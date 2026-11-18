<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Enums\PurchaseStatus;
use BijayX\IapGuard\Exceptions\StoreApiException;
use BijayX\IapGuard\Support\Time;
use Carbon\CarbonImmutable;

/**
 * Builds a normalized Purchase from Play Developer API responses (SPEC §3, §4).
 * The key is the token itself; claim() / notifications resolve the chain root.
 */
final readonly class GoogleNormalizer
{
    private const SUBSCRIPTION_STATES = [
        'SUBSCRIPTION_STATE_PENDING' => PurchaseStatus::Pending,
        'SUBSCRIPTION_STATE_ACTIVE' => PurchaseStatus::Active,
        'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => PurchaseStatus::GracePeriod,
        'SUBSCRIPTION_STATE_ON_HOLD' => PurchaseStatus::BillingRetry,
        'SUBSCRIPTION_STATE_PAUSED' => PurchaseStatus::Paused,
        'SUBSCRIPTION_STATE_EXPIRED' => PurchaseStatus::Expired,
        'SUBSCRIPTION_STATE_PENDING_PURCHASE_CANCELED' => PurchaseStatus::Canceled,
    ];

    private const PRODUCT_STATES = [
        'PURCHASED' => PurchaseStatus::Active,
        'PENDING' => PurchaseStatus::Pending,
        'CANCELLED' => PurchaseStatus::Canceled,
    ];

    public function __construct(private GoogleConfig $config) {}

    /**
     * @param  array<string, mixed>  $body  purchases.subscriptionsv2.get response.
     */
    public function subscription(string $token, array $body, CarbonImmutable $fetchedAt): Purchase
    {
        $items = self::list($body['lineItems'] ?? null);
        $first = $items[0] ?? [];

        $expiresAt = null;

        foreach ($items as $item) {
            $expiry = Time::parse($item['expiryTime'] ?? null);

            if ($expiry !== null && ($expiresAt === null || $expiry->greaterThan($expiresAt))) {
                $expiresAt = $expiry;
            }
        }

        $state = $body['subscriptionState'] ?? null;
        $canceled = $state === 'SUBSCRIPTION_STATE_CANCELED';

        $status = $canceled
            ? ($expiresAt !== null && $expiresAt->greaterThan($fetchedAt) ? PurchaseStatus::Active : PurchaseStatus::Expired)
            : (self::SUBSCRIPTION_STATES[is_string($state) ? $state : ''] ?? PurchaseStatus::Expired);

        $willRenew = match (true) {
            $canceled => false,
            is_array($first['autoRenewingPlan'] ?? null) => ($first['autoRenewingPlan']['autoRenewEnabled'] ?? null) === true,
            is_array($first['prepaidPlan'] ?? null) => false,
            default => null,
        };

        return new Purchase(
            platform: Platform::Google,
            key: $token,
            productId: self::productId($first),
            productIds: array_map(self::productId(...), $items),
            type: ProductType::Subscription,
            status: $status,
            environment: array_key_exists('testPurchase', $body) ? Environment::Sandbox : Environment::Production,
            transactionId: self::string($first, 'latestSuccessfulOrderId') ?? self::string($body, 'latestOrderId') ?? '',
            originalTransactionId: null,
            purchaseToken: $token,
            linkedPurchaseToken: self::string($body, 'linkedPurchaseToken'),
            purchasedAt: Time::parse($body['startTime'] ?? null) ?? $fetchedAt,
            expiresAt: $expiresAt,
            gracePeriodExpiresAt: null,
            willRenew: $willRenew,
            autoRenewProductId: null,
            accountToken: self::accountToken(is_array($body['externalAccountIdentifiers'] ?? null) ? $body['externalAccountIdentifiers'] : []),
            quantity: 1,
            familyShared: false,
            acknowledged: ($body['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            revokedAt: null,
            signedAt: $fetchedAt,
            raw: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body  purchases.productsv2.getproductpurchasev2 response.
     */
    public function product(string $token, array $body, CarbonImmutable $fetchedAt): Purchase
    {
        $items = self::list($body['productLineItem'] ?? null);
        $first = $items[0] ?? [];
        $productId = self::productId($first);
        $state = is_array($body['purchaseStateContext'] ?? null) ? ($body['purchaseStateContext']['purchaseState'] ?? null) : null;
        $quantity = is_array($first['productOfferDetails'] ?? null) ? ($first['productOfferDetails']['quantity'] ?? null) : null;

        return new Purchase(
            platform: Platform::Google,
            key: $token,
            productId: $productId,
            productIds: array_map(self::productId(...), $items),
            type: $this->config->isConsumable($productId) ? ProductType::Consumable : ProductType::NonConsumable,
            status: self::PRODUCT_STATES[is_string($state) ? $state : ''] ?? PurchaseStatus::Canceled,
            environment: array_key_exists('testPurchaseContext', $body) ? Environment::Sandbox : Environment::Production,
            transactionId: self::string($body, 'orderId') ?? '',
            originalTransactionId: null,
            purchaseToken: $token,
            linkedPurchaseToken: null,
            purchasedAt: Time::parse($body['purchaseCompletionTime'] ?? null) ?? $fetchedAt,
            expiresAt: null,
            gracePeriodExpiresAt: null,
            willRenew: null,
            autoRenewProductId: null,
            accountToken: self::accountToken($body),
            quantity: is_numeric($quantity) ? (int) $quantity : 1,
            familyShared: false,
            acknowledged: ($body['acknowledgementState'] ?? null) === 'ACKNOWLEDGEMENT_STATE_ACKNOWLEDGED',
            revokedAt: null,
            signedAt: $fetchedAt,
            raw: $body,
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        if (! is_array($value)) {
            throw new StoreApiException('The Play Developer API returned a purchase without line items.', Platform::Google, 200);
        }

        $items = array_values(array_filter($value, is_array(...)));

        if ($items === []) {
            throw new StoreApiException('The Play Developer API returned a purchase without line items.', Platform::Google, 200);
        }

        return $items;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private static function productId(array $item): string
    {
        $id = $item['productId'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new StoreApiException('The Play Developer API returned a line item without productId.', Platform::Google, 200);
        }

        return $id;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function accountToken(array $data): ?string
    {
        return self::string($data, 'obfuscatedExternalAccountId');
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
