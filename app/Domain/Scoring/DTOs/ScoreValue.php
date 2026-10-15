<?php

declare(strict_types=1);

namespace App\Domain\Scoring\DTOs;

use App\Domain\Scoring\Bands;
use App\Domain\Scoring\Enums\Band;
use App\Domain\Scoring\Presentation;
use JsonSerializable;
use NumberFormatter;

/**
 * The only shape in which a score reaches Inertia or the API (CONVENTION.md §4): the full
 * precision value for sorting and charts, plus everything a person reads, decided here so the
 * frontend never rounds or bands. display is 1 dp and headline 0 dp, both en-NG and in the
 * presentation mode (Q-09); the band is classified on the displayed value (Q-26).
 *
 * No score: value and band null, display and headline "—", bandLabel "No data".
 */
final readonly class ScoreValue implements JsonSerializable
{
    public const NO_DATA = '—';

    public function __construct(
        public ?float $value,
        public string $display,
        public string $headline,
        public ?Band $band,
        public string $bandLabel,
    ) {}

    public static function from(float $value, RuleConfig $rules): self
    {
        $band = Bands::classify($value, $rules);

        return new self(
            $value,
            self::format(Presentation::round($value, Bands::DISPLAY_DP), Bands::DISPLAY_DP),
            self::format(Presentation::headline($value), 0),
            $band,
            $band->label(),
        );
    }

    public static function fromNullable(?float $value, ?RuleConfig $rules): self
    {
        return $value === null || $rules === null ? self::none() : self::from($value, $rules);
    }

    /** No score: "—" and "No data". Needs no rule version. */
    public static function none(): self
    {
        $label = __('scoring.no_data');

        return new self(null, self::NO_DATA, self::NO_DATA, null, is_string($label) ? $label : 'No data');
    }

    /** @return array{value: ?float, display: string, headline: string, band: ?string, bandLabel: string} */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'display' => $this->display,
            'headline' => $this->headline,
            'band' => $this->band?->value,
            'bandLabel' => $this->bandLabel,
        ];
    }

    /** @return array{value: ?float, display: string, headline: string, band: ?string, bandLabel: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Already rounded by Presentation: the formatter only lays it out, exactly $dp decimals. */
    public static function format(float|int $rounded, int $dp): string
    {
        $formatter = new NumberFormatter('en_NG', NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $dp);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $dp);

        $formatted = $formatter->format($rounded);

        return is_string($formatted) ? $formatted : number_format((float) $rounded, $dp);
    }
}
