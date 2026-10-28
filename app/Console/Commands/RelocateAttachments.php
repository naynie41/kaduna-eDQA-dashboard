<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Actions\RelocateAttachments as Relocate;
use Illuminate\Console\Command;

/** One-off (Phase 2C): ODK attachments to storage/app/attachments/{source}/{instance}/. Safe to run again. */
final class RelocateAttachments extends Command
{
    protected $signature = 'edqa:attachments:relocate';

    protected $description = 'Move fetched attachments to the per-source attachments disk (idempotent)';

    public function handle(Relocate $relocate): int
    {
        $report = $relocate->execute();
        $this->line((string) __('sources.relocate.done', ['moved' => (string) $report['moved'], 'already' => (string) $report['already']]));
        if ($report['missing'] !== []) {
            $this->warn((string) __('sources.relocate.missing', ['count' => (string) count($report['missing']), 'ids' => implode(', ', $report['missing'])]));
        }

        return self::SUCCESS;
    }
}
