<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

use OpenSSLAsymmetricKey;

/**
 * ES256 / RS256 signing and verification with ext-openssl only.
 */
final class Jwt
{
    /**
     * Sign an ES256 JWT (App Store Server API). The signature is raw r||s, base64url.
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     *
     * @throws CryptoException
     */
    public static function signEs256(array $header, array $claims, OpenSSLAsymmetricKey $privateKey): string
    {
        $input = self::signingInput($header, $claims);

        if (! openssl_sign($input, $der, $privateKey, OPENSSL_ALGO_SHA256) || ! is_string($der)) {
            throw new CryptoException('Could not create the ES256 signature.');
        }

        return $input.'.'.Base64Url::encode(EcdsaSignature::derToRaw($der));
    }

    /**
     * Sign an RS256 JWT (Google service-account assertion).
     *
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     *
     * @throws CryptoException
     */
    public static function signRs256(array $header, array $claims, OpenSSLAsymmetricKey $privateKey): string
    {
        $input = self::signingInput($header, $claims);

        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            throw new CryptoException('Could not create the RS256 signature.');
        }

        return $input.'.'.Base64Url::encode($signature);
    }

    /**
     * Verify a raw r||s ES256 signature. Anything other than 64 bytes is rejected.
     */
    public static function verifyEs256(string $signingInput, string $rawSignature, OpenSSLAsymmetricKey $publicKey): bool
    {
        try {
            $der = EcdsaSignature::rawToDer($rawSignature);
        } catch (CryptoException) {
            return false;
        }

        return openssl_verify($signingInput, $der, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function verifyRs256(string $signingInput, string $signature, OpenSSLAsymmetricKey $publicKey): bool
    {
        return $signature !== '' && openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $claims
     */
    private static function signingInput(array $header, array $claims): string
    {
        return Base64Url::encode(self::json($header)).'.'.Base64Url::encode(self::json($claims));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
