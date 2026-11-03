<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The dashboard filters (ARCHITECTURE.md §8, §9.1): year, the Duration (a quarter, or one month,
 * D-75), lga (code), level, owner. Built from the query string, so any view is a link; toQuery()
 * turns it back into one for links and exports. Every one is optional; a quarter or a month needs
 * a year. A month (1–12) also sets its quarter, so rounds are chosen as for the quarter, and
 * monthSlot() tells the figures to read that month only.
 */
final readonly class ReportFilter
{
    public const KEYS = ['year', 'quarter', 'month', 'lga', 'level', 'owner'];

    private const LGA_CODES_CACHE = 'edqa:reporting:lga_codes';

    public function __construct(
        public ?int $year = null,
        public ?int $quarter = null,
        public ?string $lga = null,
        public ?FacilityLevel $level = null,
        public ?Ownership $owner = null,
        public ?int $month = null,
    ) {}

    /** The month's slot in its quarter (1–3), or null for a whole quarter (D-75). */
    public function monthSlot(): ?int
    {
        return $this->month === null ? null : (($this->month - 1) % 3) + 1;
    }

    /** The quarter a month falls in. */
    public static function quarterOf(int $month): int
    {
        return intdiv($month - 1, 3) + 1;
    }

    /** The same filter for the whole quarter: what the quarterly trend reads. */
    public function withoutMonth(): self
    {
        return new self($this->year, $this->quarter, $this->lga, $this->level, $this->owner);
    }

    /**
     * The dashboard's reading of a URL: each filter is kept only if it can be chosen in the filter
     * bar, and anything else falls back to "all" rather than erroring (a stale bookmark still
     * opens). A year needs a round; a quarter needs its year and a round in that quarter; an LGA
     * must be one of the 23.
     *
     * @param  array<string, mixed>  $query
     * @param  list<array{year: int, quarter: int}>  $rounds  every round there is
     * @param  list<string>  $lgaCodes
     */
    public static function resolve(array $query, array $rounds, array $lgaCodes): self
    {
        $int = fn (string $key): ?int => is_string($query[$key] ?? null) && ctype_digit($query[$key]) ? (int) $query[$key] : null;
        $string = fn (string $key): ?string => is_string($query[$key] ?? null) ? $query[$key] : null;

        $year = $int('year');
        if ($year !== null && ! in_array($year, array_column($rounds, 'year'), true)) {
            $year = null;
        }
        // A month, when given, decides the quarter (D-75); either needs a round in that quarter.
        $month = $int('month');
        $month = $month !== null && $month >= 1 && $month <= 12 ? $month : null;
        $quarter = $month === null ? $int('quarter') : self::quarterOf($month);
        if ($year === null || ! in_array(['year' => $year, 'quarter' => $quarter], $rounds, true)) {
            $quarter = null;
            $month = null;
        }
        $lga = $string('lga');

        return new self(
            year: $year,
            quarter: $quarter,
            lga: $lga !== null && in_array($lga, $lgaCodes, true) ? $lga : null,
            level: FacilityLevel::tryFrom((string) $string('level')),
            owner: Ownership::tryFrom((string) $string('owner')),
            month: $month,
        );
    }

    /** The same filter across every round: for trends, which show all rounds whatever is chosen. */
    public function withoutRound(): self
    {
        return new self(lga: $this->lga, level: $this->level, owner: $this->owner);
    }

    /** The same filter for one owner type: the Visuals Overall / Public / Private view. */
    public function withOwner(?Ownership $owner): self
    {
        return new self($this->year, $this->quarter, $this->lga, $this->level, $owner, $this->month);
    }

    /**
     * Every filter key, null when not set: the shape the filter bar reads.
     *
     * @return array{year: ?int, quarter: ?int, month: ?int, lga: ?string, level: ?string, owner: ?string}
     */
    public function toProps(): array
    {
        return [
            'year' => $this->year,
            'quarter' => $this->quarter,
            'month' => $this->month,
            'lga' => $this->lga,
            'level' => $this->level?->value,
            'owner' => $this->owner?->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $query  request query parameters; unknown keys are ignored
     *
     * @throws ValidationException
     */
    public static function fromArray(array $query): self
    {
        $data = Validator::make(array_intersect_key($query, array_flip(self::KEYS)), [
            'year' => ['nullable', 'integer', 'between:2000,2100', 'required_with:quarter,month'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'lga' => ['nullable', 'string', Rule::in(self::lgaCodes())],
            'level' => ['nullable', Rule::enum(FacilityLevel::class)],
            'owner' => ['nullable', Rule::enum(Ownership::class)],
        ])->validate();

        $month = isset($data['month']) ? (int) $data['month'] : null;

        return new self(
            year: isset($data['year']) ? (int) $data['year'] : null,
            // A month decides its quarter (D-75).
            quarter: $month !== null ? self::quarterOf($month) : (isset($data['quarter']) ? (int) $data['quarter'] : null),
            month: $month,
            lga: isset($data['lga']) ? (string) $data['lga'] : null,
            level: isset($data['level']) ? FacilityLevel::from((string) $data['level']) : null,
            owner: isset($data['owner']) ? Ownership::from((string) $data['owner']) : null,
        );
    }

    /** The same filters in any order give the same key. */
    public function cacheKey(): string
    {
        return md5((string) json_encode($this->toQuery(), JSON_THROW_ON_ERROR));
    }

    /** @return array{year?: int, quarter?: int, month?: int, lga?: string, level?: string, owner?: string} */
    public function toQuery(): array
    {
        return array_filter([
            'year' => $this->year,
            // A month stands for its quarter in a link: one Duration, never both.
            'quarter' => $this->month === null ? $this->quarter : null,
            'month' => $this->month,
            'lga' => $this->lga,
            'level' => $this->level?->value,
            'owner' => $this->owner?->value,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** The LGA list is fixed at 23 (hard rule 9), so it is read once and cached. */
    public static function forgetLgaCodes(): void
    {
        Cache::forget(self::LGA_CODES_CACHE);
    }

    /** @return list<string> */
    private static function lgaCodes(): array
    {
        /** @var list<string> */
        return Cache::rememberForever(self::LGA_CODES_CACHE, fn (): array => array_values(
            DB::table('lgas')->orderBy('code')->pluck('code')->map(fn (mixed $code): string => (string) $code)->all(),
        ));
    }
}
