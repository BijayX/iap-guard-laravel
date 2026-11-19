# IAP Guard for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/bijayx/iap-guard-laravel.svg)](https://packagist.org/packages/bijayx/iap-guard-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/bijayx/iap-guard-laravel.svg)](https://packagist.org/packages/bijayx/iap-guard-laravel)
[![CI](https://github.com/BijayX/iap-guard-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/BijayX/iap-guard-laravel/actions/workflows/ci.yml)
[![PHP Version](https://img.shields.io/packagist/dependency-v/bijayx/iap-guard-laravel/php.svg)](https://packagist.org/packages/bijayx/iap-guard-laravel)
[![License: MIT](https://img.shields.io/packagist/l/bijayx/iap-guard-laravel.svg)](LICENSE)

Server-side verification **and ownership protection** for Apple App Store and Google Play
in-app purchases.

Verifying a receipt only proves that *someone* paid. The harder part is deciding *who* gets
access. Users restore purchases on new devices, share an Apple ID across two app accounts,
upgrade plans, get refunds, and sometimes replay old proofs. IAP Guard gives you one API for both
stores, and one rule: **an entitlement belongs to your user, not to a device or a store account.**

- **One API, two stores.** `verify()`, `claim()` and webhooks return the same immutable `Purchase`
  for StoreKit 2 and Play Billing: the same statuses, the same dates and the same field names.
- **Ownership protection.** Each purchase has one owner. Another app account can't restore it
  (or it is transferred, if you choose). Upgrade chains stay together, and a consumable is never
  granted twice.
- **Real verification.** Apple JWS signatures are checked against the bundled Apple Root CA - G3
  with `ext-openssl` only. Google purchases are always fetched from the Play Developer API, and
  never trusted from the client.
- **Webhooks included.** App Store Server Notifications V2 and Play real-time developer
  notifications (Pub/Sub push with OIDC) are handled with dedupe and stale-state protection.
- **Laravel native.** Migrations, an Eloquent model, a `HasPurchases` trait for your `User`, events,
  and the `Http` facade, so `Http::fake()` and `Event::fake()` work in your tests.
- **No extra dependencies.** Only `illuminate/*` and `ext-openssl`.

It implements the same contract as the Node.js package `@bijayx/iap-guard` (see [`SPEC.md`](SPEC.md)):
statuses, error codes and normalized output are identical.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [Client side](#client-side)
- [Claiming purchases](#claiming-purchases)
- [Checking access](#checking-access)
- [Webhooks](#webhooks)
- [Events](#events)
- [Edge cases handled](#edge-cases-handled)
- [Conflict policies](#conflict-policies)
- [API reference](#api-reference)
- [Error handling](#error-handling)
- [Testing your application](#testing-your-application)
- [Sandbox testing](#sandbox-testing)
- [Security checklist](#security-checklist)
- [Limitations](#limitations)

## Requirements

- PHP 8.3 or newer, with `ext-openssl`
- Laravel 12 or 13
- A database supported by Laravel (the store relies on unique indexes and `UPDATE … WHERE version = ?`)

## Installation

```bash
composer require bijayx/iap-guard-laravel
php artisan migrate
```

The service provider and the `IapGuard` facade are registered automatically. The package
migrations (`iap_purchases`, `iap_purchase_tokens`, `iap_notifications`) are loaded automatically.

Publish the config file to change defaults:

```bash
php artisan vendor:publish --tag=iap-guard-config
```

To customise the migrations, publish them and turn off the automatic loading:

```bash
php artisan vendor:publish --tag=iap-guard-migrations
```

```dotenv
IAP_GUARD_RUN_MIGRATIONS=false
```

## Configuration

Configure only the store(s) you sell on. Configuration is validated when that store is first used.

```dotenv
# Apple
IAP_GUARD_APPLE_BUNDLE_ID=com.example.app
IAP_GUARD_APPLE_APP_APPLE_ID=1234567890          # required for App Store Server Notifications
IAP_GUARD_APPLE_ENVIRONMENTS=production,sandbox  # keep sandbox: App Review uses it

# Optional: App Store Server API (fresh subscription state, verify by transaction id)
IAP_GUARD_APPLE_ISSUER_ID=57246542-96fe-1a63-e053-0824d011072a
IAP_GUARD_APPLE_KEY_ID=ABC123DEFG
IAP_GUARD_APPLE_PRIVATE_KEY=/path/to/SubscriptionKey_ABC123DEFG.p8   # or the .p8 contents

# Google Play
IAP_GUARD_GOOGLE_PACKAGE_NAME=com.example.app
IAP_GUARD_GOOGLE_SERVICE_ACCOUNT=/path/to/service-account.json      # or the JSON contents
IAP_GUARD_GOOGLE_CONSUMABLES=coins_100,coins_500
IAP_GUARD_GOOGLE_PUBSUB_AUDIENCE=https://api.example.com/iap/webhooks/google
IAP_GUARD_GOOGLE_PUBSUB_SERVICE_ACCOUNT=pubsub-push@your-project.iam.gserviceaccount.com

# Ownership
IAP_GUARD_ACCOUNT_TOKEN_SECRET=   # 32+ random characters, e.g. `openssl rand -hex 32`
IAP_GUARD_CONFLICT_POLICY=reject  # reject | transfer
```

| Key | Default | Notes |
|---|---|---|
| `apple.bundle_id` | none | Required for Apple. |
| `apple.app_apple_id` | none | Numeric App Store id. Required to receive notifications while production is allowed. |
| `apple.environments` | `production,sandbox` | Purchases from other environments fail with `ENVIRONMENT_NOT_ALLOWED`. |
| `apple.allow_family_sharing` | `true` | `false` rejects Family Sharing purchases with `FAMILY_SHARING_NOT_ALLOWED`. |
| `apple.root_certificates` | bundled Apple Root CA - G3 | Trust anchors (PEM/DER contents or paths). Only override in tests. |
| `apple.api.issuer_id`, `key_id`, `private_key` | none | Enable the App Store Server API. Set all three or none. |
| `google.package_name` | none | Required for Google. |
| `google.service_account` | none | JSON string, array or file path. Needed for every Play API call. |
| `google.consumable_product_ids` | `[]` | Play doesn't say which one-time products are consumable. These are consumed after a claim. |
| `google.allow_test_purchases` | `true` | `false` rejects license-tester purchases with `ENVIRONMENT_NOT_ALLOWED`. |
| `google.pubsub.audience` | none | Verify the Pub/Sub push OIDC token (recommended). |
| `google.pubsub.service_account_email` | none | When set, the token's `email` must match and be verified. |
| `google.pubsub.verification_token` | none | Alternative to OIDC: a shared secret sent as `?token=` on the push URL. |
| `ownership.conflict_policy` | `reject` | See [Conflict policies](#conflict-policies). |
| `ownership.account_token_secret` | none | Enables `accountTokenFor()` and account-token checks. At least 32 characters. |
| `products` | `null` | Optional allowlist of product ids for both stores. Anything else fails with `UNKNOWN_PRODUCT`. |
| `http.timeout_ms` | `15000` | Per request. |
| `http.retries` | `2` | Extra attempts on 429, 5xx and connection failures, with a backoff of `retry_delay_ms * 2^n`. Apple's `Retry-After` is honoured when it is 10 s or less. |
| `webhooks.enabled`, `prefix`, `middleware` | `true`, `iap/webhooks`, `[]` | See [Webhooks](#webhooks). |
| `store.driver` | `database` | `database`, `memory`, or the class name of your own `PurchaseStore`. |
| `store.connection` | default | Database connection for the store, the migrations and the model. |
| `cache.store` | default | Holds the Google OAuth token and Google's OIDC keys. Use a store shared by all servers. |

### Store credentials

- **App Store Server API:** App Store Connect → Users and Access → Integrations → In-App Purchase.
  Generate a key and note the Issuer ID and Key ID. The `.p8` file can only be downloaded once.
- **Google Play Developer API:** enable the *Google Play Android Developer API* in a Google Cloud
  project, create a service account with a JSON key, and invite its email in Play Console → Users
  and permissions with access to your app's orders and subscriptions.

## How it works

```
Device ── proof ──▶ your API ── IapGuard::claim($user->id, $proof) ──▶ Apple / Google
                                     │
                                     ├─ verify: signature / API lookup, app, environment, product
                                     ├─ ownership key: originalTransactionId, transactionId, or the
                                     │  first purchase token of a Google subscription chain
                                     ├─ one owner per key (atomic insert + versioned update)
                                     └─ Google acknowledge / consume
App Store / Play ── webhook ──▶ IapGuard ── refresh state, never change the owner, never roll back
```

| Store | Product type | Ownership key |
|---|---|---|
| Apple | auto-renewable, non-consumable, non-renewing | `originalTransactionId` |
| Apple | consumable | `transactionId` |
| Google | subscription | first purchase token of the chain (follows `linkedPurchaseToken`) |
| Google | one-time product | `purchaseToken` |

## Client side

### Bind purchases to your user (recommended)

Give the app a per-user account token from your API, and pass it to the store at purchase time.
The token is a UUID derived from an HMAC of the user id with a server-side secret. It reveals
nothing about the user and can't be forged for another user.

```php
Route::middleware('auth:sanctum')->get('/iap/account-token', fn (Request $request) => [
    'token' => IapGuard::accountTokenFor($request->user()->getKey()),
]);
```

When a purchase carries a token for a different user, `claim()` throws `ACCOUNT_MISMATCH`
under the `reject` policy. Purchases without a token (older app versions) skip this check.

### StoreKit 2 (iOS)

```swift
let accountToken = UUID(uuidString: tokenFromYourAPI)!

let result = try await product.purchase(options: [.appAccountToken(accountToken)])

if case .success(let verification) = result {
    // Send Apple's signed JWS, not the decoded transaction.
    try await api.post("/api/iap/claim", [
        "platform": "apple",
        "signedTransaction": verification.jwsRepresentation,
    ])
    if case .verified(let transaction) = verification {
        await transaction.finish()
    }
}

// Restore / new device: claim every current entitlement.
for await verification in Transaction.currentEntitlements {
    try await api.post("/api/iap/claim", ["platform": "apple", "signedTransaction": verification.jwsRepresentation])
}
```

Call `transaction.finish()` once your server has accepted the claim. StoreKit Testing transactions
in Xcode are not signed by Apple, so they always fail verification. Use a Sandbox account
instead (see [Sandbox testing](#sandbox-testing)).

### Play Billing (Android)

```kotlin
val params = BillingFlowParams.newBuilder()
    .setProductDetailsParamsList(listOf(
        BillingFlowParams.ProductDetailsParams.newBuilder()
            .setProductDetails(productDetails)
            .setOfferToken(offerToken) // subscriptions only
            .build()
    ))
    .setObfuscatedAccountId(tokenFromYourAPI)
    .build()

billingClient.launchBillingFlow(activity, params)

// PurchasesUpdatedListener / queryPurchasesAsync:
api.post("/api/iap/claim", mapOf(
    "platform" to "google",
    "type" to if (isSubscription) "subscription" else "one_time",
    "purchaseToken" to purchase.purchaseToken,
    "productId" to purchase.products.first(),
))
```

Don't acknowledge or consume on the device. The server does it after the claim is stored
(products listed in `consumable_product_ids` are consumed, everything else is acknowledged).

## Claiming purchases

A form request:

```php
use Illuminate\Foundation\Http\FormRequest;

final class ClaimPurchaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'platform' => ['required', 'in:apple,google'],
            'signedTransaction' => ['required_if:platform,apple', 'string', 'max:65536'],
            'type' => ['required_if:platform,google', 'in:subscription,one_time'],
            'purchaseToken' => ['required_if:platform,google', 'string', 'max:4096'],
            'productId' => ['nullable', 'string', 'max:191'],
        ];
    }
}
```

The controller:

```php
use BijayX\IapGuard\Enums\ProductType;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\OwnershipException;
use BijayX\IapGuard\Facades\IapGuard;

final class ClaimPurchaseController
{
    public function __invoke(ClaimPurchaseRequest $request): JsonResponse
    {
        $user = $request->user();

        try {
            $result = IapGuard::claim($user->getKey(), $request->validated());
        } catch (OwnershipException $e) {
            // ALREADY_OWNED / ACCOUNT_MISMATCH: linked to another account.
            return response()->json(['error' => $e->errorCode()], 409);
        } catch (IapException $e) {
            return response()->json(['error' => $e->errorCode()], 422);
        }

        // Grant consumables only the first time. Replays return isNew = false.
        if ($result->isNew && $result->isEntitled && $result->purchase->type === ProductType::Consumable) {
            $user->increment('coins', 100 * $result->purchase->quantity);
        }

        return response()->json([
            'entitled' => $result->isEntitled,
            'product' => $result->purchase->productId,
            'expires_at' => $result->purchase->expiresAt?->toIso8601String(),
            // Already subscribed on the other platform? Warn about double billing.
            'also_active' => array_map(fn ($p) => $p->platform->value, $result->otherActive),
        ]);
    }
}
```

`claim()` returns a `ClaimResult`:

| Property | Meaning |
|---|---|
| `purchase` | The stored state, which can be newer than the proof the device sent. |
| `isEntitled` | Whether the purchase grants access right now. |
| `isNew` | `true` only the first time this transaction or token is claimed. Grant consumables only when `true`. |
| `ownership` | `created`, `existing` or `transferred`. |
| `previousUserId` | Set when ownership was `transferred`. Revoke that user's access. |
| `otherActive` | This user's other entitled purchases, for example on the other platform. |

`verify()` does the same verification without touching the store, for example to check a
proof before showing a paywall. It accepts the same input, or a `PurchaseProof`:

```php
use BijayX\IapGuard\Data\PurchaseProof;

$purchase = IapGuard::verify(PurchaseProof::appleSignedTransaction($jws));
$purchase = IapGuard::verify(PurchaseProof::appleTransactionId('2000000123456789')); // needs the Server API
$purchase = IapGuard::verify(PurchaseProof::google('subscription', $token, 'premium_monthly'));
```

With the App Store Server API configured, Apple subscriptions are refreshed from Apple on every
`verify()` / `claim()`, so you get the latest renewal, grace-period and auto-renew state.
Without it, the state comes from the signed transaction alone (`willRenew` is `null`).

## Checking access

Add the trait to your user model:

```php
use BijayX\IapGuard\Concerns\HasPurchases;

class User extends Authenticatable
{
    use HasPurchases;
}
```

```php
$user->isEntitledTo('premium_monthly');  // bool, across both stores and all devices
$user->iapEntitlements();                // list<Purchase> that grant access right now
$user->iapPurchases()->get();            // HasMany<IapPurchase>, every purchase they own
```

Or use the service directly:

```php
IapGuard::entitlements($userId);         // sorted by expiry, non-expiring first
IapGuard::isEntitled($purchase);
```

Access is time based. An `active` subscription stops granting access at `expiresAt`, and a
`grace_period` one at `gracePeriodExpiresAt`, even if a webhook is missed.

The `IapPurchase` model has promoted columns for reporting
(`product_id`, `status`, `environment`, `expires_at`, `account_token`, …) and the scopes
`forUser()`, `active()` and `platform()`. Treat it as read-only and write through `IapGuard`.

When a user deletes their account, release their purchases. The store account can then be
restored into a new app account:

```php
IapGuard::releaseUser($user->getKey()); // number of purchases released
```

## Webhooks

Two routes are registered (`iap-guard.webhooks.apple` and `iap-guard.webhooks.google`):

```
POST /iap/webhooks/apple    App Store Server Notifications V2
POST /iap/webhooks/google   Play real-time developer notifications (Pub/Sub push)
```

Change the prefix or add middleware (rate limiting, for example) in `config/iap-guard.php`.
Don't add auth or CSRF middleware, because requests are authenticated by their signatures. Set
`webhooks.enabled` to `false` to register your own routes and call
`IapGuard::handleAppleNotification()` / `handleGoogleNotification()` yourself.

Responses: **200** for processed, duplicate and stale notifications. **400** for forged,
malformed, wrong-app or policy-rejected requests, so the store stops retrying them. **500** for
store API, network, database and configuration errors, so the store retries. If processing fails,
the notification id is forgotten, so the retry is processed again.

Notifications never change who owns a purchase, and never overwrite newer state with older state.
A notification that arrives before the app claims the purchase is stored without an owner.
The first matching claim then takes it.

### App Store Server Notifications V2

In App Store Connect → your app → App Information → App Store Server Notifications, set the
**Production** and **Sandbox** server URLs to `https://api.example.com/iap/webhooks/apple` and
choose **Version 2**. Set `IAP_GUARD_APPLE_APP_APPLE_ID`: notifications are rejected without it
while production is allowed. Use *Request a Test Notification* to check the setup. It arrives as
event `test`.

### Play real-time developer notifications

1. Create a Pub/Sub topic. Grant `google-play-developer-notifications@system.gserviceaccount.com`
   the *Pub/Sub Publisher* role on it.
2. In Play Console → Monetization setup, enter the topic name and choose to receive subscription,
   one-time product and voided-purchase notifications.
3. Create a **push** subscription to `https://api.example.com/iap/webhooks/google`, enable
   **authentication**, and pick a service account. Set `IAP_GUARD_GOOGLE_PUBSUB_AUDIENCE` to the
   subscription's audience (by default, the push endpoint URL). Set
   `IAP_GUARD_GOOGLE_PUBSUB_SERVICE_ACCOUNT` to that service account's email.

Each push carries a Google-signed OIDC token. IAP Guard checks it is RS256, signed by a key from
Google's JWKS, with the right issuer, audience, expiry and email. The JWKS is cached for its
`max-age` and refetched once when a new key id appears.

The simpler alternative is a shared secret: set `IAP_GUARD_GOOGLE_PUBSUB_TOKEN` and use
`https://api.example.com/iap/webhooks/google?token=<secret>` as the push endpoint.

The notification content is never trusted beyond the purchase token. The current state is
always fetched from the Play Developer API.

## Events

| Event | When |
|---|---|
| `PurchaseClaimed` | After every successful `claim()`. Has `$userId` and `$result`. |
| `PurchaseOwnershipTransferred` | The `transfer` policy moved a purchase. Has `$purchase`, `$fromUserId` and `$toUserId`. |
| `IapNotificationProcessed` | Once per processed webhook (not for duplicates). Has `$result` (a `NotificationResult`). |

```php
use BijayX\IapGuard\Enums\NotificationEvent;
use BijayX\IapGuard\Events\IapNotificationProcessed;
use BijayX\IapGuard\Events\PurchaseOwnershipTransferred;

Event::listen(function (IapNotificationProcessed $event) {
    $result = $event->result; // platform, id, event, rawType, stale, purchase, userId, raw

    if ($result->userId === null || $result->purchase === null) {
        return; // not claimed yet, or a test notification
    }

    match ($result->event) {
        NotificationEvent::Refunded, NotificationEvent::Revoked => ClawBack::dispatch($result->userId, $result->purchase->key),
        NotificationEvent::RenewalFailed, NotificationEvent::GracePeriodStarted => NotifyPaymentIssue::dispatch($result->userId),
        default => null,
    };
});

Event::listen(fn (PurchaseOwnershipTransferred $e) => RevokePremium::dispatch($e->fromUserId));
```

`NotificationEvent` values: `purchased`, `renewed`, `recovered`, `renewal_failed`,
`grace_period_started`, `auto_renew_enabled`, `auto_renew_disabled`, `plan_changed`, `expired`,
`refunded`, `revoked`, `refund_reversed`, `paused`, `canceled`, `price_change`,
`consumption_request`, `test`, `other`. The store's own type is in `rawType`, for example
`DID_RENEW/BILLING_RECOVERY` or `SUBSCRIPTION_RENEWED`.

If a synchronous listener throws, the webhook answers 500 and the store redelivers the
notification. Queue slow work.

## Edge cases handled

| # | Scenario | Result |
|---|---|---|
| 1 | Same user, second device (iPad after iPhone, or reinstall) restores | `existing`, `isNew=false`, entitled |
| 2 | Same user, subscribed on iOS, opens the Android app | No Google purchase needed: `entitlements($userId)` already includes the Apple subscription |
| 3 | Two app accounts on one phone with one Apple ID / Google account: B restores A's purchase | `reject` (default) → `ALREADY_OWNED`; `transfer` → B owns it, `previousUserId = A` |
| 4 | Same as 3, but the purchase carries A's account token | `ACCOUNT_MISMATCH` (reject) / transferred (transfer policy) |
| 5 | Two devices of the same user claim at the same moment | Atomic insert + versioned replace: one creates, the other sees `existing`; never two owners |
| 6 | Client replays an old consumable proof | `isNew=false`, so no double grant |
| 7 | Client sends an old (stale) subscription JWS after a newer one | Ownership checks run, but the stored state is not rolled back |
| 8 | Google upgrade/downgrade/resubscribe creates a new token with `linkedPurchaseToken` | The new token joins the existing chain (same key, same owner); the old token can't be claimed by anyone else |
| 9 | Apple upgrade/downgrade within a subscription group | Same `originalTransactionId`, so the same record |
| 10 | Apple Family Sharing | Each family member's transaction has its own `originalTransactionId`; `familyShared=true`; disable with `allow_family_sharing=false` |
| 11 | Webhook arrives before the app claims | Record stored with `userId = null`; the first claim by a matching user takes it (`created`) |
| 12 | User subscribes on both platforms | Both recorded; `otherActive` on the second claim lets the app warn about double billing |
| 13 | Pending purchase (Google "slow card", Ask to Buy) | Recorded, `status=pending`, not entitled, **not acknowledged**; a later notification activates it |
| 14 | Refund / revoke | The notification sets `revoked`; the entitlement ends; the event lets the app claw back consumables |
| 15 | Account deleted | `releaseUser($userId)` sets `userId=null` on that user's records, so the store account can be restored into a new app account |
| 16 | Sandbox purchase on a production server (App Review) | Allowed by default, `environment="sandbox"` |
| 17 | Purchase for another app (bundle/package) | `WRONG_APP` |
| 18 | Forged/tampered/self-signed JWS, Xcode/StoreKit-testing transaction | `INVALID_SIGNATURE` |

Each scenario has a named test in `tests/Feature/ClaimEdgeCasesTest.php`.

## Conflict policies

What should happen when user B claims a purchase that user A already owns? This usually means
two app accounts share one Apple ID or Google account on the same phone.

- **`reject`** (default): B gets `OwnershipException` with code `ALREADY_OWNED`, and A keeps the
  purchase. The message never reveals who A is. This is the safe choice when an entitlement is
  personal or tied to a single account. Show "This purchase is linked to another account" and
  offer to sign in to that account.
- **`transfer`**: B becomes the owner. `ClaimResult::$ownership` is `transferred` and
  `previousUserId` is A, and `PurchaseOwnershipTransferred` is dispatched so you can revoke A's
  access. This matches what users expect from "Restore purchases" on a family device. Paying once
  still unlocks one account at a time.

Account tokens make `reject` precise. When the purchase was made with A's token, B gets
`ACCOUNT_MISMATCH` even before A has claimed it. Under `transfer` the token check is skipped and
the transfer rules apply.

## API reference

```php
IapGuard::verify(PurchaseProof|array $proof): Purchase
IapGuard::claim(string|int $userId, PurchaseProof|array $proof): ClaimResult
IapGuard::handleAppleNotification(string|array $body): NotificationResult
IapGuard::handleGoogleNotification(string|array $body, ?string $authorization = null, ?string $verificationToken = null): NotificationResult
IapGuard::entitlements(string|int $userId, ?DateTimeInterface $now = null): list<Purchase>
IapGuard::isEntitled(Purchase $purchase, ?DateTimeInterface $now = null): bool
IapGuard::releaseUser(string|int $userId): int
IapGuard::accountTokenFor(string|int $userId): string
```

Proof input (camelCase or snake_case keys):

| Platform | Keys |
|---|---|
| Apple | `platform: "apple"` and exactly one of `signedTransaction` (StoreKit 2 `jwsRepresentation`, preferred, works offline) or `transactionId` (needs the Server API) |
| Google | `platform: "google"`, `type: "subscription" \| "one_time"`, `purchaseToken`, optional `productId` (must match → else `PRODUCT_MISMATCH`) |

### `Purchase`

An immutable, normalized purchase. `toArray()` / JSON use camelCase keys and ISO-8601 UTC dates
with milliseconds, exactly like the Node.js package.

| Field | Notes |
|---|---|
| `platform`, `key` | `apple` / `google`, ownership key |
| `productId`, `productIds` | Google bundles can have several products |
| `type` | `subscription`, `non_consumable`, `consumable`, `non_renewing_subscription` |
| `status` | `pending`, `active`, `grace_period`, `billing_retry`, `paused`, `expired`, `revoked`, `canceled` |
| `environment` | `production` or `sandbox` (Apple Sandbox and Google test purchases) |
| `transactionId`, `originalTransactionId` | Apple ids. Google: order id (`""` while pending) |
| `purchaseToken`, `linkedPurchaseToken` | Google only |
| `purchasedAt`, `expiresAt`, `gracePeriodExpiresAt`, `revokedAt`, `signedAt` | `CarbonImmutable` (UTC) |
| `willRenew`, `autoRenewProductId` | Auto-renew state (`autoRenewProductId` = Apple pending downgrade) |
| `accountToken` | `appAccountToken` / `obfuscatedExternalAccountId` |
| `quantity`, `familyShared`, `acknowledged` | `acknowledged` is Google only |
| `raw` | Decoded store payloads, never your secrets |

## Error handling

Every exception extends `BijayX\IapGuard\Exceptions\IapException` and has a stable
`errorCode()`, a `platform()` and secret-free `details()`.

| Exception | Codes | Typical response |
|---|---|---|
| `VerificationException` | `INVALID_SIGNATURE`, `WRONG_APP`, `PRODUCT_MISMATCH`, `INVALID_NOTIFICATION` | 422, don't retry |
| `PolicyException` | `ENVIRONMENT_NOT_ALLOWED`, `UNKNOWN_PRODUCT`, `FAMILY_SHARING_NOT_ALLOWED` | 422 |
| `OwnershipException` | `ALREADY_OWNED`, `ACCOUNT_MISMATCH` | 409 |
| `PurchaseNotFoundException` | `PURCHASE_NOT_FOUND` | 404 / 422 |
| `ValidationException` | `VALIDATION_ERROR` (`fields()`) | 422 |
| `StoreApiException` | `STORE_API_ERROR` (`statusCode()`, `responseBody()`, `retryable()`) | 503 when `retryable()` |
| `NetworkException` | `NETWORK_ERROR`, `TIMEOUT` | 503, retry later |
| `AuthenticationException` | `AUTHENTICATION_FAILED` (your Apple key or Google service account was rejected) | 500, fix your credentials |
| `ConfigurationException` | `CONFIGURATION_ERROR` | 500 |
| `IapException` | `CONCURRENT_MODIFICATION` (lost three races in a row) | 409 / retry |

## Testing your application

Use the in-memory store and fake the stores' HTTP APIs:

```php
config(['iap-guard.store.driver' => 'memory']);

Http::fake([
    'androidpublisher.googleapis.com/*' => Http::response([...subscriptionsv2 body...]),
    'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test', 'expires_in' => 3600]),
]);

Event::fake([PurchaseClaimed::class]);
```

Apple JWS verification uses real cryptography. For end-to-end Apple tests, sign fixtures with
your own test chain and set `iap-guard.apple.root_certificates` to your test root. This package's
`tests/fixtures` (generated by the shared specification) show how. The clock comes from Laravel,
so `Carbon::setTestNow()` and `$this->travel()` work. Backoff uses the `Sleep` helper, so
`Sleep::fake()` keeps retries instant.

## Sandbox testing

- **Apple:** sign in with a Sandbox account (Settings → App Store → Sandbox Account) on a real
  device, or use TestFlight. Those transactions are Apple-signed with `environment = Sandbox`
  and accepted by default (App Review uses them too). Xcode StoreKit Testing transactions are
  signed locally and **always** fail with `INVALID_SIGNATURE`. Test the UI with them, but not
  your server. The App Store Server API falls back from production to sandbox automatically
  when looking up a transaction id.
- **Google:** add license testers in Play Console → Setup → License testing. Their purchases
  come back with `environment = sandbox`. Set `IAP_GUARD_GOOGLE_ALLOW_TEST_PURCHASES=false` in
  production if testers shouldn't unlock anything there.
- Use `$purchase->environment` to keep sandbox purchases out of revenue reports.

## Security checklist

- [ ] Claim only from an authenticated endpoint, and pass your own user id. Never trust a user id from the device.
- [ ] Set `IAP_GUARD_ACCOUNT_TOKEN_SECRET` (32+ random characters, kept server-side) and pass `accountTokenFor()` to StoreKit / Play Billing.
- [ ] Grant consumables only when `$result->isNew` is `true`.
- [ ] Keep the default Apple root. Override `apple.root_certificates` only in tests.
- [ ] Configure `products` if only some product ids should unlock anything.
- [ ] Configure Pub/Sub push **with authentication** (OIDC audience and service-account email), not an open endpoint.
- [ ] Keep the webhook routes free of auth/CSRF middleware, but serve them over HTTPS only.
- [ ] Store the `.p8` key, the service-account JSON and the account-token secret outside your repository, and rotate them if they leak.
- [ ] Use a shared cache (Redis, database) in multi-server deployments, so the OAuth token and JWKS are shared.
- [ ] Listen to `IapNotificationProcessed` to revoke access and claw back consumables on refunds.
- [ ] Call `releaseUser()` when you delete an account.
- [ ] Don't log raw request bodies of the claim endpoint (purchase tokens and JWS are bearer proofs).

IAP Guard never puts private keys, access tokens, JWTs, the account-token secret or other users'
ids into exception messages or logs. `ALREADY_OWNED` doesn't reveal who owns the purchase.

## Limitations

- **No OCSP / revocation checks** for Apple's certificate chain. This matches Apple's own library
  with online checks disabled. The chain, the Apple marker OIDs and the validity at `signedDate`
  are verified.
- **No StoreKit 1 receipts** (`verifyReceipt` is deprecated). Use StoreKit 2's
  `jwsRepresentation`, or verify by `transactionId` with the App Store Server API.
- Not supported: Google `purchases.subscriptions.get` (v1), Apple Advanced Commerce API,
  external purchase tokens, and replying to `CONSUMPTION_REQUEST` (you receive the event).
- Google partial refunds (`refundType = 2`) keep the purchase active and only emit `refunded`.
  Adjust quantities in your listener.
- The database store is tested on SQLite. It relies only on unique indexes and versioned updates,
  which MySQL, MariaDB, PostgreSQL and SQL Server all support.
- `iap_purchases.user_id` is a string column holding `(string) $user->getKey()`.

## Development

```bash
composer install
composer ci   # Pint, PHPStan (level 8), Pest
```

`SPEC.md` is the shared contract with the Node.js package. `tests/fixtures` are generated from it.

## License

MIT. See [LICENSE](LICENSE).
