<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The store could not be reached (NETWORK_ERROR) or did not answer in time (TIMEOUT).
 *
 * The underlying transport exception is not chained: its message contains the request URL, and
 * Play Developer API URLs contain purchase tokens. details()['reason'] keeps it with URLs removed.
 */
final class NetworkException extends IapException
{
    public const NETWORK_ERROR = 'NETWORK_ERROR';

    public const TIMEOUT = 'TIMEOUT';

    public static function timeout(Platform $platform, int $timeoutMs, string $reason = ''): self
    {
        return new self("The request to {$platform->value} timed out after {$timeoutMs} ms.", self::TIMEOUT, $platform, ['timeout_ms' => $timeoutMs, 'reason' => self::sanitize($reason)]);
    }

    public static function connectionFailed(Platform $platform, string $reason = ''): self
    {
        return new self("Could not connect to {$platform->value}.", self::NETWORK_ERROR, $platform, ['reason' => self::sanitize($reason)]);
    }

    private static function sanitize(string $reason): string
    {
        return mb_substr(trim((string) preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[url]', $reason)), 0, 300);
    }

    public function isTimeout(): bool
    {
        return $this->errorCode() === self::TIMEOUT;
    }
}
