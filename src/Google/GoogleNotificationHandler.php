<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Crypto\Base64Url;
use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseRecord;
use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Ownership\StateSynchronizer;
use BijayX\IapGuard\Support\NotificationGuard;
use BijayX\IapGuard\Support\Time;
use Closure;
use JsonException;

/**
 * Play real-time developer notifications delivered by Pub/Sub push (SPEC §8.2).
 *
 * Notification content is never trusted beyond the purchase token: the current state is always
 * fetched from the Play Developer API.
 */
final readonly class GoogleNotificationHandler
{
    public const SUBSCRIPTION_TYPES = [
        1 => 'RECOVERED',
        2 => 'RENEWED',
        3 => 'CANCELED',
        4 => 'PURCHASED',
        5 => 'ON_HOLD',
        6 => 'IN_GRACE_PERIOD',
        7 => 'RESTARTED',
        8 => 'PRICE_CHANGE_CONFIRMED',
        9 => 'DEFERRED',
        10 => 'PAUSED',
        11 => 'PAUSE_SCHEDULE_CHANGED',
        12 => 'REVOKED',
        13 => 'EXPIRED',
        17 => 'ITEMS_CHANGED',
        18 => 'CANCELLATION_SCHEDULED',
        19 => 'PRICE_CHANGE_UPDATED',
        20 => 'PENDING_PURCHASE_CANCELED',
        22 => 'PRICE_STEP_UP_CONSENT_UPDATED',
    ];

    private const SUBSCRIPTION_EVENTS = [
        1 => NotificationEvent::Recovered,
        2 => NotificationEvent::Renewed,
        3 => NotificationEvent::AutoRenewDisabled,
        4 => NotificationEvent::Purchased,
        5 => NotificationEvent::RenewalFailed,
        6 => NotificationEvent::GracePeriodStarted,
        7 => NotificationEvent::AutoRenewEnabled,
        8 => NotificationEvent::PriceChange,
        10 => NotificationEvent::Paused,
        12 => NotificationEvent::Revoked,
        13 => NotificationEvent::Expired,
        19 => NotificationEvent::PriceChange,
        20 => NotificationEvent::Canceled,
        22 => NotificationEvent::PriceChange,
    ];

    /**
     * @param  Closure(): GooglePurchaseVerifier  $verifier  Resolved lazily (needs the service account).
     * @param  Closure(): PurchaseAcknowledger  $acknowledger
     */
    public function __construct(
        private GoogleConfig $config,
        private PubSubVerifier $authenticator,
        private PurchaseStore $store,
        private StateSynchronizer $sync,
        private NotificationGuard $guard,
        private Closure $verifier,
        private Closure $acknowledger,
    ) {}

    /**
     * @param  string|array<array-key, mixed>  $body  Raw request body or its decoded JSON.
     * @param  string|null  $authorization  The Authorization header (OIDC mode).
     * @param  string|null  $verificationToken  The ?token= query value (shared-secret mode).
     *
     * @throws IapException
     */
    public function handle(string|array $body, ?string $authorization = null, ?string $verificationToken = null): NotificationResult
    {
        $this->authenticator->authenticate($authorization, $verificationToken);

        [$id, $notification] = self::parse($body);

        if (($notification['packageName'] ?? null) !== $this->config->packageName) {
            throw VerificationException::wrongApp(Platform::Google, 'The notification is for a different package.');
        }

        $subscription = is_array($notification['subscriptionNotification'] ?? null) ? $notification['subscriptionNotification'] : null;
        $oneTime = is_array($notification['oneTimeProductNotification'] ?? null) ? $notification['oneTimeProductNotification'] : null;
        $voided = is_array($notification['voidedPurchaseNotification'] ?? null) ? $notification['voidedPurchaseNotification'] : null;

        [$event, $rawType] = match (true) {
            $subscription !== null => self::subscriptionEvent($subscription['notificationType'] ?? null),
            $oneTime !== null => self::oneTimeEvent($oneTime['notificationType'] ?? null),
            $voided !== null => [NotificationEvent::Refunded, 'VOIDED_PURCHASE'],
            array_key_exists('testNotification', $notification) => [NotificationEvent::Test, 'TEST'],
            default => [NotificationEvent::Other, 'UNKNOWN'],
        };

        $result = static fn (NotificationEvent $event, ?PurchaseRecord $record = null, ?Purchase $purchase = null, bool $stale = false, bool $duplicate = false): NotificationResult => new NotificationResult(
            Platform::Google,
            $id,
            $event,
            $rawType,
            duplicate: $duplicate,
            stale: $stale,
            purchase: $purchase ?? $record?->purchase,
            userId: $record?->userId,
            raw: $notification,
        );

        return $this->guard->run(
            Platform::Google,
            $id,
            static fn (): NotificationResult => $result($event, duplicate: true),
            function () use ($subscription, $oneTime, $voided, $event, $result): NotificationResult {
                if ($subscription !== null || ($oneTime !== null && in_array($oneTime['notificationType'] ?? null, [1, 2], true))) {
                    $isSubscription = $subscription !== null;
                    $source = $subscription ?? $oneTime;
                    $token = self::token($source);

                    try {
                        $purchase = ($this->verifier)()->fetch($isSubscription, $token);
                    } catch (PurchaseNotFoundException) {
                        return $result($event); // Nothing to retry: the token is unknown or long expired.
                    }

                    if ($isSubscription && ($subscription['notificationType'] ?? null) === 4
                        && $purchase->linkedPurchaseToken !== null
                        && $this->store->findByToken(Platform::Google, $purchase->linkedPurchaseToken) !== null) {
                        $event = NotificationEvent::PlanChanged;
                    }

                    if ($isSubscription && ($subscription['notificationType'] ?? null) === 12) {
                        $purchase = $purchase->withRevoked($purchase->signedAt);
                    }

                    ['record' => $record, 'stale' => $stale] = $this->sync->upsert($purchase);

                    return $result($event, $record, ($this->acknowledger)()->acknowledgeIfNeeded($record), $stale);
                }

                if ($voided !== null) {
                    return $result($event, $this->void(self::token($voided), ($voided['refundType'] ?? null) === 2));
                }

                return $result($event);
            },
        );
    }

    /**
     * Mark the stored purchase revoked (full refund) or leave it as is (partial quantity refund).
     *
     * @throws IapException
     */
    private function void(string $token, bool $partial): ?PurchaseRecord
    {
        for ($attempt = 0; $attempt < StateSynchronizer::MAX_ATTEMPTS; $attempt++) {
            $record = $this->store->findByToken(Platform::Google, $token);

            if ($record === null || $partial) {
                return $record;
            }

            $now = Time::now();
            $next = $record->next($now, purchase: $record->purchase->withRevoked($now, $now), lastEventAt: Time::max($record->lastEventAt, $now));

            if ($this->store->replace($next, $record->version)) {
                return $next;
            }
        }

        throw IapException::concurrentModification(Platform::Google);
    }

    /**
     * @param  string|array<array-key, mixed>  $body
     * @return array{string, array<string, mixed>}
     *
     * @throws VerificationException
     */
    private static function parse(string|array $body): array
    {
        try {
            $body = is_string($body) ? json_decode($body, true, 32, JSON_THROW_ON_ERROR) : $body;
        } catch (JsonException) {
            throw self::invalid('The request body is not JSON.');
        }

        $message = is_array($body) && is_array($body['message'] ?? null) ? $body['message'] : null;

        if ($message === null) {
            throw self::invalid('The push body has no message.');
        }

        $id = $message['messageId'] ?? $message['message_id'] ?? null;
        $id = is_int($id) ? (string) $id : $id;

        if (! is_string($id) || $id === '') {
            throw self::invalid('The push message has no messageId.');
        }

        try {
            $json = Base64Url::decodeStandard(is_string($message['data'] ?? null) ? $message['data'] : '');
            $notification = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (CryptoException|JsonException) {
            throw self::invalid('The push message data is not base64-encoded JSON.');
        }

        if (! is_array($notification) || array_is_list($notification)) {
            throw self::invalid('The push message data is not a developer notification.');
        }

        /** @var array<string, mixed> $notification */
        return [$id, $notification];
    }

    /**
     * @return array{NotificationEvent, string}
     */
    private static function subscriptionEvent(mixed $type): array
    {
        $type = is_numeric($type) ? (int) $type : -1;
        $name = self::SUBSCRIPTION_TYPES[$type] ?? (string) $type;

        return [self::SUBSCRIPTION_EVENTS[$type] ?? NotificationEvent::Other, "SUBSCRIPTION_{$name}"];
    }

    /**
     * @return array{NotificationEvent, string}
     */
    private static function oneTimeEvent(mixed $type): array
    {
        return match ($type) {
            1 => [NotificationEvent::Purchased, 'ONE_TIME_PRODUCT_PURCHASED'],
            2 => [NotificationEvent::Canceled, 'ONE_TIME_PRODUCT_CANCELED'],
            default => [NotificationEvent::Other, 'UNKNOWN'],
        };
    }

    /**
     * @param  array<array-key, mixed>  $notification
     */
    private static function token(array $notification): string
    {
        $token = $notification['purchaseToken'] ?? null;

        if (! is_string($token) || $token === '') {
            throw self::invalid('The notification has no purchaseToken.');
        }

        return $token;
    }

    private static function invalid(string $reason): VerificationException
    {
        return VerificationException::invalidNotification(Platform::Google, $reason);
    }
}
