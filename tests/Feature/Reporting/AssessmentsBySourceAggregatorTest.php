<?php

declare(strict_types=1);

use App\Domain\Reporting\Aggregators\AssessmentsBySourceAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\DTOs\SourceCount;
use App\Domain\Reporting\Enums\ReportAudience;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Monitor's "Assessments by source" (ARCHITECTURE.md §9.2.4): accepted and flagged assessments in
// the selection, per source, read from assessments.source_id only. ReportingFixture's seven Q2
// assessments are ODK's; F4 and F5 (Bravo) are moved to Kobo here.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->odk = Rows::sourceId('odk');
    $this->kobo = Rows::sourceId('kobo');
    $submissions = DB::table('assessments')->where('round_id', $this->f->q2)
        ->whereIn('facility_id', [$this->f->facilities['F4'], $this->f->facilities['F5']])->pluck('submission_id');
    DB::table('submissions')->whereIn('id', $submissions)->update(['source_id' => $this->kobo]);
});

/** @return array<int, int> source id => assessments */
function bySource(array $query = []): array
{
    return collect(app(AssessmentsBySourceAggregator::class)->counts(ReportFilter::fromArray($query, ReportAudience::Admin)))
        ->mapWithKeys(fn (SourceCount $c): array => [$c->sourceId => $c->assessments])->all();
}

it('counts the selection\'s assessments per source, most first', function (): void {
    $counts = app(AssessmentsBySourceAggregator::class)->counts(ReportFilter::fromArray(['year' => 2026, 'quarter' => 2], ReportAudience::Admin));

    expect(array_map(fn (SourceCount $c): array => [$c->sourceId, $c->assessments], $counts))->toBe([[$this->odk, 5], [$this->kobo, 2]]);
});

it('covers the rounds the summaries cover: the latest without a filter, the year\'s with one', function (): void {
    expect(bySource())->toBe([$this->odk => 5, $this->kobo => 2])
        // F1's Q1 assessment is ODK's too.
        ->and(bySource(['year' => 2026]))->toBe([$this->odk => 6, $this->kobo => 2]);
});

it('applies the LGA, level and owner filters', function (): void {
    expect(bySource(['lga' => 'BBB']))->toBe([$this->kobo => 2])
        ->and(bySource(['level' => 'tertiary']))->toBe([$this->kobo => 1])
        ->and(bySource(['owner' => 'private']))->toBe([$this->odk => 2]);
});

it('counts only accepted or flagged assessments', function (): void {
    DB::table('assessments')->where('facility_id', $this->f->facilities['F4'])->update(['status' => 'withdrawn']);
    DB::table('assessments')->where('facility_id', $this->f->facilities['F1'])->where('round_id', $this->f->q2)->update(['status' => 'flagged']);

    expect(bySource())->toBe([$this->odk => 5, $this->kobo => 1]);
});

it('is empty when nothing is in view', function (): void {
    expect(bySource(['year' => 2019]))->toBe([]);
});
