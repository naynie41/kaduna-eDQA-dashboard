<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;

/**
 * The broken-submission fixtures (CLAUDE.md §6 step 6): one named case per live-report defect
 * and per rule, each with exactly the codes it must produce. Hard cases quarantine; soft cases
 * are accepted with exactly those flags. Each build() sets up whatever the database needs.
 */
final class BrokenSubmissions
{
    /** name => [expected codes, builder method] */
    private const HARD = [
        'unknown form version 2021.3' => [['UNKNOWN_FORM_VERSION'], 'unknownFormVersion'],
        "ward 'Quarterly', start 'FEBRUARY'" => [['COLUMN_DRIFT'], 'shiftedColumns'],
        'blank LGA (the 24th LGA)' => [['LGA_UNKNOWN'], 'blankLga'],
        'LGA not one of the 23' => [['LGA_UNKNOWN'], 'unknownLga'],
        "facility '2019.00'" => [['FACILITY_UNKNOWN'], 'numericFacility'],
        'deactivated facility' => [['FACILITY_UNKNOWN'], 'deactivatedFacility'],
        'facility in another LGA' => [['FACILITY_LGA_MISMATCH'], 'facilityInAnotherLga'],
        'validity month 3 missing' => [['ITEMS_INCOMPLETE'], 'missingSlot'],
        'typed scores, no items' => [['ITEMS_INCOMPLETE'], 'typedScoresOnly'],
        'typed score 347.66' => [['SCORE_RANGE'], 'typedScoreOutOfRange'],
        'ends before it starts' => [['END_BEFORE_START'], 'endsBeforeStart'],
        'visit in a closed round' => [['ROUND_WINDOW'], 'closedRound'],
        'visit outside every round' => [['ROUND_WINDOW'], 'outsideEveryRound'],
        'form names another round' => [['ROUND_WINDOW'], 'declaredOtherRound'],
        'second visit in the round' => [['DUPLICATE_ASSESSMENT'], 'duplicate'],
        'the live report row' => [['COLUMN_DRIFT', 'LGA_UNKNOWN', 'FACILITY_UNKNOWN', 'SCORE_RANGE'], 'liveReportRow'],
    ];

    private const SOFT = [
        'overall jumped 35 points' => [['SCORE_JUMP'], 'scoreJump'],
        'every score 100' => [['ALL_PERFECT'], 'allPerfect'],
        '12-minute visit' => [['VISIT_TOO_SHORT'], 'shortVisit'],
        "assessor's seventh facility of the day" => [['ASSESSOR_VOLUME'], 'seventhVisit'],
    ];

    /** @return list<string> */
    public static function hardNames(): array
    {
        return array_keys(self::HARD);
    }

    /** @return list<string> */
    public static function softNames(): array
    {
        return array_keys(self::SOFT);
    }

    /** @return array{0: ParsedSubmission, 1: list<string>} the submission and its expected codes */
    public static function build(string $name): array
    {
        [$codes, $method] = self::HARD[$name] ?? self::SOFT[$name];

        return [self::{$method}(), $codes];
    }

    /** @return list<string> every rule code some fixture covers */
    public static function coveredCodes(): array
    {
        $codes = [];
        foreach ([...array_values(self::HARD), ...array_values(self::SOFT)] as [$expected]) {
            $codes = [...$codes, ...$expected];
        }

        return array_values(array_unique($codes));
    }

    private static function unknownFormVersion(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withFormVersion('2021.3')->build();
    }

    private static function shiftedColumns(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withWard('Quarterly')->withRawStart('FEBRUARY')->withStartedAt(null)->build();
    }

    private static function blankLga(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withLga(null)->build();
    }

    private static function unknownLga(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withLga('Kaduna Central')->build();
    }

    private static function numericFacility(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withFacilityRef('2019.00')->build();
    }

    private static function deactivatedFacility(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->forFacility(Facility::factory()->inactive()->create())->build();
    }

    private static function facilityInAnotherLga(): ParsedSubmission
    {
        $other = Lga::factory()->create();

        return ParsedSubmissionBuilder::new()->withLga($other->code)->build();
    }

    private static function missingSlot(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withoutSlot(Dimension::Validity, 3)->build();
    }

    private static function typedScoresOnly(): ParsedSubmission
    {
        $builder = ParsedSubmissionBuilder::new()->withRawNumericScore('avail_m1_score', 92.5);
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $month) {
                $builder->withoutSlot($dimension, $month);
            }
        }

        return $builder->build();
    }

    private static function typedScoreOutOfRange(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withRawNumericScore('avail_m1_score', 347.66)->build();
    }

    private static function endsBeforeStart(): ParsedSubmission
    {
        $builder = ParsedSubmissionBuilder::new();
        $start = $builder->build()->startedAt;

        return $builder->withEndedAt($start?->subMinutes(30))->build();
    }

    private static function closedRound(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->inRound(Round::factory()->closed()->create())->build();
    }

    private static function outsideEveryRound(): ParsedSubmission
    {
        $round = Round::factory()->create();
        $start = CarbonImmutable::parse($round->window_end->toDateString().' 09:00', (string) config('app.timezone'))->addDays(30);

        return ParsedSubmissionBuilder::new()->inRound($round)->withStartedAt($start)->withEndedAt($start->addMinutes(45))->build();
    }

    private static function declaredOtherRound(): ParsedSubmission
    {
        $round = Round::factory()->create(['year' => 2026, 'quarter' => 2]);

        return ParsedSubmissionBuilder::new()->inRound($round)->withRoundRef(2026, 3)->build();
    }

    private static function duplicate(): ParsedSubmission
    {
        $round = Round::factory()->create();
        $facility = Facility::factory()->create();
        Assessment::factory()->forRound($round)->create(['facility_id' => $facility->id]);

        return ParsedSubmissionBuilder::new()->forFacility($facility)->inRound($round)->build();
    }

    private static function liveReportRow(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withLga(null)->withWard('Quarterly')
            ->withFacilityRef('2019.00')->withRawNumericScore('avail_m1_score', 347.66)->build();
    }

    private static function scoreJump(): ParsedSubmission
    {
        // Scores 75 now; 40 in the previous round.
        $facility = Facility::factory()->create();
        Assessment::factory()->forRound(Round::factory()->closed()->create(['year' => 2026, 'quarter' => 1]))
            ->withScores(['availability' => 40, 'consistency' => 40, 'validity' => 40])
            ->create(['facility_id' => $facility->id]);

        return ParsedSubmissionBuilder::new()->forFacility($facility)->inRound(Round::factory()->create(['year' => 2026, 'quarter' => 2]))->build();
    }

    private static function allPerfect(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withAllItems('pass')->build();
    }

    private static function shortVisit(): ParsedSubmission
    {
        return ParsedSubmissionBuilder::new()->withVisitMinutes(12)->build();
    }

    private static function seventhVisit(): ParsedSubmission
    {
        $round = Round::factory()->create();
        $start = CarbonImmutable::parse($round->window_start->toDateString().' 08:00', (string) config('app.timezone'))->addDays(13);
        Assessment::factory()->forRound($round)->count(6)->create(['assessor_name' => 'A. Bello', 'device_id' => 'collect:p1', 'started_at' => $start]);

        return ParsedSubmissionBuilder::new()->inRound($round)->withAssessor('A. Bello')->withDeviceId('collect:p1')->build();
    }
}
