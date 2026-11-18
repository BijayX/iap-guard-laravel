<?php

declare(strict_types=1);

use BijayX\IapGuard\Http\Controllers\AppleNotificationController;
use BijayX\IapGuard\Http\Controllers\GoogleNotificationController;
use Illuminate\Support\Facades\Route;

$prefix = config('iap-guard.webhooks.prefix', 'iap/webhooks');
$middleware = config('iap-guard.webhooks.middleware', []);

Route::prefix(is_string($prefix) ? trim($prefix, '/') : 'iap/webhooks')
    ->middleware(is_array($middleware) ? $middleware : [])
    ->name('iap-guard.webhooks.')
    ->group(function (): void {
        Route::post('apple', AppleNotificationController::class)->name('apple');
        Route::post('google', GoogleNotificationController::class)->name('google');
    });
