<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Data;

use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ValidationException;

/**
 * What a device sends as proof of a purchase (SPEC §5.4, §6.4).
 *
 * Apple: exactly one of signedTransaction (StoreKit 2 jwsRepresentation, preferred) or transactionId.
 * Google: purchaseToken plus type ("subscription" or "one_time"), optionally the expected productId.
 */
final readonly class PurchaseProof
{
    public const GOOGLE_SUBSCRIPTION = 'subscription';

    public const GOOGLE_ONE_TIME = 'one_time';

    private const MAX_LENGTH = 65536;

    private function __construct(
        public Platform $platform,
        public ?string $signedTransaction = null,
        public ?string $transactionId = null,
        public ?string $purchaseToken = null,
        public ?string $type = null,
        public ?string $productId = null,
    ) {}

    public static function appleSignedTransaction(string $signedTransaction): self
    {
        return self::fromArray(['platform' => 'apple', 'signedTransaction' => $signedTransaction]);
    }

    public static function appleTransactionId(string $transactionId): self
    {
        return self::fromArray(['platform' => 'apple', 'transactionId' => $transactionId]);
    }

    public static function google(string $type, string $purchaseToken, ?string $productId = null): self
    {
        return self::fromArray(['platform' => 'google', 'type' => $type, 'purchaseToken' => $purchaseToken, 'productId' => $productId]);
    }

    /**
     * Accepts camelCase (shared with the Node.js package) or snake_case keys, so validated
     * request input can be passed straight through.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input): self
    {
        $platform = is_string($input['platform'] ?? null) ? Platform::tryFrom(strtolower($input['platform'])) : null;

        if ($platform === null) {
            throw ValidationException::forField('platform', 'must be "apple" or "google".');
        }

        if ($platform === Platform::Apple) {
            $signed = self::string($input, 'signedTransaction', 'signed_transaction', $platform);
            $transactionId = self::string($input, 'transactionId', 'transaction_id', $platform);

            if (($signed === null) === ($transactionId === null)) {
                throw new ValidationException(
                    'Provide exactly one of signedTransaction or transactionId.',
                    ['signedTransaction' => ['Provide exactly one of signedTransaction or transactionId.']],
                    $platform,
                );
            }

            if ($transactionId !== null && preg_match('/^\d{1,32}$/', $transactionId) !== 1) {
                throw ValidationException::forField('transactionId', 'must be a numeric App Store transaction id.', $platform);
            }

            return new self($platform, signedTransaction: $signed, transactionId: $transactionId);
        }

        $type = self::string($input, 'type', 'type', $platform);

        if ($type !== self::GOOGLE_SUBSCRIPTION && $type !== self::GOOGLE_ONE_TIME) {
            throw ValidationException::forField('type', 'must be "subscription" or "one_time".', $platform);
        }

        $token = self::string($input, 'purchaseToken', 'purchase_token', $platform)
            ?? throw ValidationException::forField('purchaseToken', 'is required.', $platform);

        return new self(
            $platform,
            purchaseToken: $token,
            type: $type,
            productId: self::string($input, 'productId', 'product_id', $platform),
        );
    }

    public function isGoogleSubscription(): bool
    {
        return $this->platform === Platform::Google && $this->type === self::GOOGLE_SUBSCRIPTION;
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    private static function string(array $input, string $camel, string $snake, Platform $platform): ?string
    {
        $value = $input[$camel] ?? $input[$snake] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || strlen($value) > self::MAX_LENGTH) {
            throw ValidationException::forField($camel, 'must be a string.', $platform);
        }

        return trim($value);
    }
}
