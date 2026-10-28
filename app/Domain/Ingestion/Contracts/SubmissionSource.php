<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use App\Domain\Ingestion\Exceptions\SourceException;
use Carbon\CarbonImmutable;

/**
 * Where submissions come from (ARCHITECTURE.md §5.2): ODK Central today (OdkCentralSource). If the
 * client turns out to run ODK Aggregate (Q-01), a second adapter implements the same contract.
 * Read-only: nothing here writes to the source (SECURITY.md §6).
 *
 * Every method throws a SourceException subclass, never a transport exception: the source's
 * NotConfigured when credentials are missing, AuthFailed, Unreachable or UnexpectedResponse
 * (Odk… on ODK Central, Kobo… on KoboToolbox).
 */
interface SubmissionSource
{
    /**
     * Signs in and reads the project and form: proves the credentials and the role.
     *
     * @throws SourceException
     */
    public function ping(): SourcePing;

    /**
     * Every published version of the form.
     *
     * @return list<FormVersion>
     *
     * @throws SourceException
     */
    public function formVersions(): array;

    /**
     * One page of submissions received after $since (at or after when $inclusive; all when
     * null), ordered by when they were received, oldest first, with the total that matches.
     *
     * @throws SourceException
     */
    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip, bool $inclusive = false): SubmissionPage;

    /**
     * One page of submissions received or edited at or after $cursor (ARCHITECTURE.md §5.3, D-23
     * in the new numbering): an edit keeps its submission date, so a pull on that date alone would
     * miss it. Ordered by the edit time (unedited ones after the edited), then the logical ID, with
     * the total that matches. With no cursor yet, every edited submission.
     *
     * @throws SourceException
     */
    public function changedSince(?CarbonImmutable $cursor, int $top, int $skip): SubmissionPage;

    /**
     * The attachments one submission expects, and whether each has arrived.
     *
     * @return list<AttachmentRef>
     *
     * @throws SourceException
     */
    public function attachmentList(string $instanceId): array;

    /**
     * Streams one attachment to $sinkPath (the caller chooses and checks the path).
     *
     * @throws SourceException
     */
    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload;
}
