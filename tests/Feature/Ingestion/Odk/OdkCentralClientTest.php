<?php

declare(strict_types=1);

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Exceptions\OdkAuthFailed;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\Exceptions\OdkUnexpectedResponse;
use App\Domain\Ingestion\Exceptions\OdkUnreachable;
use App\Domain\Ingestion\Odk\SessionTokenCache;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The ODK Central client behind SubmissionSource (ARCHITECTURE.md §5.2–5.3, SECURITY.md §6). No
// network: every request is Http::fake, and stray requests fail the test.

const ODK_URL = 'https://central.example.org';
const ODK_TEST_PASSWORD = 'S3cret-Pa55-for-tests';
const ODK_TOKEN_A = 'tok-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const ODK_TOKEN_B = 'tok-BBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';

beforeEach(function (): void {
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'),
        'url' => ODK_URL.'/', 'email' => 'svc-edqa@kaduna.example', 'password' => ODK_TEST_PASSWORD, 'project_id' => '7', 'form_id' => 'dqa',
        'page_size' => 500, 'timeouts' => ['connect' => 10, 'total' => 60],
        'retry' => ['attempts' => 3, 'base_ms' => 1000, 'max_ms' => 30000], 'token_refresh_minutes' => 30,
    ]]);
    Http::preventStrayRequests();
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-17T10:00:00Z')); // moves the cache's clock too
});

function odkSession(string $token, string $expires = '2026-10-18T10:00:00.000Z'): array
{
    return ['token' => $token, 'expiresAt' => $expires, 'createdAt' => '2026-10-17T10:00:00.000Z'];
}

function odkSource(): SubmissionSource
{
    return app(SubmissionSource::class);
}

/** @return list<Request> requests to a path */
function odkSent(string $path): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $path))->map(fn (array $pair): Request => $pair[0])->values()->all();
}

it('signs in once and reuses the session token, stored encrypted', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response([['version' => '2026.1', 'publishedAt' => '2026-01-05T09:00:00.000Z']]),
    ]);

    odkSource()->formVersions();
    odkSource()->formVersions();

    expect(odkSent('/v1/sessions'))->toHaveCount(1)
        ->and(odkSent('/versions'))->toHaveCount(2)
        ->and(odkSent('/versions')[1]->header('Authorization'))->toBe(['Bearer '.ODK_TOKEN_A])
        ->and(odkSent('/v1/sessions')[0]->data())->toBe(['email' => 'svc-edqa@kaduna.example', 'password' => ODK_TEST_PASSWORD]);
    $stored = Cache::get(SessionTokenCache::KEY);
    expect($stored)->toBeString()->not->toContain(ODK_TOKEN_A)
        ->and(decrypt($stored, false))->toBe(ODK_TOKEN_A);
});

it('makes a new session 30 minutes before the token expires', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::sequence()->push(odkSession(ODK_TOKEN_A, '2026-10-17T12:00:00.000Z'))->push(odkSession(ODK_TOKEN_B, '2026-10-18T12:00:00.000Z')),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response([]),
    ]);

    odkSource()->formVersions();
    $this->travelTo(CarbonImmutable::parse('2026-10-17T11:29:00Z'));
    odkSource()->formVersions();
    $this->travelTo(CarbonImmutable::parse('2026-10-17T11:31:00Z')); // within 30 minutes of the 12:00 UTC expiry
    odkSource()->formVersions();

    expect(odkSent('/v1/sessions'))->toHaveCount(2)
        ->and(odkSent('/versions')[2]->header('Authorization'))->toBe(['Bearer '.ODK_TOKEN_B]);
});

it('on a 401 drops the token, signs in again and retries once', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::sequence()->push(odkSession(ODK_TOKEN_A))->push(odkSession(ODK_TOKEN_B)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::sequence()->push([], 401)->push([['version' => '2026.1', 'publishedAt' => null]]),
    ]);

    expect(odkSource()->formVersions())->toHaveCount(1)
        ->and(odkSent('/v1/sessions'))->toHaveCount(2)
        ->and(odkSent('/versions')[1]->header('Authorization'))->toBe(['Bearer '.ODK_TOKEN_B]);
});

it('gives up after one refresh: a second 401 is OdkAuthFailed', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::sequence()->push(odkSession(ODK_TOKEN_A))->push(odkSession(ODK_TOKEN_B)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response([], 401),
    ]);

    expect(fn () => odkSource()->formVersions())->toThrow(OdkAuthFailed::class);
    expect(odkSent('/v1/sessions'))->toHaveCount(2)->and(odkSent('/versions'))->toHaveCount(2);
});

it('pages through submissions: three pages, the last one partial', function (): void {
    $all = array_map(fn (int $n): array => ['__id' => "uuid:{$n}", 'meta' => ['instanceID' => "uuid:{$n}"]], range(1, 5));
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa.svc/Submissions*' => function (Request $request) use ($all) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(['@odata.context' => 'x', '@odata.count' => 5, 'value' => array_slice($all, (int) $query['$skip'], (int) $query['$top'])]);
        },
    ]);

    $pages = [];
    $skip = 0;
    do {
        $page = odkSource()->submissionsPage(null, 2, $skip);
        $pages[] = [count($page->rows), $page->totalCount, $page->hasMore];
        $skip += count($page->rows);
    } while ($page->hasMore);

    expect($pages)->toBe([[2, 5, true], [2, 5, true], [1, 5, false]]);
});

it('filters on __system/submissionDate in UTC and encodes every part of the query', function (): void {
    config(['edqa.sources.odk.form_id' => 'dqa form']);
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/*' => Http::response(['@odata.count' => 0, 'value' => []]),
    ]);

    odkSource()->submissionsPage(CarbonImmutable::parse('2026-04-14 09:50:00.250', 'Africa/Lagos'), 500, 0);
    odkSource()->submissionsPage(null, 500, 1000);

    $sent = odkSent('.svc/Submissions');
    expect($sent[0]->url())->toBe(ODK_URL.'/v1/projects/7/forms/dqa%20form.svc/Submissions?%24filter=__system%2FsubmissionDate%20gt%202026-04-14T08%3A50%3A00.250Z&%24orderby=__system%2FsubmissionDate%20asc&%24top=500&%24skip=0&%24count=true&%24expand=%2A')
        ->and($sent[1]->url())->toBe(ODK_URL.'/v1/projects/7/forms/dqa%20form.svc/Submissions?%24orderby=__system%2FsubmissionDate%20asc&%24top=500&%24skip=1000&%24count=true&%24expand=%2A');
});

it('asks for submissions received or edited since the cursor, ordered by edit time then __id; with no cursor, the edited ones', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/*' => Http::response(['@odata.count' => 0, 'value' => []]),
    ]);

    odkSource()->changedSince(CarbonImmutable::parse('2026-04-20 09:00:00.500', 'Africa/Lagos'), 500, 0);
    odkSource()->changedSince(null, 500, 500);

    $sent = odkSent('.svc/Submissions');
    expect(urldecode($sent[0]->url()))->toBe(ODK_URL.'/v1/projects/7/forms/dqa.svc/Submissions?$filter=__system/submissionDate ge 2026-04-20T08:00:00.500Z or __system/updatedAt ge 2026-04-20T08:00:00.500Z&$orderby=__system/updatedAt asc,__id asc&$top=500&$skip=0&$count=true&$expand=*')
        ->and(urldecode($sent[1]->url()))->toBe(ODK_URL.'/v1/projects/7/forms/dqa.svc/Submissions?$filter=__system/updatedAt ge 1970-01-01T00:00:00.000Z&$orderby=__system/updatedAt asc,__id asc&$top=500&$skip=500&$count=true&$expand=*');
});

it('retries 5xx and 429 with exponential backoff, honouring Retry-After', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::sequence()->push([], 503)->push([], 429, ['Retry-After' => '5'])->push([]),
    ]);

    expect(odkSource()->formVersions())->toBe([]);
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(5000)->milliseconds()]);
});

it('caps a long Retry-After, and gives up after three attempts as OdkUnreachable', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::sequence()->push([], 429, ['Retry-After' => '600'])->push([], 500)->push([], 502),
    ]);

    try {
        odkSource()->formVersions();
        $this->fail('expected OdkUnreachable');
    } catch (OdkUnreachable $e) {
        expect($e->httpStatus)->toBe(502)->and($e->status())->toBe('unreachable');
    }
    Sleep::assertSequence([Sleep::for(30000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

it('never retries another 4xx', function (int $status): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response(['message' => 'nope'], $status),
    ]);

    expect(fn () => odkSource()->formVersions())->toThrow(OdkUnexpectedResponse::class);
    expect(odkSent('/versions'))->toHaveCount(1);
    Sleep::assertNeverSlept();
})->with([400, 404, 409, 422]);

it('retries a failed connection, then reports OdkUnreachable', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::failedConnection(),
    ]);

    expect(fn () => odkSource()->formVersions())->toThrow(OdkUnreachable::class, 'could not be reached after 3 attempts');
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

it('reports a refused sign-in as OdkAuthFailed, naming what to check', function (): void {
    Http::fake([ODK_URL.'/v1/sessions' => Http::response(['message' => 'Could not authenticate'], 401)]);

    expect(fn () => odkSource()->formVersions())->toThrow(OdkAuthFailed::class, 'check ODK_CENTRAL_EMAIL and ODK_CENTRAL_PASSWORD');
});

it('reports a missing role (403) as OdkAuthFailed: the account needs Project Viewer', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/versions' => Http::response([], 403),
    ]);

    expect(fn () => odkSource()->formVersions())->toThrow(OdkAuthFailed::class, 'Project Viewer');
});

it('reports a body it cannot read as OdkUnexpectedResponse, with the status and no guessing', function (array|string $body): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa.svc/Submissions*' => Http::response($body),
    ]);

    try {
        odkSource()->submissionsPage(null, 10, 0);
        $this->fail('expected OdkUnexpectedResponse');
    } catch (OdkUnexpectedResponse $e) {
        expect($e->httpStatus)->toBe(200)->and($e->getMessage())->toContain('HTTP 200');
    }
})->with([
    'not JSON' => ['<html>proxy error</html>'],
    'no value' => [['@odata.count' => 3]],
    'no count' => [['value' => []]],
    'value not a list' => [['@odata.count' => 1, 'value' => ['a' => 1]]],
]);

it('sends nothing when not configured, and says what to set', function (array $odk, array $problems): void {
    config(['edqa.sources.odk' => [...config('edqa.sources.odk'), ...$odk]]);
    Http::fake();

    foreach ([fn () => odkSource()->ping(), fn () => odkSource()->formVersions(), fn () => odkSource()->submissionsPage(null, 10, 0), fn () => odkSource()->attachmentList('uuid:1'), fn () => odkSource()->downloadAttachment('uuid:1', 'a.jpg', '/tmp/x')] as $call) {
        try {
            $call();
            $this->fail('expected OdkNotConfigured');
        } catch (OdkNotConfigured $e) {
            expect($e->problems)->toBe($problems)
                ->and($e->status())->toBe('not_configured')
                ->and($e->getMessage())->not->toContain(ODK_TEST_PASSWORD);
        }
    }
    Http::assertNothingSent();
})->with([
    'nothing set' => [['url' => null, 'email' => null, 'password' => null, 'project_id' => null, 'form_id' => null], ['ODK_CENTRAL_URL', 'ODK_CENTRAL_EMAIL', 'ODK_CENTRAL_PASSWORD', 'ODK_PROJECT_ID', 'ODK_FORM_ID']],
    'two missing' => [['email' => '', 'form_id' => '  '], ['ODK_CENTRAL_EMAIL', 'ODK_FORM_ID']],
    'plain http' => [['url' => 'http://central.example.org'], ['ODK_CENTRAL_URL (https)']],
]);

it('logs requests on the edqa channel, but never the password, a token or a payload', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::sequence()->push(odkSession(ODK_TOKEN_A))->push(odkSession(ODK_TOKEN_B)),
        ODK_URL.'/v1/projects/7/forms/dqa.svc/Submissions*' => Http::sequence()
            ->push([], 401)
            ->push([], 503)
            ->push(['@odata.count' => 1, 'value' => [['__id' => 'uuid:payload-marker', 'secret_answer' => 'PAYLOAD-MARKER-4242']]]),
    ]);

    odkSource()->submissionsPage(null, 10, 0);

    $text = json_encode(array_map(fn (MessageLogged $e): array => [$e->level, $e->message, $e->context], $logged));
    expect(collect($logged)->pluck('message')->unique()->values()->all())->toContain('odk.request', 'odk.retry', 'odk.session')
        ->and($text)->not->toContain(ODK_TEST_PASSWORD)
        ->not->toContain(ODK_TOKEN_A)
        ->not->toContain(ODK_TOKEN_B)
        ->not->toContain('PAYLOAD-MARKER-4242')
        ->not->toContain('payload-marker');
});

it('lists a submission\'s attachments and streams one to disk', function (): void {
    $sink = tempnam(sys_get_temp_dir(), 'odk');
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/projects/7/forms/dqa/submissions/uuid%3Aabc/attachments' => Http::response([['name' => 'register.jpg', 'exists' => true], ['name' => 'trace.geojson', 'exists' => false]]),
        ODK_URL.'/v1/projects/7/forms/dqa/submissions/uuid%3Aabc/attachments/register.jpg' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $refs = odkSource()->attachmentList('uuid:abc');
    $download = odkSource()->downloadAttachment('uuid:abc', 'register.jpg', $sink);

    expect(array_map(fn ($r) => [$r->name, $r->exists], $refs))->toBe([['register.jpg', true], ['trace.geojson', false]])
        ->and([$download->path, $download->mime, $download->size])->toBe([$sink, 'image/jpeg', 8])
        ->and(file_get_contents($sink))->toBe('JPEGDATA');
    @unlink($sink);
});

it('pings: the account, the project and the form with its current version', function (): void {
    Http::fake([
        ODK_URL.'/v1/sessions' => Http::response(odkSession(ODK_TOKEN_A)),
        ODK_URL.'/v1/users/current' => Http::response(['id' => 12, 'displayName' => 'eDQA portal (read-only)', 'email' => 'svc-edqa@kaduna.example']),
        ODK_URL.'/v1/projects/7' => Http::response(['id' => 7, 'name' => 'Kaduna DQA']),
        ODK_URL.'/v1/projects/7/forms/dqa' => Http::response(['xmlFormId' => 'dqa', 'name' => 'Data Quality Assessment', 'version' => '2026.1']),
    ]);

    $ping = odkSource()->ping();

    expect([$ping->account, $ping->project, $ping->form, $ping->currentVersion])->toBe(['eDQA portal (read-only)', 'Kaduna DQA', 'Data Quality Assessment', '2026.1']);
});
