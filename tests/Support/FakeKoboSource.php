<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;
use Throwable;

/**
 * An in-memory KoboToolbox for the pull tests: holds KPI v2 data rows and answers submissionsPage
 * as KoboSource asks the API (_submission_time at or after the cursor, sorted by it then _id,
 * start and limit, the count that matches). Like Kobo, an edit replaces its row in place: add()
 * with the same _id keeps one row, its _submission_time unchanged by the caller. remove() is a
 * deletion at source. changedSince finds nothing, as on Kobo. $failOn can throw on a given call.
 */
final class FakeKoboSource implements SubmissionSource
{
    /** @var array<int, array<string, mixed>> _id => row */
    public array $rows = [];

    /** @var list<array{since: ?string, top: int, skip: int, inclusive: bool}> */
    public array $calls = [];

    /** @var (Closure(int): ?Throwable)|null  call number (1-based) => what to throw */
    public ?Closure $failOn = null;

    /** @param  array<string, mixed>  ...$rows */
    public function add(array ...$rows): self
    {
        foreach ($rows as $row) {
            $this->rows[(int) $row['_id']] = $row;
        }

        return $this;
    }

    public function remove(int $id): self
    {
        unset($this->rows[$id]);

        return $this;
    }

    public function ping(): SourcePing
    {
        return new SourcePing('fake', 'fake', 'fake', 'vKd26Q1aBcDeFgHiJkLmNo');
    }

    public function formVersions(): array
    {
        return [];
    }

    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip, bool $inclusive = false): SubmissionPage
    {
        $this->calls[] = ['since' => $since?->utc()->format('Y-m-d\TH:i:s'), 'top' => $top, 'skip' => $skip, 'inclusive' => $inclusive];
        $error = $this->failOn === null ? null : ($this->failOn)(count($this->calls));
        if ($error !== null) {
            throw $error;
        }

        // Kobo compares the naive UTC text, to the second.
        $cursor = $since?->utc()->format('Y-m-d\TH:i:s');
        $rows = array_values(array_filter($this->rows, fn (array $row): bool => $cursor === null
            || ($inclusive ? (string) $row['_submission_time'] >= $cursor : (string) $row['_submission_time'] > $cursor)));
        usort($rows, fn (array $a, array $b): int => [(string) $a['_submission_time'], (int) $a['_id']] <=> [(string) $b['_submission_time'], (int) $b['_id']]);

        $page = array_slice($rows, $skip, max(1, $top));

        return new SubmissionPage($page, count($rows), $skip + count($page) < count($rows));
    }

    public function changedSince(?CarbonImmutable $cursor, int $top, int $skip): SubmissionPage
    {
        return new SubmissionPage([], 0, false);
    }

    public function attachmentList(string $instanceId): array
    {
        return [];
    }

    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload
    {
        throw new LogicException("No file {$name} for {$instanceId}.");
    }
}
