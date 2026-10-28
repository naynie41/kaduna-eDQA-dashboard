<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Assessment\Events\AssessmentScored;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\AssessmentScore;
use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Scoring\DTOs\ScoreCard;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use App\Domain\Scoring\ScoreCalculator;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\DTOs\ValidationResult;
use App\Domain\Validation\Events\SubmissionQuarantined;
use App\Domain\Validation\Exceptions\SubmissionNotResolvable;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\ValidationContext;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Support\Facades\DB;

/**
 * Validates one parsed submission and writes the outcome in one transaction (ARCHITECTURE.md
 * §6 Outcomes):
 *
 * - any hard failure → a quarantined_records row (payload copy, parsed snapshot, every
 *   failure), submission quarantined. No assessment, items or scores.
 * - otherwise → assessment, item responses and nine scores (ScoreCalculator, current published
 *   rule version), soft flags and all-N/A notes in assessments.flags, submission accepted or
 *   flagged. An ODK edit (deprecatedID = an accepted submission) updates that assessment in
 *   place, audited, and supersedes the original.
 *
 * Events dispatch after commit. The Submission row itself is created by the caller.
 */
final class ProcessParsedSubmission
{
    public function __construct(
        private readonly ValidationPipeline $pipeline,
        private readonly ScoreCalculator $calculator,
        private readonly RuleVersionForRound $versions,
    ) {}

    /** @param  QuarantinedRecord|null  $reprocessing  the record being corrected (CorrectAndReprocess) */
    public function execute(Submission $submission, ParsedSubmission $parsed, ValidationContext $context, ?QuarantinedRecord $reprocessing = null): ProcessOutcome
    {
        $result = $this->pipeline->validate($parsed, $context);

        if (! $result->passed()) {
            return $reprocessing !== null
                ? $this->requarantine($reprocessing, $parsed, $context, $result)
                : $this->quarantine($submission, $parsed, $context, $result);
        }

        $outcome = $this->accept($submission, $parsed, $context, $result);
        $context->registerAccepted($parsed);

        return $outcome;
    }

    private function quarantine(Submission $submission, ParsedSubmission $parsed, ValidationContext $context, ValidationResult $result): ProcessOutcome
    {
        return DB::transaction(function () use ($submission, $parsed, $context, $result): ProcessOutcome {
            $record = QuarantinedRecord::query()->create([
                'source_id' => $submission->source_id,
                'submission_id' => $submission->id,
                'instance_id' => $parsed->instanceId,
                'round_id' => $context->roundFor($parsed)?->id,
                'facility_ref' => $parsed->facilityRef,
                'lga_ref' => $parsed->lgaRef,
                'payload' => $submission->payload,
                'parsed' => $parsed->toArray(),
                'failures' => array_map(fn (RuleFailure $f): array => $f->toArray(), $result->failures()),
            ]);

            $submission->update(['status' => SubmissionStatus::Quarantined, 'processed_at' => now()]);
            SubmissionQuarantined::dispatch($record->id, $submission->id);

            return new ProcessOutcome(SubmissionStatus::Quarantined, $result, quarantinedRecordId: $record->id);
        });
    }

    /**
     * A corrected record that still fails: its failures and references are refreshed in place.
     * The raw payload and the parsed snapshot stay as they arrived; no second record, no event.
     */
    private function requarantine(QuarantinedRecord $record, ParsedSubmission $parsed, ValidationContext $context, ValidationResult $result): ProcessOutcome
    {
        $record->update([
            'round_id' => $context->roundFor($parsed)?->id,
            'facility_ref' => $parsed->facilityRef,
            'lga_ref' => $parsed->lgaRef,
            'failures' => array_map(fn (RuleFailure $f): array => $f->toArray(), $result->failures()),
        ]);

        return new ProcessOutcome(SubmissionStatus::Quarantined, $result, quarantinedRecordId: $record->id);
    }

    private function accept(Submission $submission, ParsedSubmission $parsed, ValidationContext $context, ValidationResult $result): ProcessOutcome
    {
        if ($context->ruleVersionId() === null) {
            throw NoPublishedRuleVersion::forSubmission($parsed->instanceId);
        }

        // Every hard rule passed, so these resolve; anything else is a pipeline bug.
        $facility = $context->activeFacility($parsed->facilityRef);
        $round = $context->roundFor($parsed);
        if ($facility === null || $round === null || $parsed->startedAt === null || $parsed->endedAt === null) {
            throw SubmissionNotResolvable::forAcceptance($parsed->instanceId);
        }

        // The round's own version (D-32). ROUND_WINDOW passed, so the round is open: the current one.
        $ruleVersionId = $this->versions->idFor($round->status, $round->ruleVersionId);
        $card = $this->calculator->calculate($parsed->items, $this->versions->configFor($round->status, $round->ruleVersionId));
        $status = $result->isFlagged() ? SubmissionStatus::Flagged : SubmissionStatus::Accepted;
        $softCodes = array_map(fn (RuleFailure $f): string => $f->code, $result->softFlags);

        // ODK sends its own offset; stored values are written in the app's zone (D-30).
        $timezone = (string) config('app.timezone');
        $attributes = [
            'round_id' => $round->id,
            'facility_id' => $facility->id,
            'submission_id' => $submission->id,
            'assessor_name' => $parsed->assessorName,
            'device_id' => $parsed->deviceId,
            'started_at' => $parsed->startedAt->setTimezone($timezone),
            'ended_at' => $parsed->endedAt->setTimezone($timezone),
            'gps' => $parsed->gps,
            'status' => $status->value,
            'flags' => [...array_values(array_unique($softCodes)), ...$card->exclusions],
        ];

        return DB::transaction(function () use ($submission, $parsed, $card, $attributes, $ruleVersionId, $round, $status, $result): ProcessOutcome {
            $previous = $this->supersededSubmission($parsed);
            $assessment = $previous?->assessment;

            if ($assessment !== null) {
                $assessment->update($attributes);
                DB::table('item_responses')->where('assessment_id', $assessment->id)->delete();
            } else {
                $assessment = Assessment::query()->create($attributes);
            }

            $this->storeItems($assessment->id, $parsed->items);
            $this->storeScores($assessment->id, $card, $ruleVersionId, updating: $previous?->assessment !== null);

            $submission->update(['status' => $status, 'processed_at' => now()]);
            $previous?->update(['status' => SubmissionStatus::Superseded, 'superseded_by_id' => $submission->id]);

            AssessmentScored::dispatch($assessment->id, $round->id, $ruleVersionId);

            return new ProcessOutcome($status, $result, assessmentId: $assessment->id, supersededSubmissionId: $previous?->id);
        });
    }

    /** The accepted submission this one edits (ODK deprecatedID), with its assessment. */
    private function supersededSubmission(ParsedSubmission $parsed): ?Submission
    {
        if ($parsed->deprecatedId === null) {
            return null;
        }

        return Submission::query()
            ->with('assessment')
            ->where('instance_id', $parsed->deprecatedId)
            ->whereIn('status', [SubmissionStatus::Accepted, SubmissionStatus::Flagged])
            ->first();
    }

    /**
     * One insert for all items. Ingested answers are not audited, only later corrections
     * (ItemResponse::$recordEvents); an ODK edit is audited on the assessment.
     *
     * @param  list<ItemResponseData>  $items
     */
    private function storeItems(int $assessmentId, array $items): void
    {
        DB::table('item_responses')->insert(array_map(fn (ItemResponseData $item): array => [
            'assessment_id' => $assessmentId,
            'dimension' => $item->dimension->value,
            'month_slot' => $item->monthSlot,
            'item_code' => $item->itemCode,
            'value' => $item->value,
            'is_applicable' => $item->isApplicable,
        ], $items));
    }

    /** One row per dimension × month; an edit updates its rows in place, so changes are audited. */
    private function storeScores(int $assessmentId, ScoreCard $card, int $ruleVersionId, bool $updating): void
    {
        foreach ($card->slots as $dimension => $months) {
            foreach ($months as $month => $score) {
                $key = ['assessment_id' => $assessmentId, 'dimension' => $dimension, 'month_slot' => $month, 'rule_version_id' => $ruleVersionId];
                $values = ['score' => $score, 'computed_at' => now()];

                $updating
                    ? AssessmentScore::query()->updateOrCreate($key, $values)
                    : AssessmentScore::query()->create([...$key, ...$values]);
            }
        }
    }
}
