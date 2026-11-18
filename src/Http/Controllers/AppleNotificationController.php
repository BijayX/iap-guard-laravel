<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Http\Controllers;

use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\IapGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {prefix}/apple — App Store Server Notifications V2.
 */
final class AppleNotificationController
{
    use RespondsToNotifications;

    public function __invoke(Request $request, IapGuard $guard): JsonResponse
    {
        return $this->respond(static fn (): NotificationResult => $guard->handleAppleNotification($request->getContent()));
    }
}
