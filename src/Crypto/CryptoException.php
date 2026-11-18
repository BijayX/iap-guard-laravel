<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Crypto;

use RuntimeException;

/**
 * Low-level failure inside the crypto helpers. Callers translate it into a
 * VerificationException(INVALID_SIGNATURE) or ConfigurationException.
 *
 * @internal
 */
final class CryptoException extends RuntimeException {}
