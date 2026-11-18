<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Date helpers. "Now" always comes from Laravel's Date facade so Carbon::setTestNow() works.
 *
 * @internal
 */
final class Time
{
    public static function now(): CarbonImmutable
    {
        // Truncated to milliseconds: every stored date has millisecond precision.
        return self::fromMs(Date::now()->getTimestampMs());
    }

    public static function fromMs(int|float $ms): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampMs($ms, 'UTC');
    }

    public static function toMs(DateTimeInterface $date): int
    {
        return (int) CarbonImmutable::instance($date)->getTimestampMs();
    }

    /**
     * ISO-8601 in UTC with milliseconds, e.g. 2026-11-18T12:00:00.000Z (same as JavaScript's toISOString()).
     */
    public static function iso(DateTimeInterface $date): string
    {
        return CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function isoOrNull(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : self::iso($date);
    }

    /**
     * Parse an ISO-8601 / RFC 3339 string (Google) or a millisecond timestamp (Apple). Null when absent or invalid.
     */
    public static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc();
        }

        if (is_int($value) || is_float($value)) {
            return self::fromMs($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return self::fromMs((int) $value);
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Format for DATETIME(3) columns (always UTC).
     */
    public static function toDatabase(?DateTimeInterface $date): ?string
    {
        return $date === null ? null : CarbonImmutable::instance($date)->utc()->format('Y-m-d H:i:s.v');
    }

    public static function fromDatabase(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        }

        return self::parse($value);
    }

    public static function max(DateTimeInterface $a, DateTimeInterface $b): CarbonImmutable
    {
        return self::toMs($a) >= self::toMs($b) ? CarbonImmutable::instance($a)->utc() : CarbonImmutable::instance($b)->utc();
    }
}
