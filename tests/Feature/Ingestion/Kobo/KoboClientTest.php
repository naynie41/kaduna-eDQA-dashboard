<?php

declare(strict_types=1);

use App\Domain\Ingestion\Exceptions\KoboAuthFailed;
use App\Domain\Ingestion\Exceptions\KoboNotConfigured;
use App\Domain\Ingestion\Exceptions\KoboUnexpectedResponse;
use App\Domain\Ingestion\Exceptions\KoboUnreachable;
use App\Domain\Ingestion\Exceptions\KoboUnsafeUrl;
use App\Domain\Ingestion\Kobo\KoboSource;
use App\Domain\Ingestion\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

// The KoboToolbox client and source adapter (ARCHITECTURE.md §5.3, SECURITY.md §6), against the
// KPI API v2 as verified on KoboClient. No network: every request is faked.

const KOBO_URL = 'https://kf.kobotoolbox.org';
const KOBO_TOKEN = 'kobo-test-token-6b1f2c';
const KOBO_ASSET = 'aBcD1234efGH';

beforeEach(function (): void {
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), 'url' => KOBO_URL.'/', 'token' => KOBO_TOKEN, 'asset_uid' => KOBO_ASSET, 'page_size' => 500]]);
    Http::preventStrayRequests();
    Sleep::fake();
});

function kobo(): KoboSource
{
    $source = app(SourceRegistry::class)->adapter('kobo');
    assert($source instanceof KoboSource);

    return $source;
}

/** @return list<Request> requests whose URL contains $part */
function koboSent(string $part = ''): array
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $part))->map(fn (array $pair): Request => $pair[0])->values()->all();
}

/** One submission as the data endpoint returns it. */
function koboRow(int $id, array $attachments = []): array
{
    return [
        '_id' => $id, 'meta/instanceID' => "uuid:k-{$id}", 'meta/rootUuid' => "uuid:k-{$id}", '__version__' => 'vQ8xK2',
        '_submission_time' => sprintf('2026-04-14T09:%02d:00', $id), '_submitted_by' => 'kobo.reader', '_attachments' => $attachments,
        'facility/lga' => 'CHK',
    ];
}

function koboAttachment(string $name, string $url, bool $deleted = false): array
{
    return ['download_url' => $url, 'filename' => "kobo.reader/attachments/x/{$name}", 'media_file_basename' => $name, 'mimetype' => 'image/jpeg', 'uid' => 'att'.md5($name), 'question_xpath' => 'photo', 'is_deleted' => $deleted];
}

// ---- Paging and the query

it('pages the data with start and limit, sorted by submission time then _id, across three pages', function (): void {
    $rows = array_map(koboRow(...), range(1, 7));
    Http::fake([KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/*' => function (Request $request) use ($rows) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        return Http::response(['count' => 7, 'next' => 'https://elsewhere.example/next', 'previous' => null, 'results' => array_slice($rows, (int) $q['start'], (int) $q['limit'])]);
    }]);

    $pages = [];
    $skip = 0;
    do {
        $page = kobo()->submissionsPage(null, 3, $skip);
        $pages[] = [count($page->rows), $page->totalCount, $page->hasMore];
        $skip += count($page->rows);
    } while ($page->hasMore);

    expect($pages)->toBe([[3, 7, true], [3, 7, true], [1, 7, false]])
        ->and(array_map(fn (Request $r): string => (string) parse_url($r->url(), PHP_URL_QUERY), koboSent('/data/')))->toBe([
            'format=json&sort=%7B%22_submission_time%22%3A1%2C%22_id%22%3A1%7D&start=0&limit=3',
            'format=json&sort=%7B%22_submission_time%22%3A1%2C%22_id%22%3A1%7D&start=3&limit=3',
            'format=json&sort=%7B%22_submission_time%22%3A1%2C%22_id%22%3A1%7D&start=6&limit=3',
        ])
        // The answer's "next" names another host: it is never followed.
        ->and(koboSent('elsewhere.example'))->toBe([]);
});

it('filters on _submission_time in UTC to the second, $gte or $gt, sends the token, and caps the page at 1000', function (): void {
    Http::fake([KOBO_URL.'/*' => Http::response(['count' => 0, 'next' => null, 'previous' => null, 'results' => []])]);

    kobo()->submissionsPage(CarbonImmutable::parse('2026-04-14 09:50:00.250', 'Africa/Lagos'), 5000, 0, inclusive: true);
    kobo()->submissionsPage(CarbonImmutable::parse('2026-04-14T08:50:00Z'), 10, 20);

    $sent = koboSent('/data/');
    expect(urldecode($sent[0]->url()))->toBe(KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/?format=json&query={"_submission_time":{"$gte":"2026-04-14T08:50:00"}}&sort={"_submission_time":1,"_id":1}&start=0&limit=1000')
        ->and(urldecode($sent[1]->url()))->toContain('query={"_submission_time":{"$gt":"2026-04-14T08:50:00"}}')->toContain('&start=20&limit=10')
        // Every part is percent-encoded on the wire.
        ->and($sent[0]->url())->toContain('query=%7B%22_submission_time%22%3A%7B%22%24gte%22%3A%222026-04-14T08%3A50%3A00%22%7D%7D')
        ->and($sent[0]->header('Authorization'))->toBe(['Token '.KOBO_TOKEN]);
});

it('reports no edits and asks Kobo nothing: no filterable last-edited field exists', function (): void {
    Http::fake();

    $page = kobo()->changedSince(CarbonImmutable::parse('2026-04-01'), 500, 0);

    expect($page->rows)->toBe([])->and($page->totalCount)->toBe(0)->and($page->hasMore)->toBeFalse();
    Http::assertNothingSent();
});

// ---- Asset, account and versions

it('pings: the token\'s account, the asset\'s name and deployed version', function (): void {
    Http::fake([
        KOBO_URL.'/me/' => Http::response(['username' => 'kobo.reader']),
        KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/' => Http::response(['uid' => KOBO_ASSET, 'name' => 'Kaduna DQA', 'deployed_version_id' => 'vQ8xK2', 'has_deployment' => true]),
    ]);

    $ping = kobo()->ping();

    expect([$ping->account, $ping->project, $ping->form, $ping->currentVersion])->toBe(['kobo.reader', 'Kaduna DQA', 'Kaduna DQA', 'vQ8xK2']);
});

it('lists the deployed versions only, across pages', function (): void {
    $versions = array_map(fn (int $i): array => ['uid' => "v{$i}", 'date_deployed' => $i % 3 === 0 ? false : '2026-0'.(($i % 9) + 1).'-01T00:00:00Z', 'version_number' => (string) $i], range(1, 101));
    Http::fake([KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/versions/*' => function (Request $request) use ($versions) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $start = (int) $q['start'];

        return Http::response(['count' => 101, 'next' => $start + 100 < 101 ? 'https://kf.kobotoolbox.org/next' : null, 'results' => array_slice($versions, $start, 100)]);
    }]);

    $listed = kobo()->formVersions();

    expect(count($listed))->toBe(68) // 101 less the 33 never deployed
        ->and($listed[0]->version)->toBe('v1')
        ->and($listed[0]->publishedAt?->toIso8601String())->toBe('2026-02-01T00:00:00+00:00')
        ->and(count(koboSent('/versions/')))->toBe(2);
});

// ---- Errors

it('stops on a refused token: 401 and 403 are auth failures, never retried', function (int $status, string $text): void {
    Http::fake([KOBO_URL.'/*' => Http::response(['detail' => 'Invalid token.'], $status)]);

    expect(fn () => kobo()->submissionsPage(null, 10, 0))->toThrow(function (KoboAuthFailed $e) use ($status, $text): void {
        expect($e->status())->toBe('auth_failed')->and($e->httpStatus)->toBe($status)->and($e->getMessage())->toContain($text)->not->toContain(KOBO_TOKEN);
    });
    expect(koboSent())->toHaveCount(1);
    Sleep::assertNeverSlept();
})->with([
    'bad token' => [401, 'refused the API token'],
    'no access to the asset' => [403, 'no access to the form'],
]);

it('retries 429 and 5xx with backoff, honouring Retry-After, then gives up', function (): void {
    Http::fake([KOBO_URL.'/*' => Http::sequence()
        ->push(['detail' => 'Request was throttled. Expected available in 5 seconds.'], 429, ['Retry-After' => '5'])
        ->push([], 503)
        ->push(['count' => 0, 'results' => []]),
    ]);

    kobo()->submissionsPage(null, 10, 0);

    Sleep::assertSequence([Sleep::for(5000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
    expect(koboSent())->toHaveCount(3);
});

it('gives up after three attempts on a server that keeps failing', function (): void {
    Http::fake([KOBO_URL.'/*' => Http::response([], 503)]);

    expect(fn () => kobo()->submissionsPage(null, 10, 0))->toThrow(fn (KoboUnreachable $e) => expect($e->status())->toBe('unreachable')->and($e->httpStatus)->toBe(503));
    expect(koboSent())->toHaveCount(3);
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

it('refuses an answer that is not the documented shape', function (array $body): void {
    Http::fake([KOBO_URL.'/*' => Http::response($body)]);

    expect(fn () => kobo()->submissionsPage(null, 10, 0))->toThrow(fn (KoboUnexpectedResponse $e) => expect($e->status())->toBe('unexpected_response'));
})->with([
    'no count' => [['results' => []]],
    'no results' => [['count' => 3]],
    'results not a list' => [['count' => 1, 'results' => ['a' => 1]]],
]);

// ---- Not configured

it('sends nothing when not configured, and says which keys to set', function (array $kobo, array $problems): void {
    config(['edqa.sources.kobo' => [...config('edqa.sources.kobo'), ...$kobo]]);
    Http::fake();

    expect(fn () => kobo()->submissionsPage(null, 10, 0))->toThrow(fn (KoboNotConfigured $e) => expect($e->problems)->toBe($problems));
    Http::assertNothingSent();
})->with([
    'no token' => [['token' => null], ['KOBO_API_TOKEN']],
    'nothing' => [['url' => null, 'token' => '', 'asset_uid' => ' '], ['KOBO_BASE_URL', 'KOBO_API_TOKEN', 'KOBO_ASSET_UID']],
    'plain http' => [['url' => 'http://kf.kobotoolbox.org'], ['KOBO_BASE_URL (https)']],
]);

it('allows a plain-http mock only in local, with the flag set', function (): void {
    config(['edqa.sources.kobo.url' => 'http://kobo-mock:8080', 'edqa.kobo.allow_insecure_local' => true]);
    expect(app(SourceRegistry::class)->problems('kobo'))->toBe(['KOBO_ALLOW_INSECURE_LOCAL (local only)', 'KOBO_BASE_URL (https)']);

    app()->detectEnvironment(fn (): string => 'local');
    expect(app(SourceRegistry::class)->problems('kobo'))->toBe([]);
});

// ---- Attachments (SSRF)

it('lists attachments from the submission\'s own _attachments', function (): void {
    Http::fake([KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/*' => Http::response(['count' => 1, 'results' => [koboRow(4, [
        koboAttachment('register.jpg', KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/'),
        koboAttachment('old.jpg', KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/2/', deleted: true),
    ])]])]);

    $refs = kobo()->attachmentList('uuid:k-4');

    expect(array_map(fn ($r): array => [$r->name, $r->exists], $refs))->toBe([['register.jpg', true], ['old.jpg', false]])
        ->and(urldecode(koboSent('/data/')[0]->url()))->toContain('query={"meta/instanceID":"uuid:k-4"}');
});

it('downloads an attachment from the Kobo host with the token, following a redirect that stays there', function (): void {
    $sink = tempnam(sys_get_temp_dir(), 'kobo');
    Http::fake([
        KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/?*' => Http::response(['count' => 1, 'results' => [koboRow(4, [koboAttachment('register.jpg', KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/')])]]),
        KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/' => Http::response('', 302, ['Location' => '/media/original?id=1']),
        KOBO_URL.'/media/original*' => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $download = kobo()->downloadAttachment('uuid:k-4', 'register.jpg', $sink);

    expect(file_get_contents($sink))->toBe('jpeg bytes')
        ->and($download->mime)->toBe('image/jpeg')
        ->and(koboSent('/media/original')[0]->header('Authorization'))->toBe(['Token '.KOBO_TOKEN]);
    @unlink($sink);
});

it('refuses an attachment URL on another host, over http, or redirecting off the Kobo host', function (string $url, ?string $redirect, string $kind): void {
    $sink = tempnam(sys_get_temp_dir(), 'kobo');
    Http::fake([
        KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/?*' => Http::response(['count' => 1, 'results' => [koboRow(4, [koboAttachment('register.jpg', $url)])]]),
        KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/' => Http::response('', 302, ['Location' => (string) $redirect]),
        '*' => Http::response('should never be asked', 200),
    ]);

    expect(fn () => kobo()->downloadAttachment('uuid:k-4', 'register.jpg', $sink))->toThrow(function (KoboUnsafeUrl $e) use ($kind): void {
        expect($e->status())->toBe('unsafe_url')->and($e->getMessage())->toContain($kind);
    });

    // Nothing was asked of any host but Kobo, and the token went nowhere else.
    $elsewhere = Http::recorded(fn (Request $r): bool => ! str_starts_with($r->url(), KOBO_URL.'/'))->all();
    expect($elsewhere)->toBe([]);
    @unlink($sink);
})->with([
    'another host' => ['https://evil.example/steal', null, 'evil.example'],
    'a look-alike host' => ['https://kf.kobotoolbox.org.evil.example/x', null, 'kf.kobotoolbox.org.evil.example'],
    'another port' => ['https://kf.kobotoolbox.org:8443/x', null, 'not the configured'],
    'credentials in the URL' => ['https://user:pass@kf.kobotoolbox.org/x', null, 'not the configured'],
    'plain http' => ['http://kf.kobotoolbox.org/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/', null, 'not https'],
    'a redirect to another host' => [KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/', 'https://storage.example/file.jpg', 'redirect to storage.example'],
    'a redirect to http' => [KOBO_URL.'/api/v2/assets/'.KOBO_ASSET.'/data/4/attachments/1/', 'http://kf.kobotoolbox.org/file.jpg', 'not https'],
]);

it('refuses a file the submission does not list', function (): void {
    Http::fake([KOBO_URL.'/*' => Http::response(['count' => 1, 'results' => [koboRow(4, [koboAttachment('a.jpg', KOBO_URL.'/x')])]])]);

    expect(fn () => kobo()->downloadAttachment('uuid:k-4', 'b.jpg', '/tmp/nope'))->toThrow(KoboUnexpectedResponse::class);
});

// ---- Secrets

it('never logs the token, a query or a body, and names endpoints without IDs', function (): void {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event;
    });
    Http::fake([KOBO_URL.'/*' => Http::sequence()->push([], 503)->push(['count' => 1, 'results' => [koboRow(1)]])->push([], 401)]);

    kobo()->submissionsPage(CarbonImmutable::parse('2026-04-01'), 10, 0);
    try {
        kobo()->submissionsPage(null, 10, 0);
    } catch (KoboAuthFailed) {
    }

    $text = json_encode(array_map(fn (MessageLogged $e): array => [$e->level, $e->message, $e->context], $logged));
    expect(collect($logged)->pluck('message')->unique()->values()->all())->toContain('kobo.request', 'kobo.retry')
        ->and($text)->not->toContain(KOBO_TOKEN)->not->toContain(KOBO_ASSET)->not->toContain('_submission_time')->not->toContain('uuid:k-1')
        ->and($text)->toContain('assets\/{asset}\/data');
});
