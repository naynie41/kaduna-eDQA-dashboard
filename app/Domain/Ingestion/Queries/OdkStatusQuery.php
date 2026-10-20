<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Models\OdkFormSync;
use App\Domain\Ingestion\Odk\OdkConfig;
use Illuminate\Support\Facades\Cache;

/**
 * The ingestion half of GET /api/odk/status (ARCHITECTURE.md §8): whether ODK Central is
 * configured (and which env keys are missing, never their values), whether it answers, where the
 * cursors stand, and the pull in progress. Reachability is a real sign-in and project read
 * (SubmissionSource::ping), cached for REACHABLE_SECONDS so a page polling the status does not
 * call Central each time.
 */
final class OdkStatusQuery
{
    public const REACHABLE_SECONDS = 60;

    public const CACHE_KEY = 'edqa.odk.reachable';

    public function __construct(
        private readonly SubmissionSource $source,
    ) {}

    /**
     * @return array{configured: bool, problems: list<string>, reachable: ?array{ok: bool, status: string, message: ?string, checked_at: string}, cursor: ?array{last_pulled_at: ?string, last_edit_at: ?string, last_instance_id: ?string, backfill_skip: int, submission_count: int}, running_run_id: ?int}
     */
    public function status(): array
    {
        $config = OdkConfig::fromConfig();
        $configured = $config->isConfigured();
        $sync = $configured
            ? OdkFormSync::query()->where('project_id', (int) $config->projectId)->where('form_id', (string) $config->formId)->first()
            : null;

        return [
            'configured' => $configured,
            'problems' => $config->problems(),
            'reachable' => $configured ? $this->reachable() : null,
            'cursor' => $sync === null ? null : [
                'last_pulled_at' => $sync->last_pulled_at?->toIso8601String(),
                'last_edit_at' => $sync->last_edit_at?->toIso8601String(),
                'last_instance_id' => is_string($sync->last_instance_id) ? $sync->last_instance_id : null,
                'backfill_skip' => (int) $sync->backfill_skip,
                'submission_count' => (int) $sync->submission_count,
            ],
            'running_run_id' => StartManualPull::running()?->id,
        ];
    }

    /** @return array{ok: bool, status: string, message: ?string, checked_at: string} */
    private function reachable(): array
    {
        /** @var array{ok: bool, status: string, message: ?string, checked_at: string} */
        return Cache::remember(self::CACHE_KEY, self::REACHABLE_SECONDS, function (): array {
            try {
                $this->source->ping();

                return ['ok' => true, 'status' => 'ok', 'message' => null, 'checked_at' => now()->toIso8601String()];
            } catch (OdkException $e) {
                return ['ok' => false, 'status' => $e->status(), 'message' => $e->getMessage(), 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
