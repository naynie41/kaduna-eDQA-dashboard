<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Contracts\SubmissionSource;
use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\Exceptions\OdkException;
use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Ingestion\Odk\OdkConfig;
use Illuminate\Console\Command;

/**
 * The first command to run when ODK credentials arrive (ARCHITECTURE.md §11.5): signs in, reads the
 * project and form, and lists every published form version with whether the portal has a field map
 * for it (a version without one is quarantined as UNKNOWN_FORM_VERSION). Read-only. Exit 0 only
 * when connected. --json for scripts. Never prints the password or the token.
 */
final class OdkCheck extends Command
{
    protected $signature = 'edqa:odk:check {--json : Print the result as JSON}';

    protected $description = 'Check the ODK Central connection and list the form versions';

    public function handle(SubmissionSource $source, OdkConfig $config): int
    {
        $result = [
            'configured' => $config->isConfigured(),
            'problems' => $config->problems(),
            'server' => ['url' => $config->url, 'project_id' => $config->projectId, 'form_id' => $config->formId],
            'status' => 'not_configured',
            'message' => null,
            'account' => null,
            'project' => null,
            'form' => null,
            'current_version' => null,
            'versions' => [],
        ];

        if ($config->isConfigured()) {
            try {
                $ping = $source->ping();
                $versions = $source->formVersions();
                $result = [...$result, 'status' => 'ok', 'account' => $ping->account, 'project' => $ping->project, 'form' => $ping->form, 'current_version' => $ping->currentVersion,
                    'versions' => array_map(fn (FormVersion $v): array => [
                        'version' => $v->version,
                        'published_at' => $v->publishedAt?->toIso8601String(),
                        'mapped' => FieldMap::resolve($v->version) !== null,
                    ], $versions),
                ];
            } catch (OdkException $e) {
                $result = [...$result, 'status' => $e->status(), 'message' => $e->getMessage()];
            }
        }

        $this->option('json') ? $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) : $this->report($result);

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $result */
    private function report(array $result): void
    {
        $this->line((string) __('odk.check.title'));
        $server = is_array($result['server']) ? $result['server'] : [];
        $none = (string) __('odk.check.none');
        $this->line((string) __('odk.check.server', ['url' => $server['url'] ?? $none, 'project' => $server['project_id'] ?? $none, 'form' => $server['form_id'] ?? $none]));

        if ($result['status'] === 'not_configured') {
            $this->error((string) __('odk.check.not_configured', ['problems' => implode(', ', is_array($result['problems']) ? $result['problems'] : [])]));

            return;
        }
        if ($result['status'] !== 'ok') {
            $this->error((string) __('odk.check.failed', ['status' => (string) $result['status'], 'message' => (string) $result['message']]));

            return;
        }

        $this->info((string) __('odk.check.ok', ['account' => (string) $result['account'], 'project' => (string) $result['project'], 'form' => (string) $result['form'], 'version' => (string) ($result['current_version'] ?? $none)]));
        $versions = is_array($result['versions']) ? $result['versions'] : [];
        $this->line((string) __('odk.check.versions', ['count' => count($versions)]));
        $unmapped = 0;
        foreach ($versions as $version) {
            $mapped = (bool) $version['mapped'];
            $unmapped += $mapped ? 0 : 1;
            $this->line((string) __($mapped ? 'odk.check.version_mapped' : 'odk.check.version_unmapped', [
                'version' => (string) $version['version'],
                'published' => (string) ($version['published_at'] ?? __('odk.check.unknown_date')),
            ]));
        }
        if ($unmapped > 0) {
            $this->warn((string) __('odk.check.unmapped_summary', ['count' => $unmapped]));
        }
    }
}
