<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

use OpenSSLAsymmetricKey;

/**
 * Key loading helpers: PEM/DER conversion and JWK (RSA) → public key.
 */
final class Keys
{
    private const RSA_ENCRYPTION_OID = '1.2.840.113549.1.1.1';

    /**
     * @throws CryptoException
     */
    public static function privateKey(string $pem): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new CryptoException('The private key could not be parsed.');
        }

        return $key;
    }

    /**
     * Build a SubjectPublicKeyInfo from a JWK's modulus and exponent:
     * SEQUENCE { SEQUENCE { rsaEncryption, NULL }, BIT STRING { SEQUENCE { n, e } } }.
     *
     * @throws CryptoException
     */
    public static function rsaPublicKeyFromJwk(string $n, string $e): OpenSSLAsymmetricKey
    {
        $modulus = Base64Url::decode($n);
        $exponent = Base64Url::decode($e);

        if (strlen(ltrim($modulus, "\x00")) < 256 || $exponent === '') {
            throw new CryptoException('RSA keys must be at least 2048 bits.');
        }

        $spki = Asn1::sequence(
            Asn1::sequence(Asn1::objectIdentifier(self::RSA_ENCRYPTION_OID), Asn1::null()),
            Asn1::bitString(Asn1::sequence(Asn1::unsignedInteger($modulus), Asn1::unsignedInteger($exponent))),
        );

        $key = openssl_pkey_get_public(self::pem('PUBLIC KEY', $spki));

        if ($key === false) {
            throw new CryptoException('The JWK could not be converted to a public key.');
        }

        return $key;
    }

    public static function pem(string $label, string $der): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    /**
     * Accepts a PEM certificate or a DER certificate and returns PEM.
     *
     * @throws CryptoException
     */
    public static function certificatePem(string $certificate): string
    {
        $pem = str_contains($certificate, '-----BEGIN CERTIFICATE-----')
            ? $certificate
            : self::pem('CERTIFICATE', $certificate);

        if (openssl_x509_read($pem) === false) {
            throw new CryptoException('Invalid X.509 certificate.');
        }

        return $pem;
    }
}
