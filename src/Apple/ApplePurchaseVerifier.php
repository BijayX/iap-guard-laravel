<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Data\Purchase;
use BijayX\IapGuard\Data\PurchaseProof;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\PurchaseNotFoundException;
use BijayX\IapGuard\Support\ProductAllowlist;

/**
 * verify() for Apple (SPEC §5.4).
 */
final readonly class ApplePurchaseVerifier
{
    public function __construct(
        private AppleConfig $config,
        private SignedDataVerifier $verifier,
        private AppleNormalizer $normalizer,
        private ProductAllowlist $allowlist,
        private ?AppStoreServerApi $api = null,
    ) {}

    /**
     * @throws IapException
     */
    public function verify(PurchaseProof $proof): Purchase
    {
        $transaction = $proof->signedTransaction !== null
            ? $this->verifier->verifyTransaction($proof->signedTransaction)
            : $this->fetchTransaction((string) $proof->transactionId);

        $purchase = null;

        if (($transaction['type'] ?? null) === 'Auto-Renewable Subscription' && $this->api !== null) {
            $purchase = $this->latestSubscriptionState($this->api, $transaction);
        }

        $purchase ??= $this->normalizer->normalize($transaction);

        if ($purchase->familyShared && ! $this->config->allowFamilySharing) {
            throw PolicyException::familySharingNotAllowed();
        }

        $this->allowlist->check($purchase);

        return $purchase;
    }

    /**
     * Look the transaction up in production first, then sandbox on "transaction id not found".
     *
     * @return array<string, mixed>
     *
     * @throws IapException
     */
    private function fetchTransaction(string $transactionId): array
    {
        $api = $this->api ?? throw new ConfigurationException(
            'Verifying by transactionId requires the App Store Server API [iap-guard.apple.api].',
            Platform::Apple,
            'apple.api',
        );

        $notFound = null;

        foreach ($this->config->lookupOrder() as $environment) {
            try {
                return $this->verifier->verifyTransaction($api->getTransactionInfo($transactionId, $environment));
            } catch (PurchaseNotFoundException $e) {
                $notFound = $e;
            }
        }

        throw $notFound ?? new PurchaseNotFoundException(Platform::Apple);
    }

    /**
     * Latest state of an auto-renewable subscription from the Server API (verified like client input).
     *
     * @param  array<string, mixed>  $transaction
     *
     * @throws IapException
     */
    private function latestSubscriptionState(AppStoreServerApi $api, array $transaction): ?Purchase
    {
        $environment = ($transaction['environment'] ?? null) === 'Sandbox' ? Environment::Sandbox : Environment::Production;
        $original = $transaction['originalTransactionId'] ?? null;

        $response = $api->getAllSubscriptionStatuses((string) ($transaction['transactionId'] ?? ''), $environment);

        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $group) {
            foreach (is_array($group['lastTransactions'] ?? null) ? $group['lastTransactions'] : [] as $item) {
                if (! is_array($item) || ($item['originalTransactionId'] ?? null) !== $original || ! is_string($item['signedTransactionInfo'] ?? null)) {
                    continue;
                }

                $latest = $this->verifier->verifyTransaction($item['signedTransactionInfo']);
                $renewal = is_string($item['signedRenewalInfo'] ?? null)
                    ? $this->verifier->verifyRenewalInfo($item['signedRenewalInfo'])
                    : null;

                return $this->normalizer->normalize($latest, $renewal, is_int($item['status'] ?? null) ? $item['status'] : null);
            }
        }

        return null;
    }
}
