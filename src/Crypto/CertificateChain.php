<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Validates the x5c chain of an Apple-signed JWS (SPEC §5.2 steps 2–7), the same way as
 * Apple's app-store-server-library with online (OCSP) checks disabled.
 *
 * x5c[2] is never trusted: the intermediate must chain to one of the configured roots.
 */
final class CertificateChain
{
    public const LEAF_OID = '1.2.840.113635.100.6.11.1';

    public const INTERMEDIATE_OID = '1.2.840.113635.100.6.2.1';

    /** @var list<array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}> */
    private array $roots = [];

    /**
     * @param  list<string>  $rootCertificates  PEM or DER encoded trust anchors.
     *
     * @throws CryptoException
     */
    public function __construct(array $rootCertificates)
    {
        foreach ($rootCertificates as $root) {
            $this->roots[] = self::load(Keys::certificatePem($root));
        }

        if ($this->roots === []) {
            throw new CryptoException('At least one root certificate is required.');
        }
    }

    /**
     * Verify the chain at $effectiveMs and return the leaf's public key.
     *
     * @param  mixed  $x5c  The JWS header's x5c value.
     *
     * @throws CryptoException
     */
    public function verify(mixed $x5c, int $effectiveMs): OpenSSLAsymmetricKey
    {
        if (! is_array($x5c) || ! array_is_list($x5c) || count($x5c) !== 3) {
            throw new CryptoException('x5c must contain exactly three certificates.');
        }

        $leaf = self::load(self::fromX5c($x5c[0]));
        $intermediate = self::load(self::fromX5c($x5c[1]));

        $root = $this->trustedIssuerOf($intermediate);

        if ($root === null) {
            throw new CryptoException('The intermediate certificate is not issued by a trusted root.');
        }

        if (! self::issuedBy($leaf, $intermediate)) {
            throw new CryptoException('The leaf certificate is not issued by the intermediate.');
        }

        if (! self::isCa($intermediate['info'])) {
            throw new CryptoException('The intermediate certificate is not a CA.');
        }

        if (! self::hasExtension($leaf['info'], self::LEAF_OID) || ! self::hasExtension($intermediate['info'], self::INTERMEDIATE_OID)) {
            throw new CryptoException('The certificates are missing the Apple marker extensions.');
        }

        foreach ([$leaf, $intermediate, $root] as $certificate) {
            if (! self::validAt($certificate['info'], $effectiveMs)) {
                throw new CryptoException('A certificate in the chain is not valid at the signing date.');
            }
        }

        $details = openssl_pkey_get_details($leaf['key']);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new CryptoException('The leaf key is not a P-256 key.');
        }

        return $leaf['key'];
    }

    /**
     * @param  array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}  $intermediate
     * @return array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}|null
     */
    private function trustedIssuerOf(array $intermediate): ?array
    {
        foreach ($this->roots as $root) {
            if (self::issuedBy($intermediate, $root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * @param  array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}  $subject
     * @param  array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}  $issuer
     */
    private static function issuedBy(array $subject, array $issuer): bool
    {
        return ($subject['info']['issuer'] ?? null) === ($issuer['info']['subject'] ?? false)
            && openssl_x509_verify($subject['cert'], $issuer['key']) === 1;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private static function isCa(array $info): bool
    {
        $constraints = $info['extensions']['basicConstraints'] ?? null;

        return is_string($constraints) && preg_match('/(^|[\s,])CA:TRUE([\s,]|$)/', $constraints) === 1;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private static function hasExtension(array $info, string $oid): bool
    {
        return is_array($info['extensions'] ?? null) && array_key_exists($oid, $info['extensions']);
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private static function validAt(array $info, int $ms): bool
    {
        $from = $info['validFrom_time_t'] ?? null;
        $to = $info['validTo_time_t'] ?? null;

        return is_int($from) && is_int($to) && $from * 1000 <= $ms && $ms <= $to * 1000;
    }

    private static function fromX5c(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new CryptoException('Invalid x5c certificate.');
        }

        return Keys::pem('CERTIFICATE', Base64Url::decodeStandard($value));
    }

    /**
     * @return array{cert: OpenSSLCertificate, info: array<string, mixed>, key: OpenSSLAsymmetricKey}
     */
    private static function load(string $pem): array
    {
        $cert = openssl_x509_read($pem);
        $info = $cert === false ? false : openssl_x509_parse($cert);
        $key = $cert === false ? false : openssl_pkey_get_public($cert);

        if ($cert === false || ! is_array($info) || $key === false) {
            throw new CryptoException('Invalid X.509 certificate.');
        }

        return ['cert' => $cert, 'info' => $info, 'key' => $key];
    }
}
