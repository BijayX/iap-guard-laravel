<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Apple;

use BijayX\IapGuard\Crypto\CertificateChain;
use BijayX\IapGuard\Crypto\CryptoException;
use BijayX\IapGuard\Crypto\Jws;
use BijayX\IapGuard\Crypto\Jwt;
use BijayX\IapGuard\Enums\Environment;
use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\VerificationException;
use BijayX\IapGuard\Support\Time;

/**
 * Verifies Apple-signed JWS values: signed transactions, signed renewal info and
 * App Store Server Notifications V2 payloads (SPEC §5.2).
 */
final class SignedDataVerifier
{
    public const MAX_CLOCK_SKEW_MS = 60_000;

    private ?CertificateChain $chain = null;

    public function __construct(private readonly AppleConfig $config) {}

    /**
     * Verify a JWSTransaction and return its decoded payload.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException|PolicyException
     */
    public function verifyTransaction(string $jws): array
    {
        $payload = $this->verify($jws);

        if (($payload['bundleId'] ?? null) !== $this->config->bundleId) {
            throw VerificationException::wrongApp(Platform::Apple);
        }

        $this->checkEnvironment($payload['environment'] ?? null);

        return $payload;
    }

    /**
     * Verify a JWSRenewalInfo (it carries no bundleId) and return its decoded payload.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException|PolicyException
     */
    public function verifyRenewalInfo(string $jws): array
    {
        $payload = $this->verify($jws);
        $this->checkEnvironment($payload['environment'] ?? null);

        return $payload;
    }

    /**
     * Verify a notification's signedPayload, including bundleId, environment and appAppleId
     * from its data / summary / externalPurchaseToken.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException|PolicyException|ConfigurationException
     */
    public function verifyNotification(string $jws): array
    {
        if ($this->config->allows(Environment::Production) && $this->config->appAppleId === null) {
            throw new ConfigurationException(
                'Configuration [iap-guard.apple.app_apple_id] is required to verify notifications while the production environment is allowed.',
                Platform::Apple,
                'apple.app_apple_id',
            );
        }

        $payload = $this->verify($jws);

        $context = is_array($payload['data'] ?? null) ? $payload['data']
            : (is_array($payload['summary'] ?? null) ? $payload['summary'] : null);

        if ($context !== null) {
            $bundleId = $context['bundleId'] ?? null;
            $environment = $context['environment'] ?? null;
            $appAppleId = $context['appAppleId'] ?? null;
        } elseif (is_array($payload['externalPurchaseToken'] ?? null)) {
            $token = $payload['externalPurchaseToken'];
            $bundleId = $token['bundleId'] ?? null;
            $appAppleId = $token['appAppleId'] ?? null;
            $environment = str_starts_with((string) (is_scalar($token['externalPurchaseId'] ?? null) ? $token['externalPurchaseId'] : ''), 'SANDBOX')
                ? 'Sandbox'
                : 'Production';
        } else {
            throw VerificationException::invalidNotification(Platform::Apple, 'The notification has no data, summary or externalPurchaseToken.');
        }

        if ($bundleId !== $this->config->bundleId) {
            throw VerificationException::wrongApp(Platform::Apple);
        }

        $environment = $this->checkEnvironment($environment);

        if ($environment === Environment::Production
            && $this->config->appAppleId !== null
            && (! is_numeric($appAppleId) || (int) $appAppleId !== $this->config->appAppleId)) {
            throw VerificationException::wrongApp(Platform::Apple);
        }

        return $payload;
    }

    /**
     * Signature and chain verification shared by every signed type.
     *
     * @return array<string, mixed>
     *
     * @throws VerificationException
     */
    public function verify(string $jws): array
    {
        try {
            $token = Jws::parse($jws);
        } catch (CryptoException) {
            throw VerificationException::invalidSignature(Platform::Apple, 'The signed data is not a valid JWS.');
        }

        if ($token->alg() !== 'ES256') {
            throw VerificationException::invalidSignature(Platform::Apple, 'The signed data must use ES256.');
        }

        $signedDate = $token->payload['signedDate'] ?? null;

        if (! is_int($signedDate) && ! (is_float($signedDate) && is_finite($signedDate))) {
            throw VerificationException::invalidSignature(Platform::Apple, 'The signed data has no signedDate.');
        }

        $signedDate = (int) $signedDate;

        if ($signedDate > Time::toMs(Time::now()) + self::MAX_CLOCK_SKEW_MS) {
            throw VerificationException::invalidSignature(Platform::Apple, 'The signed data is dated in the future.');
        }

        try {
            $leafKey = $this->chain()->verify($token->header['x5c'] ?? null, $signedDate);
        } catch (CryptoException $e) {
            // The reason names the failed check only; it never contains certificate or token data.
            throw VerificationException::invalidSignature(Platform::Apple, 'The certificate chain is not trusted: '.$e->getMessage());
        }

        if (! Jwt::verifyEs256($token->signingInput, $token->signature, $leafKey)) {
            throw VerificationException::invalidSignature(Platform::Apple);
        }

        return $token->payload;
    }

    /**
     * Map Apple's environment string and enforce the configured environments.
     *
     * @throws VerificationException|PolicyException
     */
    private function checkEnvironment(mixed $value): Environment
    {
        $environment = match ($value) {
            'Production' => Environment::Production,
            'Sandbox' => Environment::Sandbox,
            // Xcode / LocalTesting transactions are not signed by Apple's production chain.
            default => throw VerificationException::invalidSignature(Platform::Apple, 'Unsupported App Store environment.'),
        };

        if (! $this->config->allows($environment)) {
            throw PolicyException::environmentNotAllowed(Platform::Apple, $environment->value);
        }

        return $environment;
    }

    private function chain(): CertificateChain
    {
        try {
            return $this->chain ??= new CertificateChain($this->config->rootCertificates);
        } catch (CryptoException) {
            throw new ConfigurationException('Configuration [iap-guard.apple.root_certificates] contains an invalid certificate.', Platform::Apple, 'apple.root_certificates');
        }
    }
}
