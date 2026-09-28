<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Validation\Enums\QuarantineResolution;
use App\Domain\Validation\Enums\QuarantineStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// ---- submissions (ARCHITECTURE.md §5.3: instance_id is the idempotency key)

it('rejects a second submission with the same instance ID', function (): void {
    Rows::submission(['instance_id' => 'uuid:abc']);

    expectUniqueViolation(fn () => Rows::submission(['instance_id' => 'uuid:abc']), 'submissions_instance_id_unique');
});

it('accepts every SubmissionStatus the app defines', function (SubmissionStatus $status): void {
    expect(Rows::submission(['status' => $status->value]))->toBeInt();
})->with(fn (): array => SubmissionStatus::cases());

it('rejects an unknown submission status', function (): void {
    expectCheckViolation(fn () => Rows::submission(['status' => 'imported']), 'submissions_status_valid');
});

it('stores the payload as jsonb', function (): void {
    expect(columnType('submissions', 'payload'))->toBe('jsonb');
});

it('has a jsonb_path_ops GIN index on the payload', function (): void {
    expect(indexDefinition('submissions_payload_gin'))
        ->toContain('USING gin')
        ->toContain('jsonb_path_ops');
});

it('indexes deprecated_id for the edit chain', function (): void {
    expect(indexDefinition('submissions_deprecated_id_index'))->toContain('(deprecated_id)');
});

it('links a superseded submission to its replacement', function (): void {
    $replacement = Rows::submission();
    $old = Rows::submission(['status' => 'superseded', 'superseded_by_id' => $replacement]);

    expect(DB::table('submissions')->where('id', $old)->value('superseded_by_id'))->toBe($replacement);
});

// ---- quarantined_records

it('accepts every QuarantineStatus and QuarantineResolution', function (): void {
    foreach (QuarantineStatus::cases() as $status) {
        expect(Rows::quarantined(['status' => $status->value]))->toBeInt();
    }
    foreach (QuarantineResolution::cases() as $resolution) {
        expect(Rows::quarantined(['status' => 'resolved', 'resolution' => $resolution->value]))->toBeInt();
    }
});

it('rejects an unknown quarantine status', function (): void {
    expectCheckViolation(fn () => Rows::quarantined(['status' => 'ignored']), 'quarantined_records_status_valid');
});

it('rejects an unknown quarantine resolution', function (): void {
    expectCheckViolation(
        fn () => Rows::quarantined(['status' => 'resolved', 'resolution' => 'deleted']),
        'quarantined_records_resolution_valid',
    );
});

it('stores payload and failures as jsonb with a GIN index on failures', function (): void {
    expect(columnType('quarantined_records', 'payload'))->toBe('jsonb')
        ->and(columnType('quarantined_records', 'failures'))->toBe('jsonb')
        ->and(indexDefinition('quarantined_records_failures_gin'))->toContain('USING gin (failures)');
});

it('indexes open issues by status and round', function (): void {
    expect(indexDefinition('quarantined_records_status_round_id_index'))->toContain('(status, round_id)');
});

// ---- ODK pull bookkeeping

it('accepts every PullTrigger the app defines', function (PullTrigger $trigger): void {
    expect(Rows::pullRun(['trigger_type' => $trigger->value]))->toBeInt();
})->with(fn (): array => PullTrigger::cases());

it('rejects an unknown pull trigger', function (): void {
    expectCheckViolation(fn () => Rows::pullRun(['trigger_type' => 'cron']), 'odk_pull_runs_trigger_type_valid');
});

it('starts pull-run counters at zero', function (): void {
    $run = DB::table('odk_pull_runs')->where('id', Rows::pullRun())->first();

    expect([$run->fetched, $run->accepted, $run->quarantined, $run->duplicates])->toBe([0, 0, 0, 0]);
});

it('keeps a pull cursor per form', function (): void {
    $id = DB::table('odk_form_syncs')->insertGetId([
        'project_id' => 1, 'form_id' => 'dqa', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $sync = DB::table('odk_form_syncs')->where('id', $id)->first();
    expect($sync->backfill_skip)->toBe(0)
        ->and($sync->submission_count)->toBe(0)
        ->and($sync->last_pulled_at)->toBeNull();
});

it('records attachments against a submission', function (): void {
    $id = DB::table('odk_attachments')->insertGetId([
        'submission_id' => Rows::submission(),
        'filename' => 'register.jpg',
        'mime' => 'image/jpeg',
        'size' => 120_000,
        'status' => 'pending',
    ]);

    expect($id)->toBeInt();
});
