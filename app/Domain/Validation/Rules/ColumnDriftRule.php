<?php

declare(strict_types=1);

namespace App\Domain\Validation\Rules;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Rules\Concerns\BuildsFailures;
use App\Domain\Validation\ValidationContext;
use App\Support\Text\Normalise;
use Illuminate\Container\Attributes\Config;

/**
 * COLUMN_DRIFT: catches values that landed in the wrong ODK column (live report: Ward =
 * "Quarterly", Start = "FEBRUARY"). Start and end must be ISO 8601 dates; the ward must not be
 * a reserved word (config, compared trimmed and case-insensitively) or a bare number.
 *
 * One failure lists every shifted field, so the Data issues row shows the whole picture. A
 * missing ward is not drift and passes here.
 */
final class ColumnDriftRule implements ValidationRule
{
    use BuildsFailures;

    /** @var array<string, true> */
    private readonly array $reserved;

    /** @param  list<string>  $reservedWardWords */
    public function __construct(
        #[Config('edqa.validation.reserved_ward_words')] array $reservedWardWords,
    ) {
        $this->reserved = array_fill_keys(array_map(Normalise::name(...), $reservedWardWords), true);
    }

    public function code(): string
    {
        return 'COLUMN_DRIFT';
    }

    public function severity(): Severity
    {
        return Severity::Hard;
    }

    public function dependsOnParse(): bool
    {
        return true;
    }

    public function check(ParsedSubmission $submission, ValidationContext $context): array
    {
        $shifted = [];

        $ward = $submission->wardRef;
        if (Normalise::nullIfBlank($ward) !== null && (isset($this->reserved[Normalise::name((string) $ward)]) || Normalise::isNumeric((string) $ward))) {
            $shifted['ward'] = (string) $ward;
        }
        if (! Normalise::isIsoDate($submission->rawStart)) {
            $shifted['start'] = $submission->rawStart;
        }
        if (! Normalise::isIsoDate($submission->rawEnd)) {
            $shifted['end'] = $submission->rawEnd;
        }

        if ($shifted === []) {
            return [];
        }

        $values = [];
        foreach ($shifted as $field => $value) {
            $values[] = $this->text('value', ['field' => $this->text("fields.{$field}"), 'value' => $value]);
        }

        return [$this->fail('detail', ['values' => implode(', ', $values)], implode(',', array_keys($shifted)))];
    }
}
