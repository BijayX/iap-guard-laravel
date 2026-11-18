<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Ownership\StateSynchronizer;
use BijayX\IapGuard\Support\NotificationGuard;
use BijayX\IapGuard\Support\Time;
use JsonException;

/**
 * App Store Server Notifications V2 (SPEC §8.1).
 */
final readonly class AppleNotificationHandler
{
    public function __construct(
        private SignedDataVerifier $verifier,
        private AppleNormalizer $normalizer,
        private StateSynchronizer $sync,
        private NotificationGuard $guard,
    ) {}

    /**
     * @param  string|array<array-key, mixed>  $body  Raw request body or its decoded JSON.
     *
     * @throws IapException
     */
    public function handle(string|array $body): NotificationResult
    {
        $signedPayload = self::signedPayload($body);
        $payload = $this->verifier->verifyNotification($signedPayload);

        if (($payload['version'] ?? null) !== '2.0') {
            throw VerificationException::invalidNotification(Platform::Apple, 'Only App Store Server Notifications V2 are supported.');
        }

        $id = $payload['notificationUUID'] ?? null;
        $type = $payload['notificationType'] ?? null;
        $subtype = is_string($payload['subtype'] ?? null) && $payload['subtype'] !== '' ? $payload['subtype'] : null;

        if (! is_string($id) || $id === '' || ! is_string($type) || $type === '') {
            throw VerificationException::invalidNotification(Platform::Apple, 'The notification has no notificationUUID or notificationType.');
        }

        $event = self::event($type, $subtype);
        $rawType = $subtype === null ? $type : "{$type}/{$subtype}";

        return $this->guard->run(
            Platform::Apple,
            $id,
            static fn (): NotificationResult => new NotificationResult(Platform::Apple, $id, $event, $rawType, duplicate: true, raw: $payload),
            function () use ($payload, $id, $event, $rawType): NotificationResult {
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                if (! is_string($data['signedTransactionInfo'] ?? null)) {
                    // TEST, summary and external-purchase-token notifications carry no transaction.
                    return new NotificationResult(Platform::Apple, $id, $event, $rawType, raw: $payload);
                }

                $transaction = $this->verifier->verifyTransaction($data['signedTransactionInfo']);
                $renewal = is_string($data['signedRenewalInfo'] ?? null) ? $this->verifier->verifyRenewalInfo($data['signedRenewalInfo']) : null;
                $signedDate = $payload['signedDate'] ?? null;

                $purchase = $this->normalizer->normalize(
                    $transaction,
                    $renewal,
                    is_int($data['status'] ?? null) ? $data['status'] : null,
                    is_int($signedDate) ? Time::fromMs($signedDate) : null,
                );

                ['record' => $record, 'stale' => $stale] = $this->sync->upsert($purchase);

                return new NotificationResult(
                    Platform::Apple,
                    $id,
                    $event,
                    $rawType,
                    stale: $stale,
                    purchase: $record->purchase,
                    userId: $record->userId,
                    raw: $payload,
                );
            },
        );
    }

    public static function event(string $type, ?string $subtype): NotificationEvent
    {
        return match ($type) {
            'SUBSCRIBED', 'ONE_TIME_CHARGE' => NotificationEvent::Purchased,
            'DID_RENEW' => $subtype === 'BILLING_RECOVERY' ? NotificationEvent::Recovered : NotificationEvent::Renewed,
            'DID_FAIL_TO_RENEW' => $subtype === 'GRACE_PERIOD' ? NotificationEvent::GracePeriodStarted : NotificationEvent::RenewalFailed,
            'DID_CHANGE_RENEWAL_STATUS' => match ($subtype) {
                'AUTO_RENEW_ENABLED' => NotificationEvent::AutoRenewEnabled,
                'AUTO_RENEW_DISABLED' => NotificationEvent::AutoRenewDisabled,
                default => NotificationEvent::Other,
            },
            'DID_CHANGE_RENEWAL_PREF' => NotificationEvent::PlanChanged,
            'EXPIRED', 'GRACE_PERIOD_EXPIRED' => NotificationEvent::Expired,
            'REFUND' => NotificationEvent::Refunded,
            'REVOKE' => NotificationEvent::Revoked,
            'REFUND_REVERSED' => NotificationEvent::RefundReversed,
            'PRICE_INCREASE', 'PRICE_CHANGE' => NotificationEvent::PriceChange,
            'CONSUMPTION_REQUEST' => NotificationEvent::ConsumptionRequest,
            'TEST' => NotificationEvent::Test,
            default => NotificationEvent::Other,
        };
    }

    /**
     * @param  string|array<array-key, mixed>  $body
     */
    private static function signedPayload(string|array $body): string
    {
        if (is_string($body)) {
            try {
                $body = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $body = null;
            }
        }

        $signed = is_array($body) ? ($body['signedPayload'] ?? null) : null;

        if (! is_string($signed) || $signed === '') {
            throw VerificationException::invalidNotification(Platform::Apple, 'The request body must be JSON with a signedPayload.');
        }

        return $signed;
    }
}
