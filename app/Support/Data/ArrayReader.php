<?php

declare(strict_types=1);

namespace App\Support\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Typed reads from a decoded JSON array (fixtures, stored DTOs). A missing key or a value of the
 * wrong type throws, naming the key, instead of turning into a silent default.
 */
final readonly class ArrayReader
{
    /** @param  array<array-key, mixed>  $data */
    public function __construct(
        private array $data,
        private string $context,
    ) {}

    public function string(string $key): string
    {
        $value = $this->required($key);

        return is_string($value) ? $value : throw $this->invalid($key, 'a string');
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->required($key);

        return $value === null || is_string($value) ? $value : throw $this->invalid($key, 'a string or null');
    }

    public function int(string $key): int
    {
        $value = $this->required($key);

        return is_int($value) ? $value : throw $this->invalid($key, 'an integer');
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->required($key);

        return $value === null || is_int($value) ? $value : throw $this->invalid($key, 'an integer or null');
    }

    public function bool(string $key): bool
    {
        $value = $this->required($key);

        return is_bool($value) ? $value : throw $this->invalid($key, 'true or false');
    }

    public function date(string $key): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string($key));
    }

    public function nullableDate(string $key): ?CarbonImmutable
    {
        $value = $this->nullableString($key);

        return $value === null ? null : CarbonImmutable::parse($value);
    }

    /** @return list<array<string, mixed>> */
    public function listOfArrays(string $key): array
    {
        $value = $this->required($key);
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->invalid($key, 'a list');
        }

        $rows = [];
        foreach ($value as $row) {
            $rows[] = is_array($row) ? $row : throw $this->invalid($key, 'a list of objects');
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    /**
     * Numbers keyed by name; JSON may decode a whole number as int, so both are accepted.
     *
     * @return array<string, float>|null
     */
    public function nullableFloatMap(string $key): ?array
    {
        $value = $this->required($key);
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw $this->invalid($key, 'an object of numbers or null');
        }

        $map = [];
        foreach ($value as $name => $number) {
            if (! is_string($name) || ! (is_int($number) || is_float($number))) {
                throw $this->invalid($key, 'an object of numbers or null');
            }
            $map[$name] = (float) $number;
        }

        return $map;
    }

    private function required(string $key): mixed
    {
        if (! array_key_exists($key, $this->data)) {
            throw new InvalidArgumentException("{$this->context}: missing key \"{$key}\".");
        }

        return $this->data[$key];
    }

    private function invalid(string $key, string $expected): InvalidArgumentException
    {
        return new InvalidArgumentException("{$this->context}: \"{$key}\" must be {$expected}.");
    }
}
