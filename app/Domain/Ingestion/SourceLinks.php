<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Kobo\KoboConfig;
use App\Domain\Ingestion\Odk\OdkConfig;

/**
 * A link to a submission on its own source, for the facility drawer and Data issues
 * (ARCHITECTURE.md §9.2.7, §9.3, SECURITY.md §6: deep links). Built only from the configured base
 * URL and IDs that pass their pattern, never from a payload string; anything that fails gives
 * null (no link), never a guess.
 *
 * - ODK Central: {ODK_CENTRAL_URL}/#/projects/{ODK_PROJECT_ID}/forms/{ODK_FORM_ID}/submissions/
 *   {instance}, where the instance is the submission's logical ID (external_id, Central's __id),
 *   which stays the same across edits. ^uuid:[0-9a-f-]{36}$; project digits; form ID
 *   [A-Za-z0-9_.-].
 * - KoboToolbox: {KOBO_BASE_URL}/api/v2/assets/{KOBO_ASSET_UID}/data/{_id}/, the submission's
 *   record (Kobo's web app has no address for one submission). _id numeric; asset UID
 *   alphanumeric.
 *
 * The base URL must be https (http only for a local mock, as the client allows), with no user,
 * query or fragment; it is rebuilt from its parts.
 */
final class SourceLinks
{
    public const INSTANCE_PATTERN = '/^uuid:[0-9a-f-]{36}$/D';

    public function __construct(
        private readonly SourceRegistry $sources,
    ) {}

    public function submission(string $source, ?string $externalId): ?string
    {
        if ($externalId === null) {
            return null;
        }
        try {
            $type = $this->sources->type($source);
        } catch (SourceRefused) {
            return null;
        }

        return match ($type) {
            SourceType::OdkCentral => self::odk(OdkConfig::fromConfig(), $externalId),
            SourceType::Kobo => self::kobo(KoboConfig::fromConfig(), $externalId),
        };
    }

    private static function odk(OdkConfig $config, string $instanceId): ?string
    {
        $base = self::base($config->url, $config->allowInsecureLocal && $config->environment === 'local');
        if ($base === null
            || preg_match(self::INSTANCE_PATTERN, $instanceId) !== 1
            || preg_match('/^[1-9]\d{0,9}$/D', (string) $config->projectId) !== 1
            || preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', (string) $config->formId) !== 1) {
            return null;
        }

        return $base.'/#/projects/'.$config->projectId.'/forms/'.rawurlencode((string) $config->formId)
            .'/submissions/'.rawurlencode($instanceId);
    }

    private static function kobo(KoboConfig $config, string $id): ?string
    {
        $base = self::base($config->url, $config->allowInsecureLocal && $config->environment === 'local');
        if ($base === null
            || preg_match('/^[1-9]\d{0,17}$/D', $id) !== 1
            || preg_match('/^[A-Za-z0-9]{1,64}$/D', (string) $config->assetUid) !== 1) {
            return null;
        }

        return $base.'/api/v2/assets/'.$config->assetUid.'/data/'.$id.'/';
    }

    /** scheme://host[:port][/path], or null for anything but a plain https (or allowed http) URL. */
    private static function base(?string $url, bool $allowHttp): ?string
    {
        $parts = $url === null ? false : parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $path = rtrim($parts['path'] ?? '', '/');
        if (! ($scheme === 'https' || ($scheme === 'http' && $allowHttp))
            || preg_match('/^[a-z0-9.-]+$/D', $host) !== 1
            || preg_match('#^(/[A-Za-z0-9._~-]+)*$#D', $path) !== 1) {
            return null;
        }

        return $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }
}
