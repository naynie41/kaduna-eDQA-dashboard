<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\DTOs\FormVersion;
use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Kobo\KoboConfig;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Console\Command;

/**
 * The first command when credentials arrive (ARCHITECTURE.md §5.0, the credentials-day runbook):
 * per source, whether it is configured (the env keys missing, never their values), whether it
 * answers and accepts the credentials (signing in reads the account and the form), and its form
 * versions, each marked with whether a field map reads it. Sends nothing for a source that is not
 * configured. Replaces edqa:odk:check, which stays as an alias for one release.
 */
final class SourceCheck extends Command
{
    protected $signature = 'edqa:source:check
        {source? : A source key (odk, kobo); every configured source when left out}
        {--all : Every configured source}
        {--json : Print the result as JSON}';

    protected $description = 'Check each field-collection source: configured, reachable, signed in, and its form versions';

    public function handle(SourceRegistry $sources): int
    {
        $key = $this->argument('source');
        if (is_string($key) && ! in_array($key, $sources->keys(), true)) {
            $this->error((string) __('sources.check.unknown', ['key' => $key, 'keys' => implode(', ', $sources->keys())]));

            return self::FAILURE;
        }
        $keys = is_string($key) && ! $this->option('all') ? [$key] : $sources->keys();

        $results = array_map(fn (string $k): array => $this->check($sources, $k), $keys);

        if ($this->option('json')) {
            $out = count($keys) === 1 && is_string($key) && ! $this->option('all') ? $results[0] : ['sources' => $results];
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($results as $i => $result) {
                if ($i > 0) {
                    $this->newLine();
                }
                $this->report($result);
            }
        }

        return array_filter($results, fn (array $r): bool => $r['status'] !== 'ok') === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function check(SourceRegistry $sources, string $key): array
    {
        $config = $sources->config($key);
        $problems = $sources->problems($key);
        $result = [
            'source' => $key,
            'name' => $config['name'],
            'type' => $config['type']->value,
            'configured' => $problems === [],
            'problems' => $problems,
            'server' => self::server($config['type']),
            'status' => 'not_configured',
            'message' => null,
            'account' => null,
            'project' => null,
            'form' => null,
            'current_version' => null,
            'versions' => [],
        ];
        if ($problems !== []) {
            return $result;
        }

        try {
            $adapter = $sources->adapter($key);
            $ping = $adapter->ping();
            $versions = $adapter->formVersions();
        } catch (SourceException $e) {
            return [...$result, 'status' => $e->status(), 'message' => $e->getMessage()];
        } catch (SourceRefused $e) {
            return [...$result, 'status' => $e->errorCode, 'message' => $e->getMessage()];
        }

        return [...$result, 'status' => 'ok', 'account' => $ping->account, 'project' => $ping->project, 'form' => $ping->form, 'current_version' => $ping->currentVersion,
            'versions' => array_map(fn (FormVersion $v): array => [
                'version' => $v->version,
                'published_at' => $v->publishedAt?->toIso8601String(),
                'mapped' => FieldMap::for($key, $v->version) !== null,
            ], $versions),
        ];
    }

    /**
     * Where the source is, never a credential.
     *
     * @return array<string, string|null>
     */
    private static function server(SourceType $type): array
    {
        return match ($type) {
            SourceType::OdkCentral => (function (): array {
                $odk = OdkConfig::fromConfig();

                return ['url' => $odk->url, 'project_id' => $odk->projectId, 'form_id' => $odk->formId];
            })(),
            SourceType::Kobo => (function (): array {
                $kobo = KoboConfig::fromConfig();

                return ['url' => $kobo->url, 'asset_uid' => $kobo->assetUid];
            })(),
        };
    }

    /** @param  array<string, mixed>  $result */
    private function report(array $result): void
    {
        $none = (string) __('sources.check.none');
        $this->line((string) $result['name']);
        $server = is_array($result['server']) ? $result['server'] : [];
        $this->line($result['type'] === SourceType::Kobo->value
            ? (string) __('sources.check.server_kobo', ['url' => $server['url'] ?? $none, 'asset' => $server['asset_uid'] ?? $none])
            : (string) __('sources.check.server_odk', ['url' => $server['url'] ?? $none, 'project' => $server['project_id'] ?? $none, 'form' => $server['form_id'] ?? $none]));

        if ($result['status'] === 'not_configured') {
            $this->error((string) __('sources.check.not_configured', ['problems' => implode(', ', is_array($result['problems']) ? $result['problems'] : [])]));

            return;
        }
        if ($result['status'] !== 'ok') {
            $this->error((string) __('sources.check.failed', ['status' => (string) $result['status'], 'message' => (string) $result['message']]));

            return;
        }

        $this->info((string) __('sources.check.ok', ['account' => (string) $result['account'], 'project' => (string) $result['project'], 'form' => (string) $result['form'], 'version' => (string) ($result['current_version'] ?? $none)]));
        $versions = is_array($result['versions']) ? $result['versions'] : [];
        $this->line((string) __('sources.check.versions', ['count' => count($versions)]));
        $unmapped = 0;
        foreach ($versions as $version) {
            $mapped = (bool) $version['mapped'];
            $unmapped += $mapped ? 0 : 1;
            $this->line((string) __($mapped ? 'sources.check.version_mapped' : 'sources.check.version_unmapped', [
                'version' => (string) $version['version'],
                'published' => (string) ($version['published_at'] ?? __('sources.check.unknown_date')),
            ]));
        }
        if ($unmapped > 0) {
            $this->warn((string) __('sources.check.unmapped_summary', ['count' => $unmapped]));
        }
    }
}
