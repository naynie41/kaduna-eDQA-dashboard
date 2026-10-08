<?php

declare(strict_types=1);

namespace App\Domain\Scoring\DTOs;

use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use InvalidArgumentException;

/**
 * One scoring rule version's config (scoring_rule_versions.config, ARCHITECTURE.md §7):
 * bands, item weights, N/A policy and choice map. Validated on the way in: a config the
 * calculator cannot apply exactly is refused, never scored with a guess.
 *
 * item_weights are keyed "<dimension>.<item_code>" (e.g. "availability.summary_form") and apply
 * in every month slot. Unlisted items weigh 1. Weights must be positive.
 */
final readonly class RuleConfig
{
    public const NA_POLICIES = ['exclude'];

    public const OUTCOMES = ['pass', 'fail', 'na'];

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
        if (! ($bands['strong'] <= 100 && $bands['strong'] > $bands['acceptable'] && $bands['acceptable'] > $bands['review'] && $bands['review'] >= 0)) {
            throw new InvalidArgumentException('Bands must satisfy 100 ≥ strong > acceptable > review ≥ 0.');
        }
        if (! in_array($naPolicy, self::NA_POLICIES, true)) {
            throw new InvalidArgumentException("N/A policy '{$naPolicy}' is not supported.");
        }
        foreach ($choiceMap as $choice => $outcome) {
            if (! in_array($outcome, self::OUTCOMES, true)) {
                throw new InvalidArgumentException("Choice '{$choice}' maps to '{$outcome}', not pass, fail or na.");
            }
        }
        $dimensions = array_map(fn (Dimension $d): string => $d->value, Dimension::cases());
        foreach ($itemWeights as $key => $weight) {
            if (! in_array(explode('.', $key, 2)[0], $dimensions, true) || ! str_contains($key, '.')) {
                throw new InvalidArgumentException("Weight key '{$key}' must be '<dimension>.<item_code>'.");
            }
            if ($weight <= 0) {
                throw new InvalidArgumentException("Weight for '{$key}' must be positive.");
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
            throw new InvalidArgumentException('Rule config has no bands.');
        }

        return new self(
            bands: [
                'strong' => self::number($bands, 'strong', 'bands'),
                'acceptable' => self::number($bands, 'acceptable', 'bands'),
                'review' => self::number($bands, 'review', 'bands'),
            ],
            itemWeights: self::weights($config['item_weights'] ?? []),
            naPolicy: is_string($config['na_policy'] ?? null) ? $config['na_policy'] : throw new InvalidArgumentException('Rule config has no na_policy.'),
            choiceMap: self::choiceMap($config['choice_map'] ?? null),
        );
    }

    public function weightFor(string $dimension, string $itemCode): float
    {
        return $this->itemWeights["{$dimension}.{$itemCode}"] ?? 1.0;
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

    /** @param  array<array-key, mixed>  $values */
    private static function number(array $values, string $key, string $group): float
    {
        $value = $values[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : throw new InvalidArgumentException("Rule config {$group}.{$key} must be a number.");
    }

    /** @return array<string, float> */
    private static function weights(mixed $weights): array
    {
        if (! is_array($weights)) {
            throw new InvalidArgumentException('Rule config item_weights must be an object.');
        }

        $result = [];
        foreach ($weights as $key => $weight) {
            $result[(string) $key] = self::number($weights, (string) $key, 'item_weights');
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function choiceMap(mixed $map): array
    {
        if (! is_array($map) || $map === []) {
            throw new InvalidArgumentException('Rule config has no choice_map.');
        }

        $result = [];
        foreach ($map as $choice => $outcome) {
            $result[(string) $choice] = is_string($outcome) ? $outcome : throw new InvalidArgumentException("Choice '{$choice}' must map to a string.");
        }

        return $result;
    }
}
