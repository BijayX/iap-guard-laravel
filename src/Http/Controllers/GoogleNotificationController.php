<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Http\Controllers;

use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\IapGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST {prefix}/google — Play real-time developer notifications via Pub/Sub push.
 */
final class GoogleNotificationController
{
    use RespondsToNotifications;

    public function __invoke(Request $request, IapGuard $guard): JsonResponse
    {
        $token = $request->query('token');

        return $this->respond(static fn (): NotificationResult => $guard->handleGoogleNotification(
            $request->getContent(),
            $request->header('Authorization'),
            is_string($token) ? $token : null,
        ));
    }
}
