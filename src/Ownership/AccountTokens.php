<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Ownership;

use BijayX\IapGuard\Exceptions\ConfigurationException;

/**
 * Deterministic per-user purchase tokens (SPEC §7.5).
 *
 * accountTokenFor($userId) is a UUID derived from HMAC-SHA256(secret, "iap-guard:" . $userId):
 * the first 16 bytes with version 8 and the RFC 4122 variant bits set. The app passes it to
 * StoreKit 2 (.appAccountToken) or Play Billing (setObfuscatedAccountId). It reveals nothing about
 * the user id and can't be forged for another user without the secret.
 */
final readonly class AccountTokens
{
    public const MIN_SECRET_LENGTH = 32;

    public function __construct(private ?string $secret) {}

    public function enabled(): bool
    {
        return $this->secret !== null && $this->secret !== '';
    }

    /**
     * @throws ConfigurationException
     */
    public function for(string $userId): string
    {
        if (! $this->enabled()) {
            throw ConfigurationException::missing('ownership.account_token_secret');
        }

        return self::derive((string) $this->secret, $userId);
    }

    /**
     * Case-insensitive, constant-time comparison.
     */
    public function matches(string $token, string $userId): bool
    {
        return hash_equals($this->for($userId), strtolower(trim($token)));
    }

    public static function derive(string $secret, string $userId): string
    {
        if (strlen($secret) < self::MIN_SECRET_LENGTH) {
            throw ConfigurationException::invalid('ownership.account_token_secret', 'a secret of at least 32 characters');
        }

        $bytes = substr(hash_hmac('sha256', 'iap-guard:'.$userId, $secret, true), 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x80);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
