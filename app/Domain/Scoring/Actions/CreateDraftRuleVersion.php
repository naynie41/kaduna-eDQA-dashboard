<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * "Edit → new draft version" (ARCHITECTURE.md §7): copies a version's config, replaces the
 * top-level keys given (bands, item_weights, na_policy, choice_map: each replaced whole), and
 * saves it as the next version number, unpublished. The config must be one the calculator can
 * apply (InvalidRuleConfig otherwise). At most one draft exists at a time; the
 * scoring_rule_versions_one_draft index enforces it under concurrency too.
 */
final class CreateDraftRuleVersion
{
    /** @param  array<string, mixed>  $changes */
    public function execute(ScoringRuleVersion $from, array $changes, User $actor): ScoringRuleVersion
    {
        foreach (array_keys($changes) as $key) {
            if (! in_array($key, RuleConfig::KEYS, true)) {
                throw RuleVersionRefused::unknownConfigKey((string) $key);
            }
        }
        $config = RuleConfig::fromArray(array_replace($from->config, $changes))->toArray();

        try {
            return DB::transaction(function () use ($from, $config, $actor): ScoringRuleVersion {
                if (ScoringRuleVersion::query()->whereNull('published_at')->lockForUpdate()->exists()) {
                    throw RuleVersionRefused::draftExists();
                }

                $draft = activity()->withoutLogs(fn (): ScoringRuleVersion => ScoringRuleVersion::query()->create([
                    'version' => (int) ScoringRuleVersion::query()->max('version') + 1,
                    'config' => $config,
                    'created_by' => $actor->id,
                ]));

                AuditLog::record($draft, $actor, 'drafted',
                    old: [],
                    new: ['version' => $draft->version, 'config' => $config],
                    extra: ['from_version' => $from->version],
                );

                return $draft;
            });
        } catch (UniqueConstraintViolationException) {
            throw RuleVersionRefused::draftExists();
        }
    }
}
