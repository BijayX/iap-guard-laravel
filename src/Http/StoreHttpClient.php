<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Http;

use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\NetworkException;
use BijayX\IapGuard\IapGuard;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;

/**
 * Thin wrapper around Laravel's HTTP client (so Http::fake() works).
 *
 * - Applies the configured timeout and the package User-Agent.
 * - When the caller marks a request retryable, retries connection failures, 429 and 5xx with
 *   exponential backoff (delay * 2^attempt), honouring Retry-After when it is at most 10 seconds.
 * - Returns every HTTP response (including 4xx/5xx) to the caller for store-specific mapping.
 *
 * Request headers (bearer tokens) and bodies (assertions) are never logged or put into exceptions.
 */
final readonly class StoreHttpClient
{
    public const MAX_RETRY_AFTER_SECONDS = 10;

    public function __construct(
        private Factory $http,
        private Platform $platform,
        private int $timeoutMs = 15000,
        private int $retries = 2,
        private int $retryDelayMs = 200,
    ) {}

    /**
     * @param  Closure(PendingRequest): Response  $call
     *
     * @throws NetworkException
     */
    public function send(Closure $call, bool $retryable = true): Response
    {
        $maxAttempts = $retryable ? max(0, $this->retries) + 1 : 1;

        for ($attempt = 0; ; $attempt++) {
            $isLastAttempt = $attempt + 1 >= $maxAttempts;

            try {
                $response = $call($this->request());
            } catch (ConnectionException $e) {
                if ($isLastAttempt) {
                    throw $this->networkException($e);
                }

                $this->backoff($attempt, null);

                continue;
            }

            if ($isLastAttempt || ! self::isRetryable($response)) {
                return $response;
            }

            $this->backoff($attempt, $response);
        }
    }

    public static function isRetryable(Response $response): bool
    {
        return $response->status() === 429 || $response->serverError();
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->withUserAgent('iap-guard-laravel/'.IapGuard::VERSION)
            ->withOptions([
                'timeout' => $this->timeoutMs / 1000,
                'connect_timeout' => min($this->timeoutMs, 10000) / 1000,
            ]);
    }

    private function backoff(int $attempt, ?Response $response): void
    {
        $retryAfter = $response?->header('Retry-After');

        if (is_string($retryAfter) && preg_match('/^\d+$/', trim($retryAfter)) === 1 && (int) $retryAfter <= self::MAX_RETRY_AFTER_SECONDS) {
            Sleep::for((int) $retryAfter)->seconds();

            return;
        }

        Sleep::for($this->retryDelayMs * (2 ** $attempt))->milliseconds();
    }

    private function networkException(ConnectionException $e): NetworkException
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return NetworkException::timeout($this->platform, $this->timeoutMs, $e->getMessage());
        }

        return NetworkException::connectionFailed($this->platform, $e->getMessage());
    }
}
