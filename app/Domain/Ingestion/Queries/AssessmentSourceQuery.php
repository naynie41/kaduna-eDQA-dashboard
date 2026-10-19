<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\DTOs\AssessmentSource;
use App\Domain\Ingestion\Enums\AttachmentStatus;
use App\Domain\Ingestion\Models\OdkAttachment;
use App\Domain\Ingestion\Models\Submission;
use Illuminate\Container\Attributes\Config;

/**
 * Where an accepted assessment came from: its ODK instance ID, a link to the submission in ODK
 * Central, and its attachments (the facility drawer, ARCHITECTURE.md §9.2.7), each fetched one
 * with the authenticated route that serves it (SECURITY.md §7).
 *
 * Lives in Ingestion because it reads submissions, which Reporting may not (CLAUDE.md hard rule 6).
 * It reads only the submission an assessment points at, which passed every hard rule: never a
 * quarantined one, and never the payload.
 */
final class AssessmentSourceQuery
{
    public function __construct(
        #[Config('edqa.odk.url')] private readonly ?string $baseUrl,
        #[Config('edqa.odk.project_id')] private readonly int|string|null $projectId,
    ) {}

    public function forSubmission(int $submissionId): ?AssessmentSource
    {
        $submission = Submission::query()->whereKey($submissionId)->first(['id', 'instance_id', 'form_id']);
        if ($submission === null) {
            return null;
        }

        $attachments = OdkAttachment::query()
            ->where('submission_id', $submissionId)
            ->orderBy('id')
            ->get(['id', 'submission_id', 'filename', 'mime', 'size', 'status', 'reason'])
            ->map(fn (OdkAttachment $attachment): array => [
                'id' => $attachment->id,
                'filename' => (string) $attachment->filename,
                'mime' => $attachment->mime,
                'size' => $attachment->size,
                'status' => $attachment->status->value,
                'reason' => is_string($attachment->reason) ? $attachment->reason : null,
                'url' => $attachment->status === AttachmentStatus::Fetched
                    ? route('attachments.show', ['submission' => $submissionId, 'filename' => (string) $attachment->filename], absolute: false)
                    : null,
            ])
            ->values()
            ->all();

        return new AssessmentSource(
            (string) $submission->instance_id,
            $this->centralUrl((string) $submission->form_id, (string) $submission->instance_id),
            $attachments,
        );
    }

    /** Central's submission page; null until ODK Central's URL and project are configured. */
    private function centralUrl(string $formId, string $instanceId): ?string
    {
        if ($this->baseUrl === null || $this->baseUrl === '' || $this->projectId === null || $this->projectId === '') {
            return null;
        }

        return rtrim($this->baseUrl, '/').'/#/projects/'.rawurlencode((string) $this->projectId)
            .'/forms/'.rawurlencode($formId).'/submissions/'.rawurlencode($instanceId);
    }
}
