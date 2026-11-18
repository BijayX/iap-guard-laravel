# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - Unreleased

### Added

- `IapGuard::verify()` for Apple (StoreKit 2 signed transactions, or transaction ids through the
  App Store Server API) and Google Play (subscriptionsv2 / productsv2), normalized into one
  immutable `Purchase` DTO.
- Apple JWS verification with `ext-openssl` only: x5c chain to the bundled Apple Root CA - G3,
  Apple marker OIDs, validity at `signedDate`, raw r||s ES256 signatures.
- App Store Server API client (ES256 JWT auth, production → sandbox fallback, retries with
  `Retry-After`).
- Google service-account OAuth with a cached access token, and a Play Developer API client.
- `IapGuard::claim()` with ownership protection: one owner per purchase, atomic insert and versioned
  replace, Google upgrade chains, account tokens (`appAccountToken` / `obfuscatedAccountId`),
  `reject` and `transfer` conflict policies, consumable replay protection.
- Automatic Google acknowledge / consume after a claim or notification.
- Webhooks for App Store Server Notifications V2 and Play RTDN (Pub/Sub push with OIDC or a
  shared token), with dedupe and stale-state protection.
- `entitlements()`, `releaseUser()`, `accountTokenFor()`, the `HasPurchases` model trait and the
  `IapPurchase` Eloquent model.
- `DatabasePurchaseStore` with migrations, and `InMemoryPurchaseStore` for tests.
- `PurchaseClaimed`, `PurchaseOwnershipTransferred` and `IapNotificationProcessed` events.
