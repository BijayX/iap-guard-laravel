<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

/**
 * Converts ES256 signatures between JOSE's raw r||s (64 bytes) and the DER
 * SEQUENCE { INTEGER r, INTEGER s } that openssl_sign()/openssl_verify() use.
 */
final class EcdsaSignature
{
    public const P256_PART_LENGTH = 32;

    /**
     * @throws CryptoException when the signature is not exactly 64 bytes
     */
    public static function rawToDer(string $raw): string
    {
        if (strlen($raw) !== 2 * self::P256_PART_LENGTH) {
            throw new CryptoException('ES256 signatures must be 64 bytes (r||s).');
        }

        return Asn1::sequence(
            Asn1::unsignedInteger(substr($raw, 0, self::P256_PART_LENGTH)),
            Asn1::unsignedInteger(substr($raw, self::P256_PART_LENGTH)),
        );
    }

    /**
     * @throws CryptoException
     */
    public static function derToRaw(string $der): string
    {
        [$tag, $sequence, $end] = Asn1::read($der);

        if ($tag !== Asn1::SEQUENCE || $end !== strlen($der)) {
            throw new CryptoException('Invalid ECDSA signature.');
        }

        [$rTag, $r, $next] = Asn1::read($sequence);
        [$sTag, $s, $last] = Asn1::read($sequence, $next);

        if ($rTag !== Asn1::INTEGER || $sTag !== Asn1::INTEGER || $last !== strlen($sequence)) {
            throw new CryptoException('Invalid ECDSA signature.');
        }

        return self::pad($r).self::pad($s);
    }

    private static function pad(string $integer): string
    {
        $integer = ltrim($integer, "\x00");

        if (strlen($integer) > self::P256_PART_LENGTH) {
            throw new CryptoException('Invalid ECDSA signature.');
        }

        return str_pad($integer, self::P256_PART_LENGTH, "\x00", STR_PAD_LEFT);
    }
}
