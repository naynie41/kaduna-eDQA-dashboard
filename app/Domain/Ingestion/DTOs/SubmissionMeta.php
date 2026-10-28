<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

use Carbon\CarbonImmutable;

/**
 * What a source says about a submission, in the common shape (ARCHITECTURE.md §5.2):
 *
 * - source: the source key (edqa.sources);
 * - externalId: the source's logical ID, the same for every version of an edited submission
 *   (ODK Central's __id, Kobo's _id);
 * - instanceId: this version (meta/instanceID); deprecatedId: the version it replaces;
 * - submittedAt / updatedAt: when it arrived and when it was last edited, null when unreadable;
 * - formVersion: the source's own label for the form version, as sent ('' when missing).
 */
final readonly class SubmissionMeta
{
    /** @param  list<EnvelopeAttachment>  $attachments */
    public function __construct(
        public string $source,
        public ?string $externalId,
        public string $instanceId,
        public ?string $deprecatedId,
        public ?CarbonImmutable $submittedAt,
        public ?CarbonImmutable $updatedAt,
        public string $formVersion,
        public ?string $submitter,
        public ?string $deviceId,
        public array $attachments = [],
    ) {}
}
