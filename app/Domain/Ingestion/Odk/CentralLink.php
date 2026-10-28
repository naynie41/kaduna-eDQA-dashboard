<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Odk;

/**
 * A submission's page in ODK Central (D-42): {ODK_CENTRAL_URL}/#/projects/{ODK_PROJECT_ID}/forms/
 * {form}/submissions/{instance}, or null while the URL or project is not configured. For the
 * facility drawer and the Data issues page.
 */
final class CentralLink
{
    public static function submission(string $formId, string $instanceId): ?string
    {
        $base = config('edqa.sources.odk.url');
        $project = config('edqa.sources.odk.project_id');
        if (! is_string($base) || $base === '' || $project === null || $project === '') {
            return null;
        }

        return rtrim($base, '/').'/#/projects/'.rawurlencode((string) $project)
            .'/forms/'.rawurlencode($formId).'/submissions/'.rawurlencode($instanceId);
    }
}
