<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Scoring\DTOs\RuleConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The current scoring rule version: the highest published one (ARCHITECTURE.md §7). Cached until
 * any rule version is written (ScoringRuleVersion forgets it on save), so a publish takes effect
 * at once. Null when nothing is published.
 */
final class CurrentRuleVersion
{
    public const CACHE_KEY = 'edqa.scoring.current_rule_version';

    public function id(): ?int
    {
        return $this->resolve()['id'];
    }

    public function config(): ?RuleConfig
    {
        $config = $this->resolve()['config'];

        return $config === null ? null : RuleConfig::fromArray($config);
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{id: ?int, config: ?array<string, mixed>} */
    private function resolve(): array
    {
        /** @var array{id: ?int, config: ?array<string, mixed>} */
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $row = DB::table('scoring_rule_versions')
                ->whereNotNull('published_at')
                ->orderByDesc('version')
                ->first(['id', 'config']);

            if ($row === null) {
                return ['id' => null, 'config' => null];
            }

            /** @var array<string, mixed> $config */
            $config = json_decode((string) $row->config, true, flags: JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->id, 'config' => $config];
        });
    }
}
