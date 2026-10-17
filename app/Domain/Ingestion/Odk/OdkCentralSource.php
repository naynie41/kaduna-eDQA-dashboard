<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use App\Domain\Ingestion\Exceptions\OdkUnexpectedResponse;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * SubmissionSource over ODK Central's REST and OData API (ARCHITECTURE.md §5.2–5.3), for the one
 * project and form in config. Every body is checked for the shape the API documents; anything else
 * is OdkUnexpectedResponse, never a guess.
 */
final class OdkCentralSource implements SubmissionSource
{
    public function __construct(
        private readonly OdkCentralClient $client,
    ) {}

    public function ping(): SourcePing
    {
        $account = $this->object('/v1/users/current');
        $project = $this->object($this->project());
        $form = $this->object($this->form());

        return new SourcePing(
            account: self::string($account, 'displayName') ?? self::string($account, 'email') ?? '',
            project: self::string($project, 'name') ?? '',
            form: self::string($form, 'name') ?? self::string($form, 'xmlFormId') ?? '',
            currentVersion: self::string($form, 'version'),
        );
    }

    public function formVersions(): array
    {
        $path = $this->form().'/versions';
        $versions = [];
        foreach ($this->list($path) as $entry) {
            $version = is_array($entry) ? self::string($entry, 'version') : null;
            if ($version === null) {
                throw OdkUnexpectedResponse::malformed(200, 'forms/{form}/versions');
            }
            $versions[] = new FormVersion($version, self::time($entry['publishedAt'] ?? null));
        }

        return $versions;
    }

    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip): SubmissionPage
    {
        $path = $this->form().'.svc/Submissions';
        $query = [];
        if ($since !== null) {
            // OData DateTimeOffset literal, UTC with milliseconds, as Central stores submissionDate.
            $query['$filter'] = '__system/submissionDate gt '.$since->utc()->format('Y-m-d\TH:i:s.v\Z');
        }
        $query += ['$top' => max(1, $top), '$skip' => max(0, $skip), '$count' => 'true', '$expand' => '*'];

        $body = OdkCentralClient::json($this->client->get($path, $query), $path);
        $rows = $body['value'] ?? null;
        $total = $body['@odata.count'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || ! is_int($total)) {
            throw OdkUnexpectedResponse::malformed(200, 'forms/{form}.svc/Submissions');
        }
        $entries = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw OdkUnexpectedResponse::malformed(200, 'forms/{form}.svc/Submissions');
            }
            $entries[] = $row;
        }

        return new SubmissionPage($entries, $total, $skip + count($entries) < $total);
    }

    public function attachmentList(string $instanceId): array
    {
        $refs = [];
        foreach ($this->list($this->form().'/submissions/'.rawurlencode($instanceId).'/attachments') as $entry) {
            $name = is_array($entry) ? self::string($entry, 'name') : null;
            if ($name === null) {
                throw OdkUnexpectedResponse::malformed(200, 'submissions/{instance}/attachments');
            }
            $refs[] = new AttachmentRef($name, ($entry['exists'] ?? false) === true);
        }

        return $refs;
    }

    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload
    {
        $path = $this->form().'/submissions/'.rawurlencode($instanceId).'/attachments/'.rawurlencode($name);
        $response = $this->client->get($path, sink: $sinkPath);
        $type = $response->header('Content-Type');

        return new AttachmentDownload($sinkPath, $type === '' ? null : trim(explode(';', $type)[0]), is_file($sinkPath) ? (int) filesize($sinkPath) : 0);
    }

    private function project(): string
    {
        return '/v1/projects/'.rawurlencode((string) $this->client->config()->projectId);
    }

    private function form(): string
    {
        return $this->project().'/forms/'.rawurlencode((string) $this->client->config()->formId);
    }

    /** @return array<array-key, mixed> */
    private function object(string $path): array
    {
        $body = OdkCentralClient::json($this->client->get($path), $path);

        return array_is_list($body) && $body !== [] ? throw OdkUnexpectedResponse::malformed(200, 'object') : $body;
    }

    /** @return list<mixed> */
    private function list(string $path): array
    {
        $body = OdkCentralClient::json($this->client->get($path), $path);

        return array_is_list($body) ? $body : throw OdkUnexpectedResponse::malformed(200, 'list');
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
