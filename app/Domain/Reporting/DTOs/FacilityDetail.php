<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * The facility drawer (ARCHITECTURE.md §9.2.7), as FacilityDetailAggregator builds it. Every
 * score is a ScoreValue; the frontend never rounds or bands.
 *
 * @phpstan-type Facility array{id: int, code: string, name: string, lga: string, ward: string, level: string, level_label: string, ownership: string, ownership_label: string, active: bool}
 * @phpstan-type RoundRef array{id: int, label: string, year: int, quarter: int}
 * @phpstan-type AssessmentRef array{id: int, assessed_on: string, assessor: string, status: string}
 * @phpstan-type Item array{code: string, label: ?string, result: ?string}
 * @phpstan-type ItemGroup array{dimension: string, dimension_label: string, slot: int, month: string, score: ScoreValue, items: list<Item>}
 * @phpstan-type HistoryPoint array{round_id: int, label: string, overall: ScoreValue, selected: bool}
 * @phpstan-type Flag array{code: string, label: string, overridden: array{by: ?string, at: string, reason: ?string}|null}
 * @phpstan-type Attachment array{id: int, filename: string, mime: ?string, size: ?int, status: string, reason: ?string, url: ?string}
 * @phpstan-type Change array{field: string, old: ?string, new: ?string}
 * @phpstan-type Activity array{id: int, at: string, actor: ?string, event: string, subject: string, item_code: ?string, changes: list<Change>, reason: ?string}
 */
final readonly class FacilityDetail
{
    /**
     * @param  Facility  $facility
     * @param  RoundRef|null  $round
     * @param  AssessmentRef|null  $assessment
     * @param  array{availability: ScoreValue, consistency: ScoreValue, validity: ScoreValue, overall: ScoreValue}  $scores
     * @param  list<ItemGroup>  $items
     * @param  list<HistoryPoint>  $history
     * @param  list<Flag>  $flags
     * @param  list<Attachment>  $attachments
     * @param  list<Activity>  $activity
     * @param  array{instance_id: ?string, url: ?string}  $odk
     */
    public function __construct(
        public array $facility,
        public ?array $round,
        public ?array $assessment,
        public array $scores,
        public array $items,
        public array $history,
        public array $flags,
        public array $attachments,
        public array $activity,
        public array $odk,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'facility' => $this->facility,
            'round' => $this->round,
            'assessment' => $this->assessment,
            'scores' => array_map(fn (ScoreValue $score): array => $score->toArray(), $this->scores),
            'items' => array_map(fn (array $group): array => [...$group, 'score' => $group['score']->toArray()], $this->items),
            'history' => array_map(fn (array $point): array => [...$point, 'overall' => $point['overall']->toArray()], $this->history),
            'flags' => $this->flags,
            'attachments' => $this->attachments,
            'activity' => $this->activity,
            'odk' => $this->odk,
        ];
    }
}
