<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Actions;

use App\Domain\Ingestion\Models\SubmissionSource;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Keeps submission_sources in step with edqa.sources (ARCHITECTURE.md §5.0): a configured source
 * without a row gets one (enabled as config says); an existing row takes config's type and name.
 * is_enabled is set only when the row is created: after that it is the administrator's switch
 * (the env flag is the operator's, and a source pulls only with both on). A row whose key is no
 * longer configured is kept as it is, since its submissions refer to it. Never deletes. Each
 * change is audited (SubmissionSource is Audited).
 */
final class SyncSubmissionSources
{
    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    /** @return list<array{key: string, outcome: 'created'|'updated'|'unchanged'|'orphan', changes: list<string>}> */
    public function execute(): array
    {
        $report = DB::transaction(function (): array {
            $report = [];
            foreach ($this->sources->keys() as $key) {
                $config = $this->sources->config($key);
                $row = SubmissionSource::query()->where('key', $key)->lockForUpdate()->first();
                if ($row === null) {
                    SubmissionSource::query()->create(['key' => $key, 'type' => $config['type'], 'name' => $config['name'], 'is_enabled' => $config['enabled']]);
                    $report[] = ['key' => $key, 'outcome' => 'created', 'changes' => []];

                    continue;
                }

                $row->fill(['type' => $config['type'], 'name' => $config['name']]);
                $changes = array_keys($row->getDirty());
                $row->save();
                $report[] = ['key' => $key, 'outcome' => $changes === [] ? 'unchanged' : 'updated', 'changes' => $changes];
            }

            $orphans = SubmissionSource::query()->whereNotIn('key', $this->sources->keys())->orderBy('key')->pluck('key');
            foreach ($orphans as $key) {
                $report[] = ['key' => (string) $key, 'outcome' => 'orphan', 'changes' => []];
            }

            return $report;
        });
        $this->sources->flush();

        return $report;
    }
}
