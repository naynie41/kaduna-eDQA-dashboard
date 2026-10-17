<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

use App\Domain\Ingestion\DTOs\AttachmentDownload;
use App\Domain\Ingestion\DTOs\AttachmentRef;
use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\DTOs\SourcePing;
use App\Domain\Ingestion\DTOs\SubmissionPage;
use App\Domain\Ingestion\Exceptions\OdkException;
use Carbon\CarbonImmutable;

/**
 * Where submissions come from (ARCHITECTURE.md §5.2): ODK Central today (OdkCentralSource). If the
 * client turns out to run ODK Aggregate (Q-01), a second adapter implements the same contract.
 * Read-only: nothing here writes to the source (SECURITY.md §6).
 *
 * Every method throws an OdkException subclass, never a transport exception: OdkNotConfigured
 * when credentials are missing, OdkAuthFailed, OdkUnreachable or OdkUnexpectedResponse.
 */
interface SubmissionSource
{
    /**
     * Signs in and reads the project and form: proves the credentials and the role.
     *
     * @throws OdkException
     */
    public function ping(): SourcePing;

    /**
     * Every published version of the form.
     *
     * @return list<FormVersion>
     *
     * @throws OdkException
     */
    public function formVersions(): array;

    /**
     * One page of submissions received after $since (all when null), oldest first as the source
     * serves them, with the total that matches.
     *
     * @throws OdkException
     */
    public function submissionsPage(?CarbonImmutable $since, int $top, int $skip): SubmissionPage;

    /**
     * The attachments one submission expects, and whether each has arrived.
     *
     * @return list<AttachmentRef>
     *
     * @throws OdkException
     */
    public function attachmentList(string $instanceId): array;

    /**
     * Streams one attachment to $sinkPath (the caller chooses and checks the path).
     *
     * @throws OdkException
     */
    public function downloadAttachment(string $instanceId, string $name, string $sinkPath): AttachmentDownload;
}
