<?php

declare(strict_types=1);

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Jobs\PullSubmissionsJob;
use App\Domain\Ingestion\Webhooks\KoboTokenVerifier;
use App\Domain\Ingestion\Webhooks\OdkHmacVerifier;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeKoboSource;
use Tests\Support\FakeSubmissionSource;

// POST /webhooks/{source}: one verification strategy per source (ARCHITECTURE.md §5.4,
// SECURITY.md §6, D-54). Each only queues that source's pull.

const WEBHOOK_SECRET = 'whsec-test-0123456789abcdef';
const KOBO_WEBHOOK_TOKEN = 'kobo-webhook-token-0123456789abcdefghij';

beforeEach(function (): void {
    configureSources();
    config(['edqa.odk.webhook.secret' => WEBHOOK_SECRET, 'edqa.kobo.webhook.token' => KOBO_WEBHOOK_TOKEN]);
    useSource('odk', new FakeSubmissionSource);
    useSource('kobo', new FakeKoboSource);
});

/**
 * POST /webhooks/{source} with a body and headers.
 *
 * @param  array<string, string>  $headers
 */
function postWebhook(object $test, string $source, string $body, array $headers): TestResponse
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $test->call('POST', "/webhooks/{$source}", [], [], [], [...$server, 'CONTENT_TYPE' => 'application/json'], $body);
}

/** POST /webhooks/odk, signed (or not) as Central's sender would. @param  array<string, string>|null  $headers  null: signed correctly now */
function webhook(object $test, string $body = '{"event":"submission.created"}', ?array $headers = null): TestResponse
{
    return postWebhook($test, 'odk', $body, $headers ?? webhookSigned($body, now()->getTimestamp()));
}

/** @return array<string, string> */
function webhookSigned(string $body, int $timestamp, string $secret = WEBHOOK_SECRET): array
{
    return [
        OdkHmacVerifier::TIMESTAMP => (string) $timestamp,
        OdkHmacVerifier::SIGNATURE => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

/** POST /webhooks/kobo as Kobo's REST Service sends it. @param  array<string, string>|null  $headers  null: the right token */
function koboWebhook(object $test, string $body = '{"_id": 1}', ?array $headers = null): TestResponse
{
    return postWebhook($test, 'kobo', $body, $headers ?? [KoboTokenVerifier::HEADER => KOBO_WEBHOOK_TOKEN]);
}

/** Row counts of every table but the job queue, cache and activity log. @return array<string, int> */
function webhookTableCounts(): array
{
    $counts = [];
    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename") as $table) {
        $counts[$table->tablename] = DB::table($table->tablename)->count();
    }

    return Arr::except($counts, ['activity_log', 'cache', 'cache_locks', 'jobs']);
}

// ---- ODK: HMAC over the timestamp and raw body

it('accepts a valid ODK signature: it queues an ODK webhook pull and answers 202', function (): void {
    Queue::fake();

    webhook($this)->assertStatus(202)->assertJson(['message' => __('sources.webhook.queued')]);

    Queue::assertPushed(PullSubmissionsJob::class, 1);
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === 'odk' && $job->trigger === PullTrigger::Webhook && $job->userId === null && $job->runId === null);
});

it('refuses a missing, invalid or stale ODK signature with 401, queueing nothing', function (array $headers, string $code): void {
    Queue::fake();
    $body = '{"event":"submission.created"}';
    $headers = array_map(fn (string $v): string => strtr($v, ['{now}' => (string) now()->getTimestamp()]), $headers);
    if (($headers['sign'] ?? null) !== null) {
        [$timestamp, $secret, $signedBody] = explode('|', $headers['sign']);
        $headers = webhookSigned($signedBody === '' ? $body : $signedBody, (int) $timestamp, $secret);
    }

    webhook($this, $body, $headers)->assertStatus(401)->assertJson(['code' => $code]);

    Queue::assertNothingPushed();
})->with([
    'no headers' => [[], 'signature_missing'],
    'no signature' => [[OdkHmacVerifier::TIMESTAMP => '{now}'], 'signature_missing'],
    'no timestamp' => [[OdkHmacVerifier::SIGNATURE => 'sha256=abc'], 'signature_missing'],
    'timestamp not a number' => [[OdkHmacVerifier::TIMESTAMP => 'yesterday', OdkHmacVerifier::SIGNATURE => 'sha256=abc'], 'signature_missing'],
    'wrong secret' => [['sign' => '{now}|another-secret|'], 'signature_invalid'],
    'another body' => [['sign' => '{now}|'.WEBHOOK_SECRET.'|{"event":"other"}'], 'signature_invalid'],
    'garbage signature' => [[OdkHmacVerifier::TIMESTAMP => '{now}', OdkHmacVerifier::SIGNATURE => 'sha256=00'], 'signature_invalid'],
    'the Kobo token instead' => [[KoboTokenVerifier::HEADER => KOBO_WEBHOOK_TOKEN], 'signature_missing'],
]);

it('refuses an ODK timestamp more than five minutes off, even correctly signed', function (int $offset): void {
    Queue::fake();
    $body = '{}';

    webhook($this, $body, webhookSigned($body, now()->getTimestamp() + $offset))->assertStatus(401)->assertJson(['code' => 'timestamp_stale']);

    Queue::assertNothingPushed();
})->with(['six minutes old' => -360, 'six minutes ahead' => 360]);

it('accepts an ODK timestamp just inside the five minutes', function (): void {
    Queue::fake();
    $body = '{}';

    webhook($this, $body, webhookSigned($body, now()->getTimestamp() - 290))->assertStatus(202);
});

it('switches the ODK webhook off (404) when no secret is configured', function (?string $secret): void {
    Queue::fake();
    config(['edqa.odk.webhook.secret' => $secret]);

    webhook($this)->assertNotFound();

    Queue::assertNothingPushed();
})->with([null, '']);

// ---- Kobo: a long token in X-EDQA-Webhook-Token

it('accepts the right Kobo token: it queues a Kobo webhook pull only, and answers 202', function (): void {
    Queue::fake();

    koboWebhook($this)->assertStatus(202)->assertJson(['message' => __('sources.webhook.queued')]);

    Queue::assertPushed(PullSubmissionsJob::class, 1);
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === 'kobo' && $job->trigger === PullTrigger::Webhook);
});

it('refuses a missing or wrong Kobo token with 401, queueing nothing', function (array $headers, string $code): void {
    Queue::fake();

    koboWebhook($this, '{"_id": 1}', $headers)->assertStatus(401)->assertJson(['code' => $code, 'message' => __("sources.webhook.{$code}")]);

    Queue::assertNothingPushed();
})->with([
    'no header' => [[], 'token_missing'],
    'empty header' => [[KoboTokenVerifier::HEADER => ''], 'token_missing'],
    'wrong token' => [[KoboTokenVerifier::HEADER => 'kobo-webhook-token-0123456789abcdefghiX'], 'token_invalid'],
    'a prefix of the token' => [[KoboTokenVerifier::HEADER => substr(KOBO_WEBHOOK_TOKEN, 0, 32)], 'token_invalid'],
    'the token with a space' => [[KoboTokenVerifier::HEADER => KOBO_WEBHOOK_TOKEN.' '], 'token_invalid'],
    'the ODK secret' => [[KoboTokenVerifier::HEADER => WEBHOOK_SECRET], 'token_invalid'],
    'an ODK signature instead' => [[OdkHmacVerifier::TIMESTAMP => '1', OdkHmacVerifier::SIGNATURE => 'sha256=00'], 'token_missing'],
]);

it('switches the Kobo webhook off (404) when the token is unset, empty or under 32 characters', function (?string $token): void {
    Queue::fake();
    config(['edqa.kobo.webhook.token' => $token]);

    koboWebhook($this, '{}', [KoboTokenVerifier::HEADER => (string) $token])->assertNotFound();

    Queue::assertNothingPushed();
})->with(['unset' => null, 'empty' => '', 'short' => 'only-thirty-one-characters-long']);

it('never writes the token, or the body, to the log', function (): void {
    $log = Mockery::spy();
    Log::shouldReceive('channel')->andReturn($log);

    koboWebhook($this, '{"secret":"body"}', [KoboTokenVerifier::HEADER => 'wrong-token-value-0123456789abcdefgh'])->assertStatus(401);

    $log->shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['source' => 'kobo', 'reason' => 'token_invalid']);
});

// ---- Both

it('answers 404 for a source not in config, whatever it sends', function (string $source): void {
    Queue::fake();

    postWebhook($this, $source, '{}', [KoboTokenVerifier::HEADER => KOBO_WEBHOOK_TOKEN])->assertNotFound();

    Queue::assertNothingPushed();
})->with(['dhis2', 'odk2', 'ODK', 'kobo..', '../odk']);

it('keeps each source to its own pull, and queues at most one a minute per source', function (): void {
    Queue::fake();

    webhook($this)->assertStatus(202);
    webhook($this)->assertStatus(202);
    koboWebhook($this)->assertStatus(202);
    koboWebhook($this)->assertStatus(202);

    Queue::assertPushed(PullSubmissionsJob::class, 2);
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === 'odk');
    Queue::assertPushed(PullSubmissionsJob::class, fn (PullSubmissionsJob $job): bool => $job->source === 'kobo');
});

it('accepts a genuine webhook for a source switched off, and queues nothing', function (): void {
    Queue::fake();
    DB::table('submission_sources')->where('key', 'kobo')->update(['is_enabled' => false]);

    koboWebhook($this)->assertStatus(202);

    Queue::assertNothingPushed();
});

it('never treats the body as data: a genuine body full of submissions writes nothing', function (string $source): void {
    Queue::fake(); // the pull it queues is the only effect
    $before = webhookTableCounts();
    $body = (string) json_encode(['value' => [['__id' => 'uuid:evil', '_id' => 1, 'meta' => ['instanceID' => 'uuid:evil'], 'facility' => ['facility_code' => 'X']]]]);

    ($source === 'odk' ? webhook($this, $body) : koboWebhook($this, $body))->assertStatus(202);

    expect(webhookTableCounts())->toBe($before)
        ->and(DB::table('submissions')->where('instance_id', 'uuid:evil')->exists())->toBeFalse();
    Queue::assertPushed(PullSubmissionsJob::class, 1);
})->with(['odk', 'kobo']);

it('rate limits each source on its own', function (): void {
    Queue::fake();
    foreach (range(1, 60) as $i) {
        koboWebhook($this, '{}', [])->assertStatus(401);
    }

    koboWebhook($this, '{}', [])->assertStatus(429);
    webhook($this)->assertStatus(202); // ODK's limit is its own
});

it('reaches every source\'s webhook with CSRF enforced, as on a server: each is verified its own way', function (): void {
    // Laravel skips the CSRF check only under the testing environment; outside it, it runs.
    app()->detectEnvironment(fn (): string => 'local');
    Queue::fake();

    koboWebhook($this)->assertStatus(202);
    webhook($this)->assertStatus(202);
    // Only the webhooks are exempt: any other POST without a token is still refused.
    $this->post('/login', ['email' => 'nobody@example.org', 'password' => 'x'])->assertStatus(419);

    Queue::assertPushed(PullSubmissionsJob::class, 2);
    app()->detectEnvironment(fn (): string => 'testing');
});
