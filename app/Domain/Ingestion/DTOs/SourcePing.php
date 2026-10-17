<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

/** What a successful ping saw: who the service account is, and the project and form it reads. */
final readonly class SourcePing
{
    public function __construct(
        public string $account,
        public string $project,
        public string $form,
        public ?string $currentVersion,
    ) {}
}
