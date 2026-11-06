<?php

declare(strict_types=1);

use App\Domain\Ingestion\Actions\ProcessParsedBatch;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\Enums\SubmissionStatus;
use App\Domain\Ingestion\Kobo\KoboPayloadNormalizer;
use App\Domain\Ingestion\Models\Submission;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Reporting\Jobs\RefreshRoundAggregatesJob;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\ValidationContextFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\OdkPayload;
use Tests\Support\Rows;
use Tests\TestCase;

// Feature tests boot the app and run against the edqa_test Postgres database (never SQLite).
// On the sync test queue the debounced view refresh would rebuild round_aggregates on every scored
// assessment, so it is faked here (only that job); RefreshRoundAggregatesJobTest drives it itself.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => Queue::fake([RefreshRoundAggregatesJob::class]))
    ->in('Feature');

// Unit tests that need translations or config boot the app, without a database.
pest()->extend(TestCase::class)
    ->in('Unit');

// Permission checks as the runtime role (`make grants-check`): the schema is migrated
// beforehand by the migrator, so no RefreshDatabase here.
pest()->extend(TestCase::class)
    ->in('Grants');

// Migration round-trips run real DDL outside a test transaction, so no RefreshDatabase.
pest()->extend(TestCase::class)
    ->in('Migrations');

/**
 * Runs one validation rule (resolved from the container, so config is injected) against a
 * submission, with a context built for that submission alone.
 *
 * @param  class-string<ValidationRule>  $rule
 * @return list<RuleFailure>
 */
function checkRule(string $rule, ParsedSubmission $submission): array
{
    $context = app(ValidationContextFactory::class)->forBatch([$submission]);

    return app($rule)->check($submission, $context);
}

/**
 * The raw Submission row for a parsed submission, as the pull stores it: a realistic ODK payload
 * that the parser reads back into the same submission (OdkPayload).
 */
function submissionRow(ParsedSubmission $parsed): Submission
{
    $payload = OdkPayload::fromParsed($parsed);

    return Submission::factory()->create([
        'external_id' => $payload['__id'],
        'instance_id' => $parsed->instanceId,
        'deprecated_id' => $parsed->deprecatedId,
        'form_version' => $parsed->formVersion,
        'payload' => $payload,
        'submitted_at' => $parsed->submittedAt,
        'status' => SubmissionStatus::Parsed,
    ]);
}

/** A raw ODK Central entry read as the pipeline reads it: its normaliser, then the parser. */
function odkParse(array $raw): ParsedSubmission
{
    return app(SubmissionParser::class)->parse(app(OdkPayloadNormalizer::class)->normalise($raw));
}

/** Binds an adapter for one source key in place of the real one (SourceRegistry::adapter). */
function useSource(string $key, SubmissionSource $adapter): void
{
    app()->instance(SourceRegistry::adapterBinding($key), $adapter);
}

/**
 * Both sources configured with test credentials (never real ones), their rows registered, pages
 * of $pageSize, no backfill throttle and no heartbeat.
 */
function configureSources(int $pageSize = 3): void
{
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => 'https://central.example.org', 'email' => 'svc@example.org', 'password' => 'secret-for-tests', 'project_id' => '7', 'form_id' => 'dqa',
        'page_size' => $pageSize, 'backfill_throttle_seconds' => 0, 'enabled' => true,
    ]]);
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'),
        'url' => 'https://kobo.example.org', 'token' => 'token-for-tests', 'asset_uid' => 'aKdDqaForm2026',
        'page_size' => $pageSize, 'backfill_throttle_seconds' => 0, 'enabled' => true,
    ]]);
    config(['edqa.heartbeats.pull' => ['odk' => null, 'kobo' => null]]);
    Rows::sourceId('odk');
    Rows::sourceId('kobo');
    app(SourceRegistry::class)->flush();
}

/** A raw KoboToolbox data row read as the pipeline reads it: its normaliser, then the parser. */
function koboParse(array $raw): ParsedSubmission
{
    return app(SubmissionParser::class)->parse(app(KoboPayloadNormalizer::class)->normalise($raw));
}

/**
 * Runs a parsed submission through the real pipeline and returns its quarantined record.
 * Needs a published rule version.
 */
function quarantineThrough(ParsedSubmission $parsed): QuarantinedRecord
{
    app(ProcessParsedBatch::class)->execute([[submissionRow($parsed), $parsed]]);

    return QuarantinedRecord::query()->where('instance_id', $parsed->instanceId)->sole();
}

/**
 * Postgres data type of a column in the public schema, e.g. "jsonb", or null if absent.
 */
function columnType(string $table, string $column): ?string
{
    $type = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', $table)
        ->where('column_name', $column)
        ->value('data_type');

    return is_string($type) ? $type : null;
}

/**
 * CREATE INDEX statement for an index, or '' if it doesn't exist.
 */
function indexDefinition(string $index): string
{
    $definition = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('indexname', $index)
        ->value('indexdef');

    return is_string($definition) ? $definition : '';
}

/**
 * Assert that the query is rejected by the named CHECK constraint (SQLSTATE 23514).
 */
function expectCheckViolation(Closure $query, string $constraint): void
{
    expectSqlState($query, '23514', $constraint);
}

/**
 * Assert that the query is rejected by the named UNIQUE constraint or index (SQLSTATE 23505).
 */
function expectUniqueViolation(Closure $query, string $constraint): void
{
    expectSqlState($query, '23505', $constraint);
}

/**
 * Runs the query inside a savepoint, so a rejected statement doesn't abort the test's
 * transaction and later assertions in the same test still run.
 */
function expectSqlState(Closure $query, string $sqlState, string $constraint): void
{
    try {
        DB::transaction($query);
    } catch (QueryException $e) {
        expect((string) $e->getCode())->toBe($sqlState, "Expected SQLSTATE {$sqlState}, got {$e->getCode()}: {$e->getMessage()}")
            ->and($e->getMessage())->toContain("\"{$constraint}\"");

        return;
    }

    test()->fail("Expected SQLSTATE {$sqlState} from constraint \"{$constraint}\", but the query succeeded.");
}
