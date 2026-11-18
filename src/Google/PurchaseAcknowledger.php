<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Support\Time;
use Psr\Log\LoggerInterface;

/**
 * Acknowledges / consumes Google purchases after they are stored and owned (SPEC §7.7).
 *
 * Play refunds purchases that are not acknowledged within three days. A failure here never fails
 * the claim or webhook: the state is already stored, the error is logged, and the next claim or
 * notification retries (acknowledge and consume are idempotent).
 */
final readonly class PurchaseAcknowledger
{
    public function __construct(
        private PlayDeveloperApi $api,
        private PurchaseStore $store,
        private LoggerInterface $logger,
    ) {}

    /**
     * Returns the purchase as it should be reported (acknowledged = true on success).
     */
    public function acknowledgeIfNeeded(PurchaseRecord $record): Purchase
    {
        $purchase = $record->purchase;
        $token = $purchase->purchaseToken;

        if ($purchase->platform !== Platform::Google || $token === null || $record->userId === null || ! $purchase->status->grantsAccess()) {
            return $purchase;
        }

        try {
            if ($purchase->type === ProductType::Consumable) {
                if (self::alreadyConsumed($purchase)) {
                    return $purchase;
                }

                $this->api->consumeProduct($purchase->productId, $token);
            } elseif ($purchase->acknowledged === false) {
                $purchase->type === ProductType::Subscription
                    ? $this->api->acknowledgeSubscription($purchase->productId, $token)
                    : $this->api->acknowledgeProduct($purchase->productId, $token);
            } else {
                return $purchase;
            }
        } catch (IapException $e) {
            // No tokens or user ids in the log: product and error code are enough to investigate.
            $this->logger->warning('iap-guard: Google acknowledge/consume failed; it will be retried on the next claim or notification.', [
                'iap_guard_code' => $e->errorCode(),
                'product_id' => $purchase->productId,
                'type' => $purchase->type->value,
            ]);

            return $purchase;
        }

        $acknowledged = $purchase->withAcknowledged(true);

        // Best effort: a lost race here is harmless, the next fetch reports the real state.
        $this->store->replace($record->next(Time::now(), purchase: $acknowledged), $record->version);

        return $acknowledged;
    }

    private static function alreadyConsumed(Purchase $purchase): bool
    {
        $items = $purchase->raw['productLineItem'] ?? null;
        $first = is_array($items) && is_array($items[0] ?? null) ? $items[0] : [];
        $offer = is_array($first['productOfferDetails'] ?? null) ? $first['productOfferDetails'] : [];

        return ($offer['consumptionState'] ?? null) === 'CONSUMPTION_STATE_CONSUMED';
    }
}
