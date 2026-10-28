<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\DTOs\ProcessOutcome;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Enums\Dimension;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * For the client demo (`edqa:demo:quarantine`, docs/demo-script.md): the old report's broken row
 * (no LGA, ward "Quarterly", facility "2019.00", a typed score of 347.66), stored as a raw
 * submission and sent through the real validation pipeline, so it can be shown held in quarantine
 * before the Data issues page exists (Phase 5). The ODK parser waits for credentials, so the row
 * is given as the parser would read it. Local and testing only; once (the instance ID is fixed).
 */
final class SendDemoRowToQuarantine
{
    public const INSTANCE_ID = 'uuid:demo-live-report-row';

    public function __construct(
        private readonly ProcessParsedBatch $process,
        private readonly SourceRegistry $sources,
    ) {}

    /** The outcome; null when the row is already held. */
    public function execute(): ?ProcessOutcome
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The demo quarantine row is for local demos only.');
        }
        if (Submission::query()->where('instance_id', self::INSTANCE_ID)->exists()) {
            return null;
        }

        $round = Round::query()->orderByDesc('year')->orderByDesc('quarter')->first()
            ?? throw new RuntimeException('There is no round to send the demo row to.');
        $startedAt = CarbonImmutable::parse($round->window_start->toDateString().' 09:00', (string) config('app.timezone'))->addDays(13);
        $endedAt = $startedAt->addMinutes(45);

        $parsed = new ParsedSubmission(
            source: 'odk',
            externalId: self::INSTANCE_ID,
            instanceId: self::INSTANCE_ID,
            deprecatedId: null,
            formVersion: (string) config('edqa_field_maps.current.odk'),
            formVersionKnown: true,
            submittedAt: $endedAt->addMinutes(5),
            startedAt: $startedAt,
            endedAt: $endedAt,
            rawStart: $startedAt->format('Y-m-d\TH:i:s.vP'),
            rawEnd: $endedAt->format('Y-m-d\TH:i:s.vP'),
            lgaRef: null,
            wardRef: 'Quarterly',
            facilityRef: '2019.00',
            roundYear: $round->year,
            roundQuarter: $round->quarter,
            assessorName: 'Demo assessor',
            deviceId: null,
            gps: null,
            items: self::items(),
            rawNumericScores: ['availability_m1' => 347.66],
        );

        // The raw record as ODK would hold it: kept verbatim, never edited (hard rule 8).
        $submission = Submission::query()->create([
            'source_id' => $this->sources->id('odk'),
            'external_id' => self::INSTANCE_ID,
            'instance_id' => self::INSTANCE_ID,
            'form_ref' => 'dqa',
            'form_version' => $parsed->formVersion,
            'payload' => [
                'facility' => ['lga' => null, 'ward' => 'Quarterly', 'facility_code' => '2019.00'],
                'avail_m1_score' => 347.66,
                'start' => $parsed->rawStart,
                'end' => $parsed->rawEnd,
                'demo' => true,
            ],
            'submitted_at' => $parsed->submittedAt,
            'received_at' => now(),
            'status' => SubmissionStatus::Received,
        ]);

        return $this->process->execute([[$submission, $parsed]])[0];
    }

    /** @return list<ItemResponseData> a full set of answers, so only the row's references and typed score fail */
    private static function items(): array
    {
        $items = [];
        foreach (Dimension::cases() as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                foreach (['register_present', 'summary_form', 'tally_sheet', 'reporting_rate'] as $i => $code) {
                    $items[] = new ItemResponseData($dimension, $slot, $code, $i === 3 ? 'fail' : 'pass', true);
                }
            }
        }

        return $items;
    }
}
