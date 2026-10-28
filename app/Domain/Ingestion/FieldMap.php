<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Scoring\Enums\Dimension;

/**
 * A form's field map (config/edqa_field_maps.php, ARCHITECTURE.md §5.5): where each field sits in
 * a submission's answers (SubmissionEnvelope::$data), and how scored questions are named. Maps
 * are named ("xls-2026.1"), and each source says which map a form version uses, so one XLSForm
 * on two platforms shares one map though each labels the version its own way (D-25 in the new
 * numbering).
 *
 * for() returns null for a (source, version) pair with no entry, and never falls back to another
 * version's map (CLAUDE.md hard rule 12). A map may extend another: it lists only what differs,
 * plus 'renames' for scored questions it names differently ([name in this form => name in the
 * base]). Paths are dot paths through nested groups.
 */
final readonly class FieldMap
{
    /** The dimension each scored-question prefix stands for. */
    private const PREFIXES = [
        'avail' => Dimension::Availability,
        'consist' => Dimension::Consistency,
        'valid' => Dimension::Validity,
    ];

    /** How deep "extends" may go: a cycle or a long chain is a config error, not a map. */
    private const MAX_DEPTH = 8;

    /**
     * @param  array<string, string>  $paths  field => dot path
     * @param  array<string, string>  $renames  name in this form => name in the base form
     */
    private function __construct(
        public string $source,
        public string $version,
        public string $name,
        private array $paths,
        private string $scoredPattern,
        private array $renames,
    ) {}

    public static function for(string $source, ?string $version): ?self
    {
        $config = config('edqa_field_maps');

        return self::fromConfig(is_array($config) ? $config : [], $source, $version);
    }

    /** @param  array<array-key, mixed>  $config  the shape of config/edqa_field_maps.php */
    public static function fromConfig(array $config, string $source, ?string $version): ?self
    {
        $sources = is_array($config['sources'] ?? null) ? $config['sources'] : [];
        $versions = is_array($sources[$source] ?? null) ? $sources[$source] : [];
        // Exact keys only: '2026.10' or ' 2026.1' is not '2026.1'.
        if ($version === null || ! array_key_exists($version, $versions) || ! is_string($versions[$version])) {
            return null;
        }
        $name = $versions[$version];
        $chain = self::chain(is_array($config['maps'] ?? null) ? $config['maps'] : [], $name);
        if ($chain === null) {
            return null;
        }

        $merged = [];
        $renames = [];
        foreach (array_reverse($chain) as $map) { // the base first, then each map over it
            $merged = array_replace($merged, $map);
            foreach (is_array($map['renames'] ?? null) ? $map['renames'] : [] as $from => $to) {
                if (is_string($from) && is_string($to)) {
                    $renames[$from] = $to;
                }
            }
        }

        $paths = [];
        foreach ($merged as $field => $path) {
            if (is_string($field) && is_string($path) && ! in_array($field, ['scored_prefix_pattern', 'extends'], true)) {
                $paths[$field] = $path;
            }
        }
        $pattern = is_string($merged['scored_prefix_pattern'] ?? null) ? $merged['scored_prefix_pattern'] : '/(?!)/';

        return new self($source, $version, $name, $paths, $pattern, $renames);
    }

    /**
     * The named map and every map it extends, nearest first; null when a name is missing, a map
     * is not an array, or the chain loops or runs too deep.
     *
     * @param  array<array-key, mixed>  $maps
     * @return list<array<array-key, mixed>>|null
     */
    private static function chain(array $maps, string $name): ?array
    {
        $chain = [];
        $seen = [];
        while (true) {
            if (isset($seen[$name]) || count($chain) >= self::MAX_DEPTH || ! is_array($maps[$name] ?? null)) {
                return null;
            }
            $seen[$name] = true;
            $chain[] = $maps[$name];
            $parent = $maps[$name]['extends'] ?? null;
            if ($parent === null) {
                return $chain;
            }
            if (! is_string($parent)) {
                return null;
            }
            $name = $parent;
        }
    }

    /** The dot path of a field, null when the map has none. */
    public function path(string $field): ?string
    {
        return $this->paths[$field] ?? null;
    }

    /**
     * A field's raw value in the answers; null when it is missing anywhere along the path.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function value(array $data, string $field): mixed
    {
        $path = $this->path($field);

        return $path === null ? null : self::get($data, $path);
    }

    /**
     * The value at a dot path; null when a step is missing or is not a group.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function get(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $step) {
            if (! is_array($value) || ! array_key_exists($step, $value)) {
                return null;
            }
            $value = $value[$step];
        }

        return $value;
    }

    /** A question's name in the base form. */
    public function canonicalName(string $name): string
    {
        return $this->renames[$name] ?? $name;
    }

    /** The reverse: what this form calls a base-form question (for the mock generator). */
    public function questionName(string $canonical): string
    {
        $name = array_search($canonical, $this->renames, true);

        return is_string($name) ? $name : $canonical;
    }

    /**
     * A scored question's dimension, month slot, item code and base-form name; null when the
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

    /**
     * Every (source, version) pair with a map entry, for UNKNOWN_FORM_VERSION.
     *
     * @return array<string, list<string>> source => versions
     */
    public static function known(): array
    {
        $sources = config('edqa_field_maps.sources');
        $known = [];
        foreach (is_array($sources) ? $sources : [] as $source => $versions) {
            $known[(string) $source] = is_array($versions) ? array_map(strval(...), array_keys($versions)) : [];
        }

        return $known;
    }
}
