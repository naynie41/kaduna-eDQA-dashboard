<?php

declare(strict_types=1);

namespace App\Domain\Scoring\DTOs;

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Exceptions\InvalidRuleConfig;
use App\Domain\Scoring\Models\ScoringRuleVersion;

/**
 * One scoring rule version's config (scoring_rule_versions.config, ARCHITECTURE.md §7):
 * bands, item weights, N/A policy and choice map. Validated on the way in: a config the
 * calculator cannot apply exactly is refused (InvalidRuleConfig), never scored with a guess.
 *
 * - bands: lower bounds, 100 ≥ strong > acceptable > review ≥ 0.
 * - item_weights: keyed "<dimension>.<item_code>", the same in every month slot; unlisted items
 *   weigh 1; every weight > 0 (D-27).
 * - choice_map: raw ODK choice => pass | fail | na, leading to all three outcomes.
 * - na_policy: one of NA_POLICIES.
 */
final readonly class RuleConfig
{
    public const NA_POLICIES = ['exclude'];

    public const OUTCOMES = ['pass', 'fail', 'na'];

    public const KEYS = ['bands', 'item_weights', 'na_policy', 'choice_map'];

    /**
     * @param  array{strong: float, acceptable: float, review: float}  $bands  lower bounds
     * @param  array<string, float>  $itemWeights
     * @param  array<string, string>  $choiceMap  raw ODK choice => pass | fail | na
     */
    public function __construct(
        public array $bands,
        public array $itemWeights,
        public string $naPolicy,
        public array $choiceMap,
    ) {
        foreach ($bands as $bound) {
            if ($bound < 0 || $bound > 100) {
                throw InvalidRuleConfig::because('bands_out_of_range');
            }
        }
        if (! ($bands['strong'] > $bands['acceptable'] && $bands['acceptable'] > $bands['review'])) {
            throw InvalidRuleConfig::because('bands_not_descending');
        }
        if (! in_array($naPolicy, self::NA_POLICIES, true)) {
            throw InvalidRuleConfig::because('na_policy_unknown', ['policy' => $naPolicy]);
        }
        foreach ($choiceMap as $choice => $outcome) {
            if (! in_array($outcome, self::OUTCOMES, true)) {
                throw InvalidRuleConfig::because('choice_outcome_invalid', ['choice' => $choice]);
            }
        }
        if (array_diff(self::OUTCOMES, $choiceMap) !== []) {
            throw InvalidRuleConfig::because('choice_map_incomplete');
        }
        $dimensions = array_map(fn (Dimension $d): string => $d->value, Dimension::cases());
        foreach ($itemWeights as $key => $weight) {
            $parts = explode('.', $key, 2);
            if (count($parts) !== 2 || $parts[1] === '' || ! in_array($parts[0], $dimensions, true)) {
                throw InvalidRuleConfig::because('weight_key_invalid', ['key' => $key]);
            }
            if ($weight <= 0) {
                throw InvalidRuleConfig::because('weight_not_positive', ['key' => $key]);
            }
        }
    }

    public static function fromVersion(ScoringRuleVersion $version): self
    {
        return self::fromArray($version->config);
    }

    /** @param  array<array-key, mixed>  $config */
    public static function fromArray(array $config): self
    {
        $bands = $config['bands'] ?? null;
        if (! is_array($bands)) {
            throw InvalidRuleConfig::because('config_missing', ['part' => 'bands']);
        }
        $naPolicy = $config['na_policy'] ?? null;
        if (! is_string($naPolicy)) {
            throw InvalidRuleConfig::because('config_missing', ['part' => 'na_policy']);
        }

        return new self(
            bands: [
                'strong' => self::band($bands, 'strong'),
                'acceptable' => self::band($bands, 'acceptable'),
                'review' => self::band($bands, 'review'),
            ],
            itemWeights: self::weights($config['item_weights'] ?? []),
            naPolicy: $naPolicy,
            choiceMap: self::choiceMap($config['choice_map'] ?? null),
        );
    }

    public function weightFor(string $dimension, string $itemCode): float
    {
        return $this->itemWeights["{$dimension}.{$itemCode}"] ?? 1.0;
    }

    /**
     * The outcome for a stored answer (Q-25): a raw choice in choice_map is mapped (the
     * factories and demo seed store raw yes/no/na); otherwise an outcome (pass/fail/na, as
     * processing stores it) passes through. The map wins when both match ("na" is both a choice
     * and an outcome), so a version that remaps N/A applies to every stored N/A. An answer that
     * is neither is refused, never guessed.
     */
    public function outcome(string $stored): string
    {
        if (isset($this->choiceMap[$stored])) {
            return $this->choiceMap[$stored];
        }

        return in_array($stored, self::OUTCOMES, true)
            ? $stored
            : throw InvalidRuleConfig::because('item_value_unmapped', ['value' => $stored]);
    }

    /** @return array{bands: array{strong: float, acceptable: float, review: float}, item_weights: array<string, float>, na_policy: string, choice_map: array<string, string>} */
    public function toArray(): array
    {
        return [
            'bands' => $this->bands,
            'item_weights' => $this->itemWeights,
            'na_policy' => $this->naPolicy,
            'choice_map' => $this->choiceMap,
        ];
    }

    /** @param  array<array-key, mixed>  $bands */
    private static function band(array $bands, string $band): float
    {
        $value = $bands[$band] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : throw InvalidRuleConfig::because('band_missing', ['band' => $band]);
    }

    /** @return array<string, float> */
    private static function weights(mixed $weights): array
    {
        if (! is_array($weights)) {
            throw InvalidRuleConfig::because('config_missing', ['part' => 'item_weights']);
        }

        $result = [];
        foreach ($weights as $key => $weight) {
            $result[(string) $key] = is_int($weight) || is_float($weight)
                ? (float) $weight
                : throw InvalidRuleConfig::because('weight_not_positive', ['key' => (string) $key]);
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function choiceMap(mixed $map): array
    {
        if (! is_array($map) || $map === []) {
            throw InvalidRuleConfig::because('config_missing', ['part' => 'choice_map']);
        }

        $result = [];
        foreach ($map as $choice => $outcome) {
            $result[(string) $choice] = is_string($outcome)
                ? $outcome
                : throw InvalidRuleConfig::because('choice_outcome_invalid', ['choice' => (string) $choice]);
        }

        return $result;
    }
}
