<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

/**
 * RFC 4648 §5 base64url without padding (JWS/JWT compact serialization).
 */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Strict decoding: rejects characters outside the url-safe alphabet and impossible lengths.
     *
     * @throws CryptoException
     */
    public static function decode(string $data): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $data) !== 1 || strlen($data) % 4 === 1) {
            throw new CryptoException('Invalid base64url data.');
        }

        $decoded = base64_decode(str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '='), true);

        if ($decoded === false) {
            throw new CryptoException('Invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * Strict decoding of standard base64 (x5c certificates, Pub/Sub message data).
     *
     * @throws CryptoException
     */
    public static function decodeStandard(string $data): string
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false || $data === '') {
            throw new CryptoException('Invalid base64 data.');
        }

        return $decoded;
    }
}
