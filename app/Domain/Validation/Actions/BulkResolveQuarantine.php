<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Bulk resolve" (ARCHITECTURE.md §6 step 4): one resolution and one reason applied to many
 * records that fail the same rules (the same set of hard-failure codes). Each record is resolved
 * by the single-record action, so each gets its own audit entry. Atomic: any refusal rolls the
 * whole batch back. Soft flags are overridden per assessment, never in bulk.
 */
final class BulkResolveQuarantine
{
    public const ACTIONS = ['correct', 'reject'];

    public function __construct(
        private readonly CorrectAndReprocess $correct,
        private readonly RejectQuarantinedRecord $reject,
    ) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $corrections  for 'correct'
     * @return list<QuarantinedRecord> the records after resolution, in id order
     */
    public function execute(array $ids, string $action, array $corrections, User $actor, string $reason): array
    {
        $reason = Reason::require($reason);
        if (! in_array($action, self::ACTIONS, true)) {
            throw QuarantineActionRefused::unknownAction($action);
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            throw QuarantineActionRefused::emptyBulk();
        }
        if ($action === 'correct') {
            CorrectAndReprocess::validate($corrections);
        }

        return DB::transaction(function () use ($ids, $action, $corrections, $actor, $reason): array {
            $records = QuarantinedRecord::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

            foreach ($ids as $id) {
                $record = $records->firstWhere('id', $id);
                if ($record === null) {
                    throw QuarantineActionRefused::recordNotFound($id);
                }
                if ($record->status !== QuarantineStatus::Open) {
                    throw QuarantineActionRefused::alreadyResolved();
                }
            }
            if ($records->map(fn (QuarantinedRecord $r): string => implode(',', self::hardCodes($r)))->unique()->count() !== 1) {
                throw QuarantineActionRefused::mixedRules();
            }

            $resolved = [];
            foreach ($records as $record) {
                $action === 'correct'
                    ? $this->correct->execute($record, $corrections, $actor, $reason)
                    : $this->reject->execute($record, $actor, $reason);
                $resolved[] = $record->refresh();
            }

            return $resolved;
        });
    }

    /** @return list<string> the record's hard-failure codes, sorted */
    private static function hardCodes(QuarantinedRecord $record): array
    {
        $codes = [];
        foreach ($record->failures as $failure) {
            if (($failure['severity'] ?? null) === 'hard' && is_string($failure['code'] ?? null)) {
                $codes[] = $failure['code'];
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }
}
