<?php

declare(strict_types=1);

namespace App\Domain\Ingestion;

use App\Domain\Ingestion\Contracts\PayloadNormalizer;
use App\Domain\Ingestion\Contracts\SubmissionSource as SourceAdapter;
use App\Domain\Ingestion\Enums\SourceType;
use App\Domain\Ingestion\Exceptions\KoboNotConfigured;
use App\Domain\Ingestion\Exceptions\OdkNotConfigured;
use App\Domain\Ingestion\Exceptions\SourceException;
use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\Kobo\KoboConfig;
use App\Domain\Ingestion\Kobo\KoboPayloadNormalizer;
use App\Domain\Ingestion\Kobo\KoboSource;
use App\Domain\Ingestion\Models\SubmissionSource;
use App\Domain\Ingestion\Odk\OdkCentralSource;
use App\Domain\Ingestion\Odk\OdkConfig;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use Illuminate\Contracts\Container\Container;

/**
 * The field-collection sources (ARCHITECTURE.md §5.0, §5.2): a source key (edqa.sources) to its
 * config, its submission_sources row and its adapter. A key not in config is refused
 * (SourceRefused::unknown), never guessed.
 *
 * - configured(): every credential it needs is set (OdkConfig / KoboConfig problems() empty);
 * - enabled(): configured, switched on in config, and its row enabled: these are the sources
 *   that pull.
 *
 * Every configured source has its adapter and normaliser.
 */
final class SourceRegistry
{
    /** @var array<string, SubmissionSource> */
    private array $rows = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return list<string> every configured key, in config order */
    public function keys(): array
    {
        $sources = config('edqa.sources');

        return is_array($sources) ? array_map(strval(...), array_keys($sources)) : [];
    }

    /** @return array{type: SourceType, name: string, enabled: bool, settings: array<string, mixed>} */
    public function config(string $key): array
    {
        $source = config("edqa.sources.{$key}");
        if (! is_array($source) || ! in_array($key, $this->keys(), true)) {
            throw SourceRefused::unknown($key);
        }
        $type = SourceType::tryFrom(is_string($source['type'] ?? null) ? $source['type'] : '') ?? throw SourceRefused::unknown($key);

        return [
            'type' => $type,
            'name' => is_string($source['name'] ?? null) ? $source['name'] : $key,
            'enabled' => ($source['enabled'] ?? true) !== false,
            'settings' => $source,
        ];
    }

    public function type(string $key): SourceType
    {
        return $this->config($key)['type'];
    }

    /** The source's row; refused when config knows it but edqa:sources:sync has not created it. */
    public function model(string $key): SubmissionSource
    {
        $this->config($key);

        return $this->rows[$key] ??= SubmissionSource::query()->where('key', $key)->first() ?? throw SourceRefused::notRegistered($key);
    }

    public function id(string $key): int
    {
        return $this->model($key)->id;
    }

    /** The key of a submission_sources row, or null for a row config no longer knows. */
    public function keyOf(int $sourceId): ?string
    {
        foreach ($this->keys() as $key) {
            if (($this->rows[$key] ??= SubmissionSource::query()->where('key', $key)->first())?->id === $sourceId) {
                return $key;
            }
        }

        return null;
    }

    /** The container key a test (or a future override) binds to replace one source's adapter. */
    public static function adapterBinding(string $key): string
    {
        return "edqa.sources.adapter.{$key}";
    }

    public function adapter(string $key): SourceAdapter
    {
        if ($this->container->bound(self::adapterBinding($key))) {
            $this->config($key);

            /** @var SourceAdapter */
            return $this->container->make(self::adapterBinding($key));
        }

        return match ($this->type($key)) {
            SourceType::OdkCentral => $this->container->make(OdkCentralSource::class),
            SourceType::Kobo => $this->container->make(KoboSource::class),
        };
    }

    /** The source's payload normaliser (ARCHITECTURE.md §5.2). */
    public function normaliser(string $key): PayloadNormalizer
    {
        return match ($this->type($key)) {
            SourceType::OdkCentral => $this->container->make(OdkPayloadNormalizer::class),
            SourceType::Kobo => $this->container->make(KoboPayloadNormalizer::class),
        };
    }

    /**
     * What stops the source from pulling: env keys to set, never their values. Empty when
     * configured.
     *
     * @return list<string>
     */
    public function problems(string $key): array
    {
        return match ($this->type($key)) {
            SourceType::OdkCentral => OdkConfig::fromConfig()->problems(),
            SourceType::Kobo => KoboConfig::fromConfig()->problems(),
        };
    }

    /** What the source's one form is called, for its source_syncs row: ODK's project/form, Kobo's asset UID. */
    public function formRef(string $key): string
    {
        return match ($this->type($key)) {
            SourceType::OdkCentral => OdkConfig::fromConfig()->formRef(),
            SourceType::Kobo => (string) KoboConfig::fromConfig()->assetUid,
        };
    }

    /** The source's own NotConfigured, naming the env keys to set. */
    public function notConfigured(string $key): SourceException
    {
        return match ($this->type($key)) {
            SourceType::OdkCentral => new OdkNotConfigured($this->problems($key)),
            SourceType::Kobo => new KoboNotConfigured($this->problems($key)),
        };
    }

    /** The source's name, as administrators see it ("ODK Central", "KoboToolbox"). */
    public function name(string $key): string
    {
        return $this->config($key)['name'];
    }

    /** Submissions a page (sources.*.page_size, at least 1). */
    public function pageSize(string $key): int
    {
        $size = $this->config($key)['settings']['page_size'] ?? 500;

        return max(1, is_numeric($size) ? (int) $size : 500);
    }

    /** Seconds between backfill pages (sources.*.backfill_throttle_seconds). */
    public function backfillThrottle(string $key): int
    {
        $seconds = $this->config($key)['settings']['backfill_throttle_seconds'] ?? 5;

        return max(0, is_numeric($seconds) ? (int) $seconds : 5);
    }

    /** @return list<string> keys whose credentials are complete */
    public function configured(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key): bool => $this->problems($key) === []));
    }

    /** @return list<string> keys that pull: configured, on in config, and their row enabled */
    public function enabled(): array
    {
        return array_values(array_filter($this->configured(), function (string $key): bool {
            if (! $this->config($key)['enabled']) {
                return false;
            }
            $row = SubmissionSource::query()->where('key', $key)->first();

            return $row !== null && $row->is_enabled;
        }));
    }

    /** Forgets the rows read so far (after edqa:sources:sync changes them). */
    public function flush(): void
    {
        $this->rows = [];
    }
}
