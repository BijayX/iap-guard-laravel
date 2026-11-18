<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Http\Controllers;

use BijayX\IapGuard\Data\NotificationResult;
use BijayX\IapGuard\Exceptions\IapException;
use BijayX\IapGuard\Exceptions\PolicyException;
use BijayX\IapGuard\Exceptions\ValidationException;
use BijayX\IapGuard\Exceptions\VerificationException;
use Closure;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Status codes for webhooks (SPEC §8): 200 for processed / duplicate / stale, 400 for requests
 * that will never succeed (forged, malformed, other app, rejected by policy) so the store does
 * not retry them, 500 for everything else (store API, network, database, configuration) so it does.
 *
 * @internal
 */
trait RespondsToNotifications
{
    /**
     * @param  Closure(): NotificationResult  $handle
     */
    protected function respond(Closure $handle): JsonResponse
    {
        try {
            $result = $handle();
        } catch (VerificationException|PolicyException|ValidationException $e) {
            report($e);

            return new JsonResponse(['error' => $e->errorCode()], 400);
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse(['error' => $e instanceof IapException ? $e->errorCode() : 'SERVER_ERROR'], 500);
        }

        return new JsonResponse([
            'id' => $result->id,
            'event' => $result->event->value,
            'duplicate' => $result->duplicate,
            'stale' => $result->stale,
        ]);
    }
}
