<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Google;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Support\ProductAllowlist;
use BijayX\IapGuard\Support\Time;

/**
 * verify() for Google Play (SPEC §6.4). There is no packageName in Google's responses: the
 * package is enforced by the URL we call.
 */
final readonly class GooglePurchaseVerifier
{
    public function __construct(
        private GoogleConfig $config,
        private PlayDeveloperApi $api,
        private GoogleNormalizer $normalizer,
        private ProductAllowlist $allowlist,
    ) {}

    /**
     * @throws IapException
     */
    public function verify(PurchaseProof $proof): Purchase
    {
        $purchase = $this->fetch($proof->isGoogleSubscription(), (string) $proof->purchaseToken);

        if ($proof->productId !== null && ! in_array($proof->productId, $purchase->productIds, true)) {
            throw VerificationException::productMismatch(Platform::Google, $proof->productId);
        }

        if ($purchase->environment === Environment::Sandbox && ! $this->config->allowTestPurchases) {
            throw PolicyException::environmentNotAllowed(Platform::Google, Environment::Sandbox->value);
        }

        $this->allowlist->check($purchase);

        return $purchase;
    }

    /**
     * Current state straight from the Play Developer API (no policy checks).
     *
     * @throws IapException
     */
    public function fetch(bool $subscription, string $token): Purchase
    {
        $body = $subscription ? $this->api->getSubscription($token) : $this->api->getProduct($token);
        $fetchedAt = Time::now();

        return $subscription
            ? $this->normalizer->subscription($token, $body, $fetchedAt)
            : $this->normalizer->product($token, $body, $fetchedAt);
    }
}
