<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Kobo;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use App\Domain\Ingestion\Exceptions\KoboUnexpectedResponse;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * KoboToolbox as a SubmissionSource (ARCHITECTURE.md §5.0, §5.3), over the KPI API v2 (what was
 * verified is on KoboClient). One configured asset is the form.
 *
 * - submissionsPage: the asset's data, query {"_submission_time": {"$gte": cursor}} (UTC, to the
 *   second, as Kobo writes the field), sorted by _submission_time then _id, paged with start and
 *   limit (at most 1000).
 * - changedSince: nothing. The KPI API exposes no filterable last-edited field for submissions
 *   (KoboClient), so no query can find edits; ReconcileSourceJob (Phase 2C step 5) is Kobo's edit
 *   path. Returning none (with no request) keeps the pull's second pass a no-op here.
 * - attachmentList / downloadAttachment: from the submission's own _attachments; a download URL
 *   is followed only on the Kobo host, over https (KoboClient::download).
 *
 * Every method throws a KoboException subclass, never a transport exception.
 */
final class KoboSource implements SubmissionSource
{
    /** Versions per page when listing them: a form has few. */
    private const VERSIONS_PAGE = 100;

    public function __construct(
        private readonly KoboClient $client,
    ) {}

    public function ping(): SourcePing
    {
        $me = $this->object('/me/', 'me');
        $asset = $this->object($this->asset(), 'assets/{asset}');
        $name = self::string($asset, 'name') ?? '';

        return new SourcePing(
            account: self::string($me, 'username') ?? '',
            project: $name,
            form: $name,
            currentVersion: self::string($asset, 'deployed_version_id'),
        );
    }

    public function formVersions(): array
    {
        $versions = [];
        for ($start = 0; ; $start += self::VERSIONS_PAGE) {
            $page = $this->object($this->asset().'versions/', 'assets/{asset}/versions', ['start' => $start, 'limit' => self::VERSIONS_PAGE]);
            $results = $page['results'] ?? null;
            if (! is_array($results) || ! array_is_list($results)) {
                throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/versions');
            }
            foreach ($results as $entry) {
                $uid = is_array($entry) ? self::string($entry, 'uid') : null;
                if ($uid === null) {
                    throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/versions');
                }
                // date_deployed is false for a version never deployed: only deployed ones carry submissions.
                $deployed = $entry['date_deployed'] ?? false;
                if ($deployed !== false) {
                    $versions[] = new FormVersion($uid, self::time($deployed));
                }
            }
            if (count($results) < self::VERSIONS_PAGE || ! is_string($page['next'] ?? null)) {
                return $versions;
            }
        }
    }

    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip, bool $inclusive = false): SubmissionPage
    {
        $query = ['format' => 'json'];
        if ($since !== null) {
            $query['query'] = self::encode(['_submission_time' => [($inclusive ? '$gte' : '$gt') => self::koboTime($since)]]);
        }
        // A cursor on a field is only safe if the feed is ordered by that field; _id breaks ties.
        $query['sort'] = self::encode(['_submission_time' => 1, '_id' => 1]);

        return $this->page($query, $top, $skip);
    }

    /** None: no filterable last-edited field on Kobo submissions (see the class docblock). */
    public function changedSince(?CarbonImmutable $cursor, int $top, int $skip): SubmissionPage
    {
        return new SubmissionPage([], 0, false);
    }

    public function attachmentList(string $instanceId): array
    {
        $refs = [];
        foreach ($this->attachments($instanceId) as $attachment) {
            $name = self::attachmentName($attachment);
            if ($name === null) {
                throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/data');
            }
            $refs[] = new AttachmentRef($name, ($attachment['is_deleted'] ?? false) !== true && self::string($attachment, 'download_url') !== null);
        }

        return $refs;
    }

    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload
    {
        foreach ($this->attachments($instanceId) as $attachment) {
            $url = self::string($attachment, 'download_url');
            if (self::attachmentName($attachment) === $name && ($attachment['is_deleted'] ?? false) !== true && $url !== null) {
                $response = $this->client->download($url, $sinkPath);
                $type = $response->header('Content-Type');

                return new AttachmentDownload($sinkPath, $type === '' ? null : trim(explode(';', $type)[0]), is_file($sinkPath) ? (int) filesize($sinkPath) : 0);
            }
        }

        throw KoboUnexpectedResponse::attachmentMissing($name);
    }

    /**
     * One page of the asset's data.
     *
     * @param  array<string, string>  $query
     */
    private function page(array $query, int $top, int $skip): SubmissionPage
    {
        $limit = max(1, min($top, KoboConfig::MAX_PAGE_SIZE));
        $body = $this->object($this->asset().'data/', 'assets/{asset}/data', [...$query, 'start' => max(0, $skip), 'limit' => $limit]);
        $rows = $body['results'] ?? null;
        $total = $body['count'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! is_int($total)) {
            throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/data');
        }
        $entries = [];
        foreach ($rows as $row) {
            $entries[] = is_array($row) ? $row : throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/data');
        }

        // Paged by start and limit: the answer's "next" URL is never followed (it could name another host).
        return new SubmissionPage($entries, $total, $skip + count($entries) < $total);
    }

    /**
     * The submission's _attachments, found by its instance ID.
     *
     * @return list<array<array-key, mixed>>
     */
    private function attachments(string $instanceId): array
    {
        $body = $this->object($this->asset().'data/', 'assets/{asset}/data', [
            'format' => 'json', 'query' => self::encode(['meta/instanceID' => $instanceId]), 'limit' => 1,
        ]);
        $row = is_array($body['results'] ?? null) ? ($body['results'][0] ?? null) : null;
        if (! is_array($row)) {
            return [];
        }
        $attachments = $row['_attachments'] ?? [];
        if (! is_array($attachments) || ! array_is_list($attachments)) {
            throw KoboUnexpectedResponse::malformed(200, 'assets/{asset}/data');
        }

        return array_values(array_filter($attachments, is_array(...)));
    }

    /** @param  array<array-key, mixed>  $attachment  the file's name as the form names it */
    private static function attachmentName(array $attachment): ?string
    {
        $name = self::string($attachment, 'media_file_basename');
        if ($name === null && ($file = self::string($attachment, 'filename')) !== null) {
            $name = basename($file);
        }

        return $name;
    }

    private function asset(): string
    {
        return '/api/v2/assets/'.rawurlencode((string) $this->client->config()->assetUid).'/';
    }

    /**
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     */
    private function object(string $path, string $what, array $query = []): array
    {
        $body = KoboClient::json($this->client->get($path, $query), $what);

        return array_is_list($body) && $body !== [] ? throw KoboUnexpectedResponse::malformed(200, $what) : $body;
    }

    /**
     * A MongoDB query document as Kobo reads it: JSON, slashes as they are.
     *
     * @param  array<string, mixed>  $value
     */
    private static function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Kobo's _submission_time format: UTC, to the second, no zone ("2019-09-01T01:02:03"). */
    private static function koboTime(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s');
    }

    /** @param  array<array-key, mixed>  $entry */
    private static function string(array $entry, string $key): ?string
    {
        return is_string($entry[$key] ?? null) && $entry[$key] !== '' ? $entry[$key] : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
