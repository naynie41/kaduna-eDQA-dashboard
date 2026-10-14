<?php

declare(strict_types=1);

namespace App\Domain\Scoring;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use Illuminate\Support\Facades\DB;

/**
 * Which scoring rule version a round's scores are read and written under (D-32): a closed round
 * its pinned rounds.rule_version_id, an open round the current published one. Use it wherever a
 * score is read or written; never "the highest published version" directly.
 *
 * Takes a Round, or its status and pin (for callers holding a snapshot), so Scoring does not
 * depend on Validation's DTOs.
 */
final class RuleVersionForRound
{
    /** @var array<int, RuleConfig> configs of pinned versions, by id */
    private array $pinned = [];

    public function __construct(
        private readonly CurrentRuleVersion $current,
    ) {}

    public function id(Round $round): int
    {
        return $this->idFor($round->status, $round->rule_version_id);
    }

    public function config(Round $round): RuleConfig
    {
        return $this->configFor($round->status, $round->rule_version_id);
    }

    public function idFor(RoundStatus $status, ?int $pinnedId): int
    {
        if ($status === RoundStatus::Closed && $pinnedId !== null) {
            return $pinnedId;
        }

        // An open round, or a closed one without a pin (rounds_closed_has_rule_version forbids it).
        return $this->current->id() ?? throw NoPublishedRuleVersion::forOpenRound();
    }

    public function configFor(RoundStatus $status, ?int $pinnedId): RuleConfig
    {
        if ($status !== RoundStatus::Closed || $pinnedId === null) {
            return $this->current->config() ?? throw NoPublishedRuleVersion::forOpenRound();
        }

        if (! isset($this->pinned[$pinnedId])) {
            $config = DB::table('scoring_rule_versions')->where('id', $pinnedId)->value('config');
            $this->pinned[$pinnedId] = RuleConfig::fromArray((array) json_decode((string) $config, true, flags: JSON_THROW_ON_ERROR));
        }

        return $this->pinned[$pinnedId];
    }
}
