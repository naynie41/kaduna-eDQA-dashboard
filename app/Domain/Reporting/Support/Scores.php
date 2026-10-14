<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * Row value → ScoreValue. Postgres returns averages as numeric strings; null means no data.
 * Mapping only: no arithmetic here.
 */
final class Scores
{
    public static function of(mixed $value, ?RuleConfig $rules): ScoreValue
    {
        return is_numeric($value) ? ScoreValue::fromNullable((float) $value, $rules) : ScoreValue::none();
    }

    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
