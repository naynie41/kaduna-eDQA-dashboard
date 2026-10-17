<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Scoring\Enums\Dimension;

/**
 * One form version's field map (config/edqa_field_maps.php, ARCHITECTURE.md §5.5): where each
 * field sits in an ODK Central OData submission, and how scored questions are named.
 *
 * resolve() merges an additive version over the default; a version with no entry gives null and
 * never falls back to the default (CLAUDE.md hard rule 12). Paths are dot paths through nested
 * groups. Scored questions are found by scored_prefix_pattern after the version's renames
 * ([name in this version => name in the default]).
 */
final readonly class FieldMap
{
    /** The dimension each scored-question prefix stands for. */
    private const PREFIXES = [
        'avail' => Dimension::Availability,
        'consist' => Dimension::Consistency,
        'valid' => Dimension::Validity,
    ];

    /**
     * @param  array<string, string>  $paths  field => dot path
     * @param  array<string, string>  $renames  name in this version => name in the default
     */
    private function __construct(
        public string $version,
        private array $paths,
        private string $scoredPattern,
        private array $renames,
    ) {}

    public static function resolve(?string $version): ?self
    {
        $config = config('edqa_field_maps');

        return self::fromConfig(is_array($config) ? $config : [], $version);
    }

    /** @param  array<array-key, mixed>  $config  the shape of config/edqa_field_maps.php */
    public static function fromConfig(array $config, ?string $version): ?self
    {
        $versions = is_array($config['versions'] ?? null) ? $config['versions'] : [];
        $default = is_string($config['default'] ?? null) ? $config['default'] : null;
        // Exact keys only: '2026.10' or ' 2026.1' is not '2026.1'.
        if ($version === null || $default === null || ! array_key_exists($version, $versions) || ! is_array($versions[$default] ?? null)) {
            return null;
        }

        $own = is_array($versions[$version]) ? $versions[$version] : [];
        $merged = $version === $default ? $versions[$default] : array_replace($versions[$default], $own);

        $paths = [];
        foreach ($merged as $field => $path) {
            if (is_string($field) && is_string($path) && ! in_array($field, ['scored_prefix_pattern'], true)) {
                $paths[$field] = $path;
            }
        }
        $renames = [];
        foreach (is_array($own['renames'] ?? null) ? $own['renames'] : [] as $from => $to) {
            if (is_string($from) && is_string($to)) {
                $renames[$from] = $to;
            }
        }
        $pattern = is_string($merged['scored_prefix_pattern'] ?? null) ? $merged['scored_prefix_pattern'] : '/(?!)/';

        return new self($version, $paths, $pattern, $renames);
    }

    /** The dot path of a field, null when the map has none. */
    public function path(string $field): ?string
    {
        return $this->paths[$field] ?? null;
    }

    /**
     * A field's raw value in the submission; null when it is missing anywhere along the path.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function value(array $payload, string $field): mixed
    {
        $path = $this->path($field);

        return $path === null ? null : self::get($payload, $path);
    }

    /**
     * The value at a dot path; null when a step is missing or is not a group.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function get(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $step) {
            if (! is_array($value) || ! array_key_exists($step, $value)) {
                return null;
            }
            $value = $value[$step];
        }

        return $value;
    }

    /** A question's name in the default form. */
    public function canonicalName(string $name): string
    {
        return $this->renames[$name] ?? $name;
    }

    /** The reverse: what this version calls a default-form question (for the mock generator). */
    public function questionName(string $canonical): string
    {
        $name = array_search($canonical, $this->renames, true);

        return is_string($name) ? $name : $canonical;
    }

    /**
     * A scored question's dimension, month slot, item code and default-form name; null when the
     * name (after renames) is not a scored question.
     *
     * @return array{0: Dimension, 1: int, 2: string, 3: string}|null
     */
    public function scored(string $name): ?array
    {
        $canonical = $this->canonicalName($name);
        if (preg_match($this->scoredPattern, $canonical, $match) !== 1 || ! isset($match[1], $match[2], $match[3])) {
            return null;
        }
        $dimension = self::PREFIXES[$match[1]] ?? null;
        $slot = (int) $match[2];

        return $dimension === null || $slot < 1 || $slot > 3 ? null : [$dimension, $slot, $match[3], $canonical];
    }
}
