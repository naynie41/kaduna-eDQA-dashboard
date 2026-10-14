<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Events\RuleVersionPublished;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Publish" (ARCHITECTURE.md §7; SECURITY.md §4 mandatory reason): the draft becomes the current
 * version for open rounds. Closed rounds keep their pinned version (D-32). One audit entry with
 * the reason; RuleVersionPublished then asks for the open rounds to be rescored.
 */
final class PublishRuleVersion
{
    public const MIN_REASON = 10;

    public function execute(ScoringRuleVersion $draft, User $actor, string $reason): ScoringRuleVersion
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw RuleVersionRefused::reasonTooShort();
        }

        return DB::transaction(function () use ($draft, $actor, $reason): ScoringRuleVersion {
            $draft = ScoringRuleVersion::query()->lockForUpdate()->findOrFail($draft->id);
            if ($draft->published_at !== null) {
                throw RuleVersionRefused::notADraft();
            }
            RuleConfig::fromVersion($draft); // never publish a config the calculator cannot apply

            $publishedAt = now();
            activity()->withoutLogs(fn () => $draft->update(['published_at' => $publishedAt, 'published_reason' => $reason]));

            AuditLog::record($draft, $actor, 'published',
                old: ['published_at' => null, 'published_reason' => null],
                new: ['published_at' => $publishedAt->toIso8601String(), 'published_reason' => $reason],
                reason: $reason,
            );

            RuleVersionPublished::dispatch($draft->id);

            return $draft;
        });
    }
}
