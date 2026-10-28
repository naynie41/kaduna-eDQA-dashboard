<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Validation\Models\QuarantinedRecord;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * For the Data issues page's Playwright run and the client demo (`edqa:demo:data-issues`, D-57):
 * known broken submissions, stored raw and sent through the real validation pipeline, for the
 * latest open round and facilities with no assessment in it yet:
 *
 * - one with no LGA (LGA_UNKNOWN only), which giving its LGA makes acceptable;
 * - two whose form names the previous quarter while the visit is in this round (ROUND_WINDOW
 *   only), so they can be resolved together.
 *
 * Each run is a batch, named by a tag; a batch runs once (the instance IDs are fixed by the tag),
 * and a new tag picks facilities still free. Local and testing only.
 *
 * @phpstan-type Held array{instance_id: string, record_id: int, facility_id: int, facility_code: string, lga_code: string, lga_name: string}
 */
final class SendDemoDataIssues
{
    public const PREFIX = 'uuid:demo-issue-';

    public function __construct(
        private readonly ProcessParsedBatch $process,
        private readonly SubmissionParser $parser,
        private readonly SourceRegistry $sources,
    ) {}

    /** @return array{round_id: int, null_lga: Held, wrong_quarter: list<Held>} */
    public function execute(string $batch = 'a'): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo data issues are for local demos only.');
        }
        if (preg_match('/^[a-z0-9-]{1,32}$/', $batch) !== 1) {
            throw new RuntimeException('The batch tag is 1 to 32 lower-case letters, digits or hyphens.');
        }

        $round = Round::query()->where('status', RoundStatus::Open)->orderByDesc('year')->orderByDesc('quarter')->first()
            ?? throw new RuntimeException('There is no open round to send the demo data issues to.');

        $ids = ['null_lga' => self::PREFIX."{$batch}-null-lga", 'wrong_quarter' => [self::PREFIX."{$batch}-quarter-1", self::PREFIX."{$batch}-quarter-2"]];
        $all = [$ids['null_lga'], ...$ids['wrong_quarter']];

        if (! Submission::query()->whereIn('instance_id', $all)->exists()) {
            $facilities = $this->freeFacilities($round);
            $this->send($round, $facilities[0], $ids['null_lga'], withLga: false, quarter: $round->quarter, year: $round->year, offset: 0);
            [$year, $quarter] = $round->quarter === 1 ? [$round->year - 1, 4] : [$round->year, $round->quarter - 1];
            foreach ($ids['wrong_quarter'] as $i => $instanceId) {
                $this->send($round, $facilities[$i + 1], $instanceId, withLga: true, quarter: $quarter, year: $year, offset: $i + 1);
            }
        }

        return [
            'round_id' => $round->id,
            'null_lga' => self::held($ids['null_lga']),
            'wrong_quarter' => array_map(self::held(...), $ids['wrong_quarter']),
        ];
    }

    /** @return list<Facility> three active facilities with no assessment in the round and no earlier demo submission */
    private function freeFacilities(Round $round): array
    {
        $used = Submission::query()->where('instance_id', 'like', self::PREFIX.'%')->get(['payload', 'form_version'])
            ->map(fn (Submission $s): mixed => FieldMap::for('odk', $s->form_version)?->value($s->payload, 'facility_code'))->filter(fn (mixed $code): bool => is_string($code))->values()->all();

        $facilities = Facility::query()->with(['lga:id,code', 'ward:id,code'])
            ->where('is_active', true)
            ->whereNotIn('code', $used)
            ->whereNotIn('id', Assessment::query()->select('facility_id')->where('round_id', $round->id))
            ->orderBy('id')->limit(3)->get()->all();

        if (count($facilities) < 3) {
            throw new RuntimeException('There are not three free facilities in the open round for the demo data issues.');
        }

        return array_values($facilities);
    }

    private function send(Round $round, Facility $facility, string $instanceId, bool $withLga, int $quarter, int $year, int $offset): void
    {
        $tz = (string) config('app.timezone');
        $first = CarbonImmutable::parse($round->window_start->toDateString().' 09:00', $tz);
        $yesterday = CarbonImmutable::now($tz)->subDay()->setTime(9, 0);
        $startedAt = $first->addDays(13 + $offset);
        $startedAt = $startedAt->greaterThan($yesterday) ? $yesterday->max($first) : $startedAt;
        $endedAt = $startedAt->addMinutes(45);
        $version = (string) config('edqa_field_maps.current.odk');
        $map = FieldMap::for('odk', $version) ?? throw new RuntimeException('No field map to write the demo data issues with.');

        // The raw record as ODK Central would send it, read by the real parser, so a correction
        // reprocesses it exactly as it would a pulled one (hard rule 8).
        $payload = [
            '__id' => $instanceId,
            '__system' => ['submissionDate' => $endedAt->addMinutes(5)->utc()->format('Y-m-d\TH:i:s.v\Z'), 'updatedAt' => null, 'deviceId' => null, 'edits' => 0, 'formVersion' => $version],
            'meta' => ['instanceID' => $instanceId],
        ];
        foreach ([
            'started_at' => $startedAt->format('Y-m-d\TH:i:s.vP'),
            'ended_at' => $endedAt->format('Y-m-d\TH:i:s.vP'),
            'assessor' => 'Demo assessor '.($offset + 1),
            'round_year' => $year,
            'round_quarter' => $quarter,
            'lga_code' => $withLga ? $facility->lga->code : null,
            'ward_code' => $facility->ward->code,
            'facility_code' => $facility->code,
        ] as $field => $value) {
            $path = $map->path($field);
            if ($path !== null) {
                data_set($payload, $path, $value);
            }
        }
        // A full set of answers with one "no" a slot, so only the references fail.
        foreach (['avail' => 'availability', 'consist' => 'consistency', 'valid' => 'validity'] as $prefix => $group) {
            foreach ([1, 2, 3] as $slot) {
                foreach (['nhmis_summary', 'register_present', 'tally_sheet', 'reporting_rate'] as $i => $code) {
                    $payload[$group][$map->questionName("{$prefix}_m{$slot}_{$code}")] = $i === 3 ? 'no' : 'yes';
                }
            }
        }

        $parsed = $this->parser->parse(app(OdkPayloadNormalizer::class)->normalise($payload));
        $submission = Submission::query()->create([
            'source_id' => $this->sources->id('odk'),
            'external_id' => $instanceId,
            'instance_id' => $instanceId,
            'form_ref' => 'dqa',
            'form_version' => $version,
            'payload' => $payload,
            'submitted_at' => $parsed->submittedAt,
            'received_at' => now(),
            'status' => SubmissionStatus::Received,
        ]);

        $this->process->execute([[$submission, $parsed]]);
    }

    /** @return Held */
    private static function held(string $instanceId): array
    {
        $record = QuarantinedRecord::query()->where('instance_id', $instanceId)->firstOrFail();
        $submission = Submission::query()->where('instance_id', $instanceId)->firstOrFail();
        $map = FieldMap::for('odk', $submission->form_version);
        $code = $map === null ? null : $map->value($submission->payload, 'facility_code');
        $facility = Facility::query()->with('lga:id,code,name')->where('code', is_scalar($code) ? (string) $code : '')->firstOrFail();

        return ['instance_id' => $instanceId, 'record_id' => $record->id, 'facility_id' => $facility->id, 'facility_code' => $facility->code, 'lga_code' => $facility->lga->code, 'lga_name' => $facility->lga->name];
    }
}
