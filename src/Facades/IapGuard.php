<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \BijayX\IapGuard\Data\Purchase verify(\BijayX\IapGuard\Data\PurchaseProof|array<array-key, mixed> $proof)
 * @method static \BijayX\IapGuard\Data\ClaimResult claim(string|int $userId, \BijayX\IapGuard\Data\PurchaseProof|array<array-key, mixed> $proof)
 * @method static \BijayX\IapGuard\Data\NotificationResult handleAppleNotification(string|array<array-key, mixed> $body)
 * @method static \BijayX\IapGuard\Data\NotificationResult handleGoogleNotification(string|array<array-key, mixed> $body, string|null $authorization = null, string|null $verificationToken = null)
 * @method static list<\BijayX\IapGuard\Data\Purchase> entitlements(string|int $userId, \DateTimeInterface|null $now = null)
 * @method static bool isEntitled(\BijayX\IapGuard\Data\Purchase $purchase, \DateTimeInterface|null $now = null)
 * @method static int releaseUser(string|int $userId)
 * @method static string accountTokenFor(string|int $userId)
 * @method static \BijayX\IapGuard\Contracts\PurchaseStore store()
 *
 * @see \BijayX\IapGuard\IapGuard
 */
final class IapGuard extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \BijayX\IapGuard\IapGuard::class;
    }
}
