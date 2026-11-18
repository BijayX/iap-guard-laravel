<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Enums;

/**
 * Apple Sandbox purchases and Google license-tester purchases are both "sandbox".
 */
enum Environment: string
{
    case Production = 'production';
    case Sandbox = 'sandbox';
}
