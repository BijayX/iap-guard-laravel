<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The store API answered with an unexpected status (429, 5xx, unexpected 4xx) or a malformed body.
 */
final class StoreApiException extends IapException
{
    public const CODE = 'STORE_API_ERROR';

    /**
     * @param  array<mixed>|string|null  $responseBody
     */
    public function __construct(
        string $message,
        Platform $platform,
        private readonly ?int $statusCode = null,
        private readonly array|string|null $responseBody = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message, self::CODE, $platform, [
            'status_code' => $statusCode,
            'response_body' => $responseBody,
            'retryable' => $retryable,
        ]);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * @return array<mixed>|string|null
     */
    public function responseBody(): array|string|null
    {
        return $this->responseBody;
    }

    /**
     * Whether trying again later may succeed (429 / 5xx).
     */
    public function retryable(): bool
    {
        return $this->retryable;
    }
}
