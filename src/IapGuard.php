<?php

declare(strict_types=1);

namespace BijayX\IapGuard;

use BijayX\IapGuard\Apple\AppleConfig;
use BijayX\IapGuard\Apple\AppleNormalizer;
use BijayX\IapGuard\Apple\AppleNotificationHandler;
use BijayX\IapGuard\Apple\ApplePurchaseVerifier;
use BijayX\IapGuard\Apple\AppStoreServerApi;
use BijayX\IapGuard\Apple\SignedDataVerifier;
use BijayX\IapGuard\Contracts\PurchaseStore;
use BijayX\IapGuard\Data\ClaimResult;
use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\ConflictPolicy;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\ValidationException;
use BijayX\IapGuard\Google\GoogleConfig;
use BijayX\IapGuard\Google\GoogleNormalizer;
use BijayX\IapGuard\Google\GoogleNotificationHandler;
use BijayX\IapGuard\Google\GooglePurchaseVerifier;
use BijayX\IapGuard\Google\PlayDeveloperApi;
use BijayX\IapGuard\Google\PubSubVerifier;
use BijayX\IapGuard\Google\PurchaseAcknowledger;
use BijayX\IapGuard\Google\ServiceAccountAuth;
use BijayX\IapGuard\Http\StoreHttpClient;
use BijayX\IapGuard\Ownership\AccountTokens;
use BijayX\IapGuard\Ownership\ClaimProcessor;
use BijayX\IapGuard\Ownership\StateSynchronizer;
use BijayX\IapGuard\Support\ConfigReader;
use BijayX\IapGuard\Support\NotificationGuard;
use BijayX\IapGuard\Support\ProductAllowlist;
use BijayX\IapGuard\Support\Time;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

/**
 * Server-side verification and ownership protection for App Store and Google Play purchases.
 *
 * - verify():  proves a purchase is genuine and normalizes it (stateless).
 * - claim():   verify + ownership rules + persistence + Google acknowledge/consume.
 * - handleAppleNotification() / handleGoogleNotification(): webhooks.
 * - entitlements(): what a user may access right now, across platforms and devices.
 *
 * Components are built lazily, so an app that only sells on one store only configures that store.
 */
class IapGuard
{
    public const VERSION = '0.1.0';

    private ?AppleConfig $appleConfig = null;

    private ?SignedDataVerifier $signedDataVerifier = null;

    private ?ApplePurchaseVerifier $appleVerifier = null;

    private ?GoogleConfig $googleConfig = null;

    private ?GooglePurchaseVerifier $googleVerifier = null;

    private ?PlayDeveloperApi $playApi = null;

    private ?AccountTokens $accountTokens = null;

    /**
     * The dispatcher and logger are resolved on use, so Event::fake() / Log::spy() work even
     * after IapGuard has been resolved.
     *
     * @param  array<array-key, mixed>  $config  config('iap-guard')
     * @param  Dispatcher|Closure(): Dispatcher  $events
     * @param  LoggerInterface|Closure(): LoggerInterface  $logger
     */
    public function __construct(
        private readonly array $config,
        private readonly PurchaseStore $store,
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly Dispatcher|Closure $events,
        private readonly LoggerInterface|Closure $logger,
    ) {}

    /**
     * Verify a purchase proof with Apple or Google and return the normalized purchase.
     *
     * @param  PurchaseProof|array<array-key, mixed>  $proof  e.g. ['platform' => 'apple', 'signedTransaction' => $jws]
     *
     * @throws IapException
     */
    public function verify(PurchaseProof|array $proof): Purchase
    {
        $proof = is_array($proof) ? PurchaseProof::fromArray($proof) : $proof;

        return $proof->platform === Platform::Apple
            ? $this->appleVerifier()->verify($proof)
            : $this->googleVerifier()->verify($proof);
    }

    /**
     * Verify the proof and record it against your authenticated user (SPEC §7).
     *
     * @param  PurchaseProof|array<array-key, mixed>  $proof
     *
     * @throws IapException
     */
    public function claim(string|int $userId, PurchaseProof|array $proof): ClaimResult
    {
        $userId = self::userId($userId);

        return $this->claimProcessor()->claim($userId, $this->verify($proof));
    }

    /**
     * Handle an App Store Server Notification V2 request body.
     *
     * @param  string|array<array-key, mixed>  $body
     *
     * @throws IapException
     */
    public function handleAppleNotification(string|array $body): NotificationResult
    {
        return (new AppleNotificationHandler(
            $this->signedDataVerifier(),
            new AppleNormalizer,
            new StateSynchronizer($this->store),
            new NotificationGuard($this->store, $this->events()),
        ))->handle($body);
    }

    /**
     * Handle a Play real-time developer notification (Pub/Sub push request).
     *
     * @param  string|array<array-key, mixed>  $body
     * @param  string|null  $authorization  Authorization header (OIDC mode).
     * @param  string|null  $verificationToken  ?token= query value (shared-secret mode).
     *
     * @throws IapException
     */
    public function handleGoogleNotification(string|array $body, ?string $authorization = null, ?string $verificationToken = null): NotificationResult
    {
        $config = $this->googleConfig();

        return (new GoogleNotificationHandler(
            $config,
            new PubSubVerifier($config, $this->httpClient(Platform::Google), $this->cache, $this->cachePrefix()),
            $this->store,
            new StateSynchronizer($this->store),
            new NotificationGuard($this->store, $this->events()),
            fn (): GooglePurchaseVerifier => $this->googleVerifier(),
            fn (): PurchaseAcknowledger => $this->acknowledger(),
        ))->handle($body, $authorization, $verificationToken);
    }

    /**
     * The user's purchases that grant access at $now, newest expiry first (non-expiring first).
     *
     * @return list<Purchase>
     */
    public function entitlements(string|int $userId, ?DateTimeInterface $now = null): array
    {
        $now ??= Time::now();
        $purchases = [];

        foreach ($this->store->findByUser(self::userId($userId)) as $record) {
            if ($record->purchase->isEntitled($now)) {
                $purchases[] = $record->purchase;
            }
        }

        usort($purchases, static function (Purchase $a, Purchase $b): int {
            if ($a->expiresAt === null || $b->expiresAt === null) {
                return ($a->expiresAt === null ? 0 : 1) <=> ($b->expiresAt === null ? 0 : 1);
            }

            return Time::toMs($b->expiresAt) <=> Time::toMs($a->expiresAt);
        });

        return $purchases;
    }

    public function isEntitled(Purchase $purchase, ?DateTimeInterface $now = null): bool
    {
        return $purchase->isEntitled($now);
    }

    /**
     * Release every purchase owned by the user (account deletion), so the store account can be
     * restored into a new app account. Returns the number of records released.
     *
     * @throws IapException
     */
    public function releaseUser(string|int $userId): int
    {
        return $this->claimProcessor()->releaseUser(self::userId($userId));
    }

    /**
     * Deterministic UUID to pass to StoreKit 2 (.appAccountToken) or Play Billing
     * (setObfuscatedAccountId) so the purchase is bound to this user.
     *
     * @throws ConfigurationException|ValidationException
     */
    public function accountTokenFor(string|int $userId): string
    {
        return $this->accountTokens()->for(self::userId($userId));
    }

    public function store(): PurchaseStore
    {
        return $this->store;
    }

    /**
     * @throws ValidationException
     */
    public static function userId(string|int $userId): string
    {
        $userId = (string) $userId;

        if ($userId === '' || strlen($userId) > 191) {
            throw ValidationException::forField('userId', 'must be a non-empty string of at most 191 characters.');
        }

        return $userId;
    }

    private function claimProcessor(): ClaimProcessor
    {
        $policy = $this->reader()->string('ownership.conflict_policy') ?? ConflictPolicy::Reject->value;

        return new ClaimProcessor(
            $this->store,
            new StateSynchronizer($this->store),
            $this->accountTokens(),
            ConflictPolicy::tryFrom(strtolower($policy)) ?? throw ConfigurationException::invalid('ownership.conflict_policy', '"reject" or "transfer"'),
            $this->events(),
            fn (): PurchaseAcknowledger => $this->acknowledger(),
        );
    }

    private function accountTokens(): AccountTokens
    {
        return $this->accountTokens ??= new AccountTokens($this->reader()->string('ownership.account_token_secret'));
    }

    private function appleConfig(): AppleConfig
    {
        $apple = $this->config['apple'] ?? [];

        return $this->appleConfig ??= AppleConfig::fromArray(is_array($apple) ? $apple : []);
    }

    private function signedDataVerifier(): SignedDataVerifier
    {
        return $this->signedDataVerifier ??= new SignedDataVerifier($this->appleConfig());
    }

    private function appleVerifier(): ApplePurchaseVerifier
    {
        if ($this->appleVerifier !== null) {
            return $this->appleVerifier;
        }

        $config = $this->appleConfig();

        return $this->appleVerifier = new ApplePurchaseVerifier(
            $config,
            $this->signedDataVerifier(),
            new AppleNormalizer,
            $this->allowlist(),
            $config->hasApi() ? new AppStoreServerApi($config, $this->httpClient(Platform::Apple)) : null,
        );
    }

    private function googleConfig(): GoogleConfig
    {
        $google = $this->config['google'] ?? [];

        return $this->googleConfig ??= GoogleConfig::fromArray(is_array($google) ? $google : []);
    }

    private function playApi(): PlayDeveloperApi
    {
        if ($this->playApi !== null) {
            return $this->playApi;
        }

        $config = $this->googleConfig();
        $http = $this->httpClient(Platform::Google);

        return $this->playApi = new PlayDeveloperApi(
            $config,
            new ServiceAccountAuth($config, $http, $this->cache, $this->cachePrefix()),
            $http,
        );
    }

    private function googleVerifier(): GooglePurchaseVerifier
    {
        return $this->googleVerifier ??= new GooglePurchaseVerifier(
            $this->googleConfig(),
            $this->playApi(),
            new GoogleNormalizer($this->googleConfig()),
            $this->allowlist(),
        );
    }

    private function acknowledger(): PurchaseAcknowledger
    {
        return new PurchaseAcknowledger($this->playApi(), $this->store, $this->logger());
    }

    private function events(): Dispatcher
    {
        return $this->events instanceof Closure ? ($this->events)() : $this->events;
    }

    private function logger(): LoggerInterface
    {
        return $this->logger instanceof Closure ? ($this->logger)() : $this->logger;
    }

    private function allowlist(): ProductAllowlist
    {
        return new ProductAllowlist($this->reader()->stringList('products'));
    }

    private function httpClient(Platform $platform): StoreHttpClient
    {
        $reader = $this->reader();

        return new StoreHttpClient(
            $this->http,
            $platform,
            timeoutMs: $reader->int('http.timeout_ms', 15000, 1),
            retries: $reader->int('http.retries', 2, 0),
            retryDelayMs: $reader->int('http.retry_delay_ms', 200, 0),
        );
    }

    private function cachePrefix(): string
    {
        return $this->reader()->string('cache.prefix') ?? 'iap-guard';
    }

    private function reader(): ConfigReader
    {
        return new ConfigReader($this->config, '');
    }
}
