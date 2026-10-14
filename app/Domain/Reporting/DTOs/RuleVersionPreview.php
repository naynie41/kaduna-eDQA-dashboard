<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

/** The preview of publishing one draft: one entry per open round. */
final readonly class RuleVersionPreview
{
    /** @param  list<RoundPreview>  $rounds */
    public function __construct(
        public int $ruleVersionId,
        public array $rounds,
    ) {}

    /** @return array{rule_version_id: int, rounds: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'rule_version_id' => $this->ruleVersionId,
            'rounds' => array_map(fn (RoundPreview $r): array => $r->toArray(), $this->rounds),
        ];
    }
}
