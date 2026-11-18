<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

use JsonException;

/**
 * A parsed (not yet verified) JWS / JWT in compact serialization.
 */
final readonly class Jws
{
    /**
     * @param  array<string, mixed>  $header
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public array $header,
        public array $payload,
        public string $signingInput,
        public string $signature,
    ) {}

    /**
     * Exactly three base64url segments; header and payload must be JSON objects.
     *
     * @throws CryptoException
     */
    public static function parse(string $token): self
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3 || $segments[0] === '' || $segments[1] === '') {
            throw new CryptoException('A JWS must have exactly three segments.');
        }

        return new self(
            self::object(Base64Url::decode($segments[0])),
            self::object(Base64Url::decode($segments[1])),
            $segments[0].'.'.$segments[1],
            Base64Url::decode($segments[2]),
        );
    }

    public function alg(): ?string
    {
        return is_string($this->header['alg'] ?? null) ? $this->header['alg'] : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CryptoException
     */
    private static function object(string $json): array
    {
        if (! str_starts_with(ltrim($json), '{')) {
            throw new CryptoException('JWS segments must be JSON objects.');
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new CryptoException('JWS segments must be JSON objects.');
        }

        if (! is_array($decoded)) {
            throw new CryptoException('JWS segments must be JSON objects.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
