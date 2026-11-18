<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

/**
 * Minimal DER encoder/decoder: just what ES256 signature conversion and JWK → SPKI need.
 *
 * @internal
 */
final class Asn1
{
    public const INTEGER = 0x02;

    public const BIT_STRING = 0x03;

    public const NULL = 0x05;

    public const OBJECT_IDENTIFIER = 0x06;

    public const SEQUENCE = 0x30;

    public static function sequence(string ...$elements): string
    {
        return self::tlv(self::SEQUENCE, implode('', $elements));
    }

    /**
     * Unsigned big-endian bytes → DER INTEGER (minimal, with a 0x00 prefix when the high bit is set).
     */
    public static function unsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return self::tlv(self::INTEGER, $bytes);
    }

    public static function bitString(string $bytes): string
    {
        return self::tlv(self::BIT_STRING, "\x00".$bytes);
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function objectIdentifier(string $oid): string
    {
        $parts = array_map(intval(...), explode('.', $oid));

        if (count($parts) < 2) {
            throw new CryptoException('Invalid OID.');
        }

        $body = chr(40 * $parts[0] + $parts[1]);

        foreach (array_slice($parts, 2) as $part) {
            $chunk = chr($part & 0x7F);

            while (($part >>= 7) > 0) {
                $chunk = chr(0x80 | ($part & 0x7F)).$chunk;
            }

            $body .= $chunk;
        }

        return self::tlv(self::OBJECT_IDENTIFIER, $body);
    }

    public static function tlv(int $tag, string $content): string
    {
        return chr($tag).self::length(strlen($content)).$content;
    }

    public static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * Read one TLV at $offset. Returns [tag, content, nextOffset]. Only definite, minimal lengths.
     *
     * @return array{int, string, int}
     *
     * @throws CryptoException
     */
    public static function read(string $der, int $offset = 0): array
    {
        $total = strlen($der);

        if ($offset + 2 > $total) {
            throw new CryptoException('Truncated DER.');
        }

        $tag = ord($der[$offset]);
        $length = ord($der[$offset + 1]);
        $offset += 2;

        if ($length & 0x80) {
            $count = $length & 0x7F;

            if ($count === 0 || $count > 4 || $offset + $count > $total) {
                throw new CryptoException('Unsupported DER length.');
            }

            $length = 0;

            for ($i = 0; $i < $count; $i++) {
                $length = ($length << 8) | ord($der[$offset + $i]);
            }

            $offset += $count;

            if ($length < 0x80) {
                throw new CryptoException('Non-minimal DER length.');
            }
        }

        if ($offset + $length > $total) {
            throw new CryptoException('Truncated DER.');
        }

        return [$tag, substr($der, $offset, $length), $offset + $length];
    }
}
