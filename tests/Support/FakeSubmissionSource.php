<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use Throwable;

/**
 * An in-memory ODK Central for the pull tests: holds OData entries and answers submissionsPage as
 * Central does (filter on the cursor field, ordered by it, $top and $skip, the total that matches).
 * Like Central, an edit replaces its row: add() with the same __id keeps one row per submission.
 * $failOn can throw on a given call, to simulate the source failing mid-pull. $files are the
 * attachments it lists and serves, by root ID.
 */
final class FakeSubmissionSource implements SubmissionSource
{
    /** @var array<string, array<string, mixed>> __id => entry */
    public array $rows = [];

    /** @var list<array{since: ?string, top: int, skip: int, inclusive: bool, by: string}> */
    public array $calls = [];

    /** @var (Closure(int): ?Throwable)|null  call number (1-based) => what to throw */
    public ?Closure $failOn = null;

    /** @var array<string, array<string, string>> root ID (__id) => filename => bytes */
    public array $files = [];

    /** @var list<string> "root/name" of every download asked for */
    public array $downloads = [];

    /** @var (Closure(string): ?Throwable)|null  filename => what a download of it throws */
    public ?Closure $failDownload = null;

    /** @param  array<string, mixed>  ...$entries */
    public function add(array ...$entries): self
    {
        foreach ($entries as $entry) {
            $this->rows[(string) $entry['__id']] = $entry;
        }

        return $this;
    }

    public function ping(): SourcePing
    {
        return new SourcePing('fake', 'fake', 'fake', '2026.1');
    }

    public function formVersions(): array
    {
        return [];
    }

    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip, bool $inclusive = false, string $by = 'submissionDate'): SubmissionPage
    {
        $this->calls[] = ['since' => $since?->utc()->format('Y-m-d\TH:i:s.v\Z'), 'top' => $top, 'skip' => $skip, 'inclusive' => $inclusive, 'by' => $by];
        $error = $this->failOn === null ? null : ($this->failOn)(count($this->calls));
        if ($error !== null) {
            throw $error;
        }

        $time = fn (array $row): ?float => is_string($row['__system'][$by] ?? null) ? (float) CarbonImmutable::parse($row['__system'][$by])->format('U.u') : null;
        $rows = array_values(array_filter($this->rows, function (array $row) use ($time, $since, $inclusive): bool {
            $t = $time($row);
            if ($t === null) {
                return false;
            }
            if ($since === null) {
                return true;
            }
            $s = (float) $since->format('U.u');

            return $inclusive ? $t >= $s : $t > $s;
        }));
        usort($rows, fn (array $a, array $b): int => [$time($a), $a['__id']] <=> [$time($b), $b['__id']]);

        $page = array_slice($rows, $skip, max(1, $top));

        return new SubmissionPage($page, count($rows), $skip + count($page) < count($rows));
    }

    public function attachmentList(string $instanceId): array
    {
        return array_map(fn (string $name): AttachmentRef => new AttachmentRef($name, true), array_keys($this->files[$instanceId] ?? []));
    }

    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload
    {
        $this->downloads[] = "{$instanceId}/{$name}";
        $error = $this->failDownload === null ? null : ($this->failDownload)($name);
        if ($error !== null) {
            throw $error;
        }
        $bytes = $this->files[$instanceId][$name] ?? throw new LogicException("No file {$name} for {$instanceId}.");
        file_put_contents($sinkPath, $bytes);

        return new AttachmentDownload($sinkPath, null, strlen($bytes));
    }
}
