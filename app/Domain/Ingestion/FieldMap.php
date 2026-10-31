<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Scoring\Enums\Dimension;

/**
 * A form's field map (config/edqa_field_maps.php, ARCHITECTURE.md §5.5): where each field sits in
 * a submission's answers (SubmissionEnvelope::$data), and how scored questions are named. Maps
 * are named ("xls-2026.1"), and each source says which map a form version uses, so one XLSForm
 * on two platforms shares one map though each labels the version its own way (D-63).
 *
 * for() returns null for a (source, version) pair with no entry, and never falls back to another
 * version's map (CLAUDE.md hard rule 12). A map may extend another: it lists only what differs,
 * plus 'renames' for scored questions it names differently ([name in this form => name in the
 * base]). Paths are dot paths through nested groups.
 *
 * A form that does not follow the contract's scored-question naming (the real KoboToolbox form,
 * D-65) is read through **item rules** instead: 'item_rules' names a set in
 * config/edqa_item_rules.php, each rule saying which question(s) make one item and how they score
 * (D-66–D-68). Such a map can also say where the visit's mode is ('visit_mode': monthly or
 * quarterly, D-70), when a submission is not a facility assessment ('out_of_scope', D-74), and that
 * the quarter is written q1–q4 ('round_quarter_format' => 'q', D-71).
 */
final readonly class FieldMap
{
    /** The dimension each scored-question prefix stands for. */
    private const PREFIXES = [
        'avail' => Dimension::Availability,
        'consist' => Dimension::Consistency,
        'valid' => Dimension::Validity,
    ];

    /** Keys that configure the map rather than name a field's path. */
    private const OPTIONS = ['scored_prefix_pattern', 'extends', 'renames', 'item_rules', 'visit_mode', 'out_of_scope', 'round_quarter_format'];

    /** How deep "extends" may go: a cycle or a long chain is a config error, not a map. */
    private const MAX_DEPTH = 8;

    /**
     * @param  array<string, string>  $paths  field => dot path
     * @param  array<string, string>  $renames  name in this form => name in the base form
     * @param  array<string, mixed>  $options  item_rules, visit_mode, out_of_scope, round_quarter_format
     */
    private function __construct(
        public string $source,
        public string $version,
        public string $name,
        private array $paths,
        private string $scoredPattern,
        private array $renames,
        private array $options = [],
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
        $options = [];
        foreach ($merged as $field => $path) {
            if (! is_string($field)) {
                continue;
            }
            if (in_array($field, self::OPTIONS, true)) {
                $options[$field] = $path;
            } elseif (is_string($path)) {
                $paths[$field] = $path;
            }
        }
        $pattern = is_string($merged['scored_prefix_pattern'] ?? null) ? $merged['scored_prefix_pattern'] : '/(?!)/';

        return new self($source, $version, $name, $paths, $pattern, $renames, $options);
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

    /**
     * The map's item rules (config/edqa_item_rules.php), or null for a map read by the scored-name
     * pattern. A rule set that is named but missing gives [], so nothing is scored and
     * ITEMS_INCOMPLETE holds the submission: never a fallback to the pattern.
     *
     * @return list<array{dimension: string, mode: string, slot: ?int, quarter: ?int, kind: string, code: string, paths: list<string>, service: ?string}>|null
     */
    public function itemRules(): ?array
    {
        $name = $this->options['item_rules'] ?? null;
        if (! is_string($name)) {
            return null;
        }
        $sets = config('edqa_item_rules');
        $rules = is_array($sets) && is_array($sets[$name] ?? null) ? $sets[$name] : [];

        /** @var list<array{dimension: string, mode: string, slot: ?int, quarter: ?int, kind: string, code: string, paths: list<string>, service: ?string}> */
        return array_values(array_filter($rules, is_array(...)));
    }

    /**
     * The visit's mode, 'monthly' or 'quarterly', from the map's visit_mode; null when the map has
     * none (every visit covers its round's three months) or the answer is neither.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function visitMode(array $data): ?string
    {
        $mode = $this->options['visit_mode'] ?? null;
        if (! is_array($mode) || ! is_string($mode['path'] ?? null)) {
            return null;
        }
        $value = self::get($data, $mode['path']);
        foreach (['monthly', 'quarterly'] as $kind) {
            if ($value !== null && $value === ($mode[$kind] ?? null)) {
                return $kind;
            }
        }

        return null;
    }

    /** Whether the map has a visit mode at all (a form with monthly and quarterly visits). */
    public function hasVisitMode(): bool
    {
        return is_array($this->options['visit_mode'] ?? null);
    }

    /**
     * Why the submission is not a facility assessment (D-74), the first of the map's out_of_scope
     * reasons whose answer matches; null when none does.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function outOfScope(array $data): ?string
    {
        foreach (is_array($this->options['out_of_scope'] ?? null) ? $this->options['out_of_scope'] : [] as $reason => $test) {
            if (is_string($reason) && is_array($test) && is_string($test['path'] ?? null) && self::get($data, $test['path']) === ($test['value'] ?? null)) {
                return $reason;
            }
        }

        return null;
    }

    /** How the form writes the quarter: 'q' for q1–q4, null for a number. */
    public function quarterFormat(): ?string
    {
        $format = $this->options['round_quarter_format'] ?? null;

        return is_string($format) ? $format : null;
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
