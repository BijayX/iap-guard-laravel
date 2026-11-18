<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Exceptions;

use BijayX\IapGuard\Enums\Platform;

/**
 * The input passed to verify() / claim() is malformed.
 */
final class ValidationException extends IapException
{
    public const CODE = 'VALIDATION_ERROR';

    /**
     * @param  array<string, list<string>>  $fields
     */
    public function __construct(string $message, private readonly array $fields = [], ?Platform $platform = null)
    {
        parent::__construct($message, self::CODE, $platform, ['fields' => $fields]);
    }

    public static function forField(string $field, string $message, ?Platform $platform = null): self
    {
        return new self("{$field}: {$message}", [$field => [$message]], $platform);
    }

    /**
     * @return array<string, list<string>>
     */
    public function fields(): array
    {
        return $this->fields;
    }
}
