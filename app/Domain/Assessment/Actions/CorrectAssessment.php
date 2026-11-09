<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Actions;

use App\Domain\Assessment\DTOs\CorrectionResult;
use App\Domain\Assessment\Events\AssessmentCorrected;
use App\Domain\Assessment\Exceptions\CorrectionRefused;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Assessment\Support\ItemKey;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\Actions\RescoreAssessments;
use App\Domain\Scoring\RuleVersionForRound;
use App\Domain\Validation\EventFlags;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Correct this assessment" (SECURITY.md §4 item 5, ARCHITECTURE.md §7, D-58). An administrator
 * changes item results (pass, fail, N/A; for a question answered as several checks, how many of
 * them passed, D-67) and the visit's start and end, with a typed reason.
 * Scores are never edited (CLAUDE.md rule 2): the corrected assessment is scored again at once,
 * through ScoreCalculator, by the same code a round rescore uses (RescoreAssessments::score), so
 * the facility drawer shows the new scores straight away; the round's rescore is then queued
 * (AssessmentCorrected) for everything that depends on it.
 *
 * - Refused for a closed round (D-32: its scores are pinned; reopen it first, with a reason),
 *   for an assessment that is not accepted, for nothing to change, for an end before its start,
 *   for a start moved out of the round's window (ROUND_WINDOW would hold such a visit), and for
 *   a correction that leaves a dimension with nothing to score.
 * - Each changed item is audited (old and new stored value), and the times as one entry (old and
 *   new start and end); each entry carries the reason.
 * - The soft rules run again: SCORE_JUMP and ALL_PERFECT with the scores; VISIT_TOO_SHORT from
 *   the new times; ASSESSOR_VOLUME for the new visit day when the times move. A flag an
 *   administrator overrode is never raised again.
 *
 * All or nothing: one transaction, the assessment locked.
 */
final class CorrectAssessment
{
    public const OUTCOMES = ['pass', 'fail', 'na'];

    private const TIME_CODES = ['VISIT_TOO_SHORT', 'ASSESSOR_VOLUME'];

    /** @param  array<string, string>  $rules  edqa.validation.rules, in pipeline order */
    public function __construct(
        private readonly RescoreAssessments $rescore,
        private readonly RuleVersionForRound $versions,
        #[Config('edqa.validation.soft_rules.visit_min_minutes')] private readonly int $visitMinMinutes,
        #[Config('edqa.validation.soft_rules.assessor_max_per_day')] private readonly int $assessorMaxPerDay,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    /**
     * Whether a corrected value fits an item: pass, fail or na for an item; na or a count from 0
     * to its checks for a question answered as several checks (D-67).
     */
    public static function fits(string $value, int $checks): bool
    {
        if ($checks <= 1) {
            return in_array($value, self::OUTCOMES, true);
        }

        return $value === 'na' || (ctype_digit($value) && (int) $value <= $checks);
    }

    /** @param  array<string, string>  $items  item key => pass | fail | na, or for a question answered as several checks na | a count of them passed */
    public function execute(Assessment $assessment, array $items, ?CarbonImmutable $startedAt, ?CarbonImmutable $endedAt, User $actor, string $reason): CorrectionResult
    {
        $reason = Reason::require($reason);

        $result = DB::transaction(function () use ($assessment, $items, $startedAt, $endedAt, $actor, $reason): CorrectionResult {
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessment->id);
            $round = Round::query()->findOrFail($assessment->round_id);
            if ($round->status === RoundStatus::Closed) {
                throw CorrectionRefused::roundClosed(RoundLabel::of($round->year, $round->quarter));
            }
            if (! in_array($assessment->status, ['accepted', 'flagged'], true)) {
                throw CorrectionRefused::notAccepted($assessment->status);
            }

            $rules = $this->versions->config($round);
            // The checks of each item key, in stored order: one for an item, several for a
            // question answered as several checks (D-67), corrected together by their count.
            $groups = ItemResponse::query()->where('assessment_id', $assessment->id)->orderBy('id')->get()
                ->groupBy(fn (ItemResponse $r): string => ItemKey::make($r->dimension, $r->month_slot, (string) $r->item_code));

            /** @var list<array{key: string, old: string, new: string, rows: list<array{row: ItemResponse, new: string}>}> $changes */
            $changes = [];
            foreach ($items as $key => $outcome) {
                $checks = $groups->get($key) ?? throw CorrectionRefused::unknownItem($key);
                if (! self::fits($outcome, $checks->count())) {
                    throw CorrectionRefused::unknownItem($key);
                }
                $now = $checks->map(fn (ItemResponse $r): string => $r->is_applicable ? $rules->outcome((string) $r->value) : 'na')->values();
                $current = $checks->count() === 1
                    ? (string) $now[0]
                    : ($now->every(fn (string $o): bool => $o === 'na') ? 'na' : (string) $now->filter(fn (string $o): bool => $o === 'pass')->count());
                if ($current === $outcome) {
                    continue;
                }
                // A count k: the first k checks pass, the rest fail; na: every check N/A.
                $target = $checks->count() === 1
                    ? [$outcome]
                    : array_map(fn (int $i): string => $outcome === 'na' ? 'na' : ($i < (int) $outcome ? 'pass' : 'fail'), range(0, $checks->count() - 1));
                $rows = [];
                foreach ($checks->values() as $i => $row) {
                    if ($now[$i] !== $target[$i]) {
                        $rows[] = ['row' => $row, 'new' => $target[$i]];
                    }
                }
                $changes[] = ['key' => $key, 'old' => $current, 'new' => $outcome, 'rows' => $rows];
            }

            $tz = (string) config('app.timezone');
            $oldStart = CarbonImmutable::instance($assessment->started_at)->setTimezone($tz);
            $oldEnd = CarbonImmutable::instance($assessment->ended_at)->setTimezone($tz);
            $newStart = ($startedAt ?? $oldStart)->setTimezone($tz);
            $newEnd = ($endedAt ?? $oldEnd)->setTimezone($tz);
            $timesChanged = ! $newStart->equalTo($oldStart) || ! $newEnd->equalTo($oldEnd);

            if ($changes === [] && ! $timesChanged) {
                throw CorrectionRefused::noChanges();
            }
            if ($newEnd->lessThan($newStart)) {
                throw CorrectionRefused::endBeforeStart();
            }
            if ($timesChanged) {
                $day = $newStart->toDateString();
                $first = $round->window_start->toDateString();
                $last = $round->window_end->toDateString();
                if ($day < $first || $day > $last) {
                    throw CorrectionRefused::outsideRound($day, RoundLabel::of($round->year, $round->quarter), $first, $last);
                }
            }

            foreach ($changes as $change) {
                foreach ($change['rows'] as ['row' => $row, 'new' => $new]) {
                    $old = (string) $row->value;
                    activity()->withoutLogs(fn () => $row->update(['value' => $new, 'is_applicable' => $new !== 'na']));
                    AuditLog::record($row, $actor, 'corrected',
                        old: ['value' => $old],
                        new: ['value' => $new],
                        reason: $reason,
                        extra: ['key' => $change['key'], 'assessment_id' => $assessment->id],
                    );
                }
            }
            if ($timesChanged) {
                activity()->withoutLogs(fn () => $assessment->update(['started_at' => $newStart, 'ended_at' => $newEnd]));
                AuditLog::record($assessment, $actor, 'corrected',
                    old: ['started_at' => $oldStart->toIso8601String(), 'ended_at' => $oldEnd->toIso8601String()],
                    new: ['started_at' => $newStart->toIso8601String(), 'ended_at' => $newEnd->toIso8601String()],
                    reason: $reason,
                );
            }

            // Scored by ScoreCalculator, as a rescore scores it; the score-based flags with it.
            $scored = $this->rescore->score(new Collection([$assessment]), $round, $this->versions->id($round));
            if ($scored['unscorable'] !== []) {
                throw CorrectionRefused::unscorable();
            }

            $assessment->refresh();
            $this->timeFlags($assessment, $newStart, $newEnd, $timesChanged);

            return new CorrectionResult(
                assessment: $assessment->refresh(),
                round: $round,
                changes: array_map(fn (array $c): array => ['key' => $c['key'], 'old' => $c['old'], 'new' => $c['new']], $changes),
                times: $timesChanged ? ['started_at' => $newStart->toIso8601String(), 'ended_at' => $newEnd->toIso8601String()] : null,
            );
        });

        AssessmentCorrected::dispatch($result->assessment->id, $result->round->id);

        return $result;
    }

    /**
     * VISIT_TOO_SHORT from the new times; ASSESSOR_VOLUME for the new visit day when the times
     * moved (otherwise it stands as ingestion left it). Overridden codes stay off.
     */
    private function timeFlags(Assessment $assessment, CarbonImmutable $start, CarbonImmutable $end, bool $timesChanged): void
    {
        /** @var list<string> $flags */
        $flags = $assessment->flags;
        $overridden = $this->overridden($assessment);
        $raise = [];

        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $raise['VISIT_TOO_SHORT'] = $seconds >= 0 && $seconds < $this->visitMinMinutes * 60;
        $raise['ASSESSOR_VOLUME'] = $timesChanged
            ? $this->assessorFacilitiesOn($assessment, $start) > $this->assessorMaxPerDay
            : in_array('ASSESSOR_VOLUME', $flags, true);

        $kept = array_values(array_filter($flags, fn (string $f): bool => ! in_array($f, self::TIME_CODES, true)));
        foreach (self::TIME_CODES as $code) {
            if ($raise[$code] && ! in_array($code, $overridden, true)) {
                $kept[] = $code;
            }
        }
        $order = array_flip([...array_keys($this->rules), ...EventFlags::ALL]);
        usort($kept, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        $soft = array_values(array_filter($kept, fn (string $f): bool => isset($order[$f])));
        $status = $soft === [] ? 'accepted' : 'flagged';
        $sorted = fn (array $list): array => (static function (array $l): array {
            sort($l);

            return $l;
        })($list);

        if ($sorted($kept) !== $sorted($flags) || $status !== $assessment->status) {
            $assessment->update(['flags' => $kept, 'status' => $status]);
        }
    }

    /** @return list<string> the soft codes overridden on this assessment */
    private function overridden(Assessment $assessment): array
    {
        return DB::table('activity_log')
            ->where('subject_type', $assessment->getMorphClass())->where('subject_id', $assessment->id)->where('event', 'flag_overridden')
            ->pluck('properties')
            ->map(fn (mixed $p): mixed => json_decode((string) $p, true)['code'] ?? null)
            ->filter(fn (mixed $c): bool => is_string($c))->values()->all();
    }

    /**
     * Facilities the assessor visited on the visit's local day, this one included: an assessor is a
     * name ignoring case and spacing plus the device ID (D-29), as ASSESSOR_VOLUME counts them.
     */
    private function assessorFacilitiesOn(Assessment $assessment, CarbonImmutable $start): int
    {
        $tz = (string) config('app.timezone');
        $name = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($assessment->assessor_name)));

        $facilities = Assessment::query()
            ->whereIn('status', ['accepted', 'flagged'])
            ->whereKeyNot($assessment->id)
            ->whereRaw("lower(regexp_replace(trim(assessor_name), '\\s+', ' ', 'g')) = ?", [$name])
            ->whereRaw("coalesce(trim(device_id), '') = ?", [trim((string) $assessment->device_id)])
            ->whereRaw('(started_at AT TIME ZONE ?)::date = ?::date', [$tz, $start->toDateString()])
            ->pluck('facility_id')
            ->push($assessment->facility_id)
            ->unique();

        return count($facilities);
    }
}
