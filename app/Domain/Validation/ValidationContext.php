<?php

declare(strict_types=1);

namespace App\Domain\Validation;

use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Validation\DTOs\FacilitySnapshot;
use App\Domain\Validation\DTOs\LgaSnapshot;
use App\Domain\Validation\DTOs\RoundSnapshot;
use App\Domain\Validation\Exceptions\SubmissionNotResolvable;
use App\Support\Text\Normalise;
use Carbon\CarbonImmutable;

/**
 * Everything the rules need about the world, loaded once per batch by ValidationContextFactory
 * and answered from memory (no queries per rule or per submission).
 *
 * It also tracks the batch itself: registerAccepted() records each submission the pipeline
 * accepts, so a second visit to the same facility in the same round, or an assessor's seventh
 * facility of the day, is caught even when both arrive in one batch.
 */
final class ValidationContext
{
    /**
     * @param  array<string, LgaSnapshot>  $lgas  keyed by normalised code and normalised name
     * @param  array<string, FacilitySnapshot>  $facilities  keyed by exact code
     * @param  list<RoundSnapshot>  $rounds
     * @param  array<string, list<string>>  $acceptedInstances  "round:facility" => instance IDs
     * @param  array<int, list<array{sequence: int, overall: float}>>  $priorScores  per facility, latest first
     * @param  array<string, array<int, true>>  $assessorDays  "assessor|Y-m-d" => facility IDs
     * @param  array<string, true>  $knownFormVersions
     */
    public function __construct(
        private readonly array $lgas,
        private readonly array $facilities,
        private readonly array $rounds,
        private array $acceptedInstances,
        private readonly array $priorScores,
        private array $assessorDays,
        private readonly array $knownFormVersions,
        private readonly ?RuleConfig $ruleConfig,
    ) {}

    /** By code first, then by name, ignoring case, spacing and punctuation. */
    public function lga(?string $ref): ?LgaSnapshot
    {
        $key = Normalise::matchKey($ref);

        return $key === null ? null : ($this->lgas["code:{$key}"] ?? $this->lgas["name:{$key}"] ?? null);
    }

    public function lgaById(int $id): ?LgaSnapshot
    {
        foreach ($this->lgas as $lga) {
            if ($lga->id === $id) {
                return $lga;
            }
        }

        return null;
    }

    /** By exact code (surrounding spaces ignored), active or not. */
    public function facility(?string $ref): ?FacilitySnapshot
    {
        $code = Normalise::nullIfBlank($ref);

        return $code === null ? null : ($this->facilities[$code] ?? null);
    }

    public function activeFacility(?string $ref): ?FacilitySnapshot
    {
        $facility = $this->facility($ref);

        return $facility?->isActive === true ? $facility : null;
    }

    public function round(int $year, int $quarter): ?RoundSnapshot
    {
        foreach ($this->rounds as $round) {
            if ($round->year === $year && $round->quarter === $quarter) {
                return $round;
            }
        }

        return null;
    }

    /**
     * Every round, open or closed, whose window holds the local day, in time order. Windows
     * should never overlap (§3), so more than one means bad round setup.
     *
     * @return list<RoundSnapshot>
     */
    public function roundsContaining(CarbonImmutable $day): array
    {
        return array_values(array_filter($this->rounds, fn (RoundSnapshot $r): bool => $r->contains($day)));
    }

    /**
     * The round, open or closed, whose window holds the visit date (D-28). Null when no window
     * holds it, or when more than one does: an ambiguous round is never guessed (CLAUDE.md hard
     * rule 12).
     */
    public function roundFor(ParsedSubmission $submission): ?RoundSnapshot
    {
        $matches = $this->roundsContaining($submission->visitDate());

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** The highest published scoring rule version's config; null when none is published. */
    public function ruleConfig(): ?RuleConfig
    {
        return $this->ruleConfig;
    }

    /**
     * The instance ID of an accepted assessment (in the database or earlier in this batch) for
     * the same round and facility, or null. The submission's own instance and the one it
     * supersedes (ODK deprecatedID) are not duplicates.
     */
    public function duplicateOf(ParsedSubmission $submission, int $roundId, int $facilityId): ?string
    {
        $own = array_filter([$submission->instanceId, $submission->deprecatedId]);

        foreach ($this->acceptedInstances["{$roundId}:{$facilityId}"] ?? [] as $instanceId) {
            if (! in_array($instanceId, $own, true)) {
                return $instanceId;
            }
        }

        return null;
    }

    /**
     * The facility's overall score in its latest round before this one, under the current
     * published rule version; null if it has none.
     */
    public function priorOverallScore(int $facilityId, RoundSnapshot $round): ?float
    {
        foreach ($this->priorScores[$facilityId] ?? [] as $score) {
            if ($score['sequence'] < $round->sequence()) {
                return $score['overall'];
            }
        }

        return null;
    }

    /**
     * Distinct facilities the assessor visited that local day: accepted assessments plus this
     * batch's accepted submissions. Only the batch's visit dates are loaded.
     */
    public function assessorVisitCount(string $assessorName, CarbonImmutable $day): int
    {
        return count($this->assessorDays[self::assessorDayKey($assessorName, $day)] ?? []);
    }

    public function isKnownFormVersion(string $version): bool
    {
        return isset($this->knownFormVersions[$version]);
    }

    /** Records a submission the pipeline accepted, for the in-batch checks above. */
    public function registerAccepted(ParsedSubmission $submission): void
    {
        $facility = $this->facility($submission->facilityRef);
        $round = $this->roundFor($submission);

        if ($facility === null || $round === null) {
            throw SubmissionNotResolvable::forAcceptance($submission->instanceId);
        }

        $this->acceptedInstances["{$round->id}:{$facility->id}"][] = $submission->instanceId;
        $this->assessorDays[self::assessorDayKey($submission->assessorName, $submission->visitDate())][$facility->id] = true;
    }

    /** "assessor|Y-m-d": the name ignoring case and spacing, the local calendar day. */
    public static function assessorDayKey(string $assessorName, CarbonImmutable|string $day): string
    {
        $date = is_string($day) ? $day : $day->setTimezone((string) config('app.timezone'))->toDateString();

        return Normalise::name($assessorName)."|{$date}";
    }
}
