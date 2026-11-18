<?php

declare(strict_types=1);

namespace BijayX\IapGuard\Support;

use BijayX\IapGuard\Enums\Platform;
use BijayX\IapGuard\Exceptions\ConfigurationException;

/**
 * Typed access to a config array with helpful ConfigurationExceptions.
 *
 * @internal
 */
final readonly class ConfigReader
{
    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(private array $config, private string $prefix, private ?Platform $platform = null) {}

    public function get(string $key): mixed
    {
        return data_get($this->config, $key);
    }

    public function requiredString(string $key): string
    {
        return $this->string($key) ?? throw ConfigurationException::missing($this->path($key), $this->platform);
    }

    public function string(string $key): ?string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw ConfigurationException::invalid($this->path($key), 'a string', $this->platform);
        }

        return trim($value);
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($bool === null) {
            throw ConfigurationException::invalid($this->path($key), 'a boolean', $this->platform);
        }

        return $bool;
    }

    public function int(string $key, int $default, int $min = 0): int
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < $min) {
            throw ConfigurationException::invalid($this->path($key), "an integer of at least {$min}", $this->platform);
        }

        return $value;
    }

    /**
     * A list of strings; also accepts a comma-separated string (handy for env values).
     *
     * @return list<string>|null
     */
    public function stringList(string $key): ?array
    {
        $value = $this->get($key);

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            throw ConfigurationException::invalid($this->path($key), 'a list of strings', $this->platform);
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_scalar($item)) {
                throw ConfigurationException::invalid($this->path($key), 'a list of strings', $this->platform);
            }

            $item = trim((string) $item);

            if ($item !== '') {
                $list[] = $item;
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * Inline content, or the contents of a file when the value is a readable path.
     */
    public function contentsOrPath(string $key): ?string
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ConfigurationException::invalid($this->path($key), 'a string or a file path', $this->platform);
        }

        return self::readMaybePath($value) ?? throw ConfigurationException::invalid($this->path($key), 'a string or a readable file path', $this->platform);
    }

    public static function readMaybePath(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $looksInline = str_starts_with($trimmed, '-----BEGIN') || str_starts_with($trimmed, '{') || str_contains($trimmed, "\n") || str_contains($trimmed, "\0");

        if (! $looksInline && strlen($trimmed) < 4096 && is_file($trimmed)) {
            $contents = @file_get_contents($trimmed);

            return $contents === false ? null : $contents;
        }

        return $value;
    }

    public function path(string $key): string
    {
        return $this->prefix === '' ? $key : $this->prefix.'.'.$key;
    }
}
