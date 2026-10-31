<?php

declare(strict_types=1);

namespace App\Support\SourceMock;

use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Kobo\KoboPayloadNormalizer;
use App\Domain\Ingestion\Odk\OdkPayloadNormalizer;
use App\Domain\Round\Models\Round;
use App\Support\OdkMock\MockSubmissionGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes the data of the two local mocks (docker/odk-mock, docker/kobo-mock) for
 * edqa:source-mock:generate. Local development and tests only.
 *
 * - ODK Central: MockSubmissionGenerator's OData entries, as edqa:odk-mock:generate writes them.
 * - KoboToolbox: the same mix (MockSubmissionGenerator, then each entry rewritten as a KPI v2 data
 *   row: flat "group/question" keys, every answer as text, the XForms geopoint text, _id,
 *   _submission_time with no zone, __version__ the deployment uid mapped to the same field map,
 *   _attachments with download URLs on the mock). An edit keeps its _id and _submission_time.
 * - Neither mock visits a facility the other one does, unless asked: --overlap writes N plain
 *   visits of the other mock again, in turn copied with the same instance ID (a submission copied
 *   between servers: a cross-source duplicate, D-60) and entered again with a new one (the same
 *   facility and round on both tools: DUPLICATE_ASSESSMENT naming both sources, §5.8).
 */
final class SourceMockGenerator
{
    public const KOBO_TOKEN = 'mock-kobo-token';

    public const KOBO_ASSET = 'aKdDqaMock2026';

    /** @var array<string, int> */
    public array $overlap = ['copies' => 0, 'reentered' => 0];

    public function __construct(
        private readonly string $odkDir,
        private readonly string $koboDir,
    ) {}

    /** @return array<string, int> entries written, by kind */
    public function generate(string $source, int $count, int $broken, ?Round $round, string $version, int $seed, int $overlap = 0): array
    {
        $other = $source === 'kobo' ? 'odk' : 'kobo';
        $overlapRows = $overlap > 0 ? $this->plainVisits($other, $overlap) : [];

        $written = $source === 'kobo'
            ? $this->generateKobo($count, $broken, $round, $version, $seed)
            : (new MockSubmissionGenerator($this->odkDir, $this->facilityCodes('kobo')))->generate($count, $broken, $round, $version, $seed);

        $this->writeOverlap($source, $overlapRows, $seed);

        return $written;
    }

    public function applyEdits(string $source): void
    {
        if ($source === 'odk') {
            (new MockSubmissionGenerator($this->odkDir))->applyEdits();

            return;
        }
        $file = "{$this->koboDir}/config.json";
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($config)) {
            throw new RuntimeException('No mock data yet: run the generator first.');
        }
        $config['edits_applied'] = true;
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, int> */
    private function generateKobo(int $count, int $broken, ?Round $round, string $version, int $seed): array
    {
        // The mix in OData shape first, in a scratch directory beside the mock's, then rewritten.
        $scratch = $this->koboDir.'.odk-shape';
        try {
            $written = (new MockSubmissionGenerator($scratch, $this->facilityCodes('odk')))->generate($count, $broken, $round, $version, $seed);

            File::deleteDirectory($this->koboDir);
            foreach (['submissions', 'edits', 'attachments'] as $folder) {
                File::ensureDirectoryExists("{$this->koboDir}/{$folder}");
            }
            $base = $this->koboBaseUrl();
            foreach (glob("{$scratch}/submissions/*.json") ?: [] as $file) {
                $n = (int) basename($file, '.json');
                $entry = self::read($file);
                $row = self::koboRow($entry, 1000 + $n, $base);
                $photo = $entry['register_photo'] ?? null;
                if (is_string($photo)) {
                    $dir = "{$scratch}/attachments/".str_replace(':', '_', (string) ($entry['meta']['instanceID'] ?? ''));
                    File::ensureDirectoryExists("{$this->koboDir}/attachments/".(1000 + $n));
                    File::copy("{$dir}/{$photo}", "{$this->koboDir}/attachments/".(1000 + $n)."/{$photo}");
                    $row['_attachments'] = [self::attachment($base, 1000 + $n, 1, $photo)];
                }
                self::write("{$this->koboDir}/submissions/".basename($file), $row);
                if (is_file("{$scratch}/edits/".basename($file))) {
                    // Kobo replaces the row: same _id and _submission_time, the edited answers.
                    $edit = self::koboRow(self::read("{$scratch}/edits/".basename($file)), 1000 + $n, $base);
                    $edit['_submission_time'] = $row['_submission_time'];
                    $edit['_attachments'] = $row['_attachments'];
                    self::write("{$this->koboDir}/edits/".basename($file), $edit);
                }
            }
            File::copy("{$scratch}/kinds.json", "{$this->koboDir}/kinds.json");
            $this->writeKoboConfig($version);
        } finally {
            File::deleteDirectory($scratch);
        }

        return $written;
    }

    /**
     * Up to $count plain visits (no defect, no edit, no photo) of a mock, oldest file first, each
     * as its envelope's answers and meta.
     *
     * @return list<array{entry: array<string, mixed>, source: string}>
     */
    private function plainVisits(string $source, int $count): array
    {
        $dir = $source === 'kobo' ? $this->koboDir : $this->odkDir;
        $kinds = is_file("{$dir}/kinds.json") ? (array) json_decode((string) file_get_contents("{$dir}/kinds.json"), true) : [];
        $visits = [];
        foreach ($kinds as $n => $kind) {
            if ($kind === 'valid' && is_file("{$dir}/submissions/{$n}.json") && count($visits) < $count) {
                $visits[] = ['entry' => self::read("{$dir}/submissions/{$n}.json"), 'source' => $source];
            }
        }
        if ($visits === []) {
            throw new RuntimeException((string) __('sources.mock.no_overlap', ['other' => (string) config("edqa.sources.{$source}.name", $source)]));
        }

        return $visits;
    }

    /** @param  list<array{entry: array<string, mixed>, source: string}>  $visits */
    private function writeOverlap(string $source, array $visits, int $seed): void
    {
        $this->overlap = ['copies' => 0, 'reentered' => 0];
        $dir = $source === 'kobo' ? $this->koboDir : $this->odkDir;
        $next = count(glob("{$dir}/submissions/*.json") ?: []);
        foreach ($visits as $i => $visit) {
            $odk = $visit['source'] === 'kobo' ? self::odkEntry($visit['entry']) : $visit['entry'];
            $copy = $i % 2 === 0;
            if (! $copy) {
                // Entered again on the other tool: its own instance, a few minutes later.
                $odk['__id'] = $odk['meta']['instanceID'] = sprintf('uuid:overlap-%s-%d-%d', $source, $seed, $i);
                $odk['__system']['submissionDate'] = CarbonImmutable::parse((string) $odk['__system']['submissionDate'])->addMinutes(7)->utc()->format('Y-m-d\TH:i:s.v\Z');
            }
            $n = ++$next;
            $out = $source === 'kobo' ? self::koboRow($odk, 1000 + $n, $this->koboBaseUrl()) : $odk;
            self::write(sprintf('%s/submissions/%04d.json', $dir, $n), $out);
            $this->overlap[$copy ? 'copies' : 'reentered']++;
        }
    }

    /**
     * An OData entry as a KPI v2 data row.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    public static function koboRow(array $entry, int $id, string $baseUrl): array
    {
        $system = is_array($entry['__system'] ?? null) ? $entry['__system'] : [];
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $instance = (string) ($meta['instanceID'] ?? $entry['__id'] ?? '');
        $row = ['_id' => $id, 'formhub/uuid' => substr(hash('sha256', self::KOBO_ASSET), 0, 32)];
        if (isset($system['deviceId'])) {
            $row['deviceid'] = (string) $system['deviceId'];
        }
        $answers = array_diff_key($entry, array_flip(['__id', '__system', 'meta']));
        self::flatten($answers, '', $row);
        $row['__version__'] = self::koboVersion((string) ($system['formVersion'] ?? ''));
        $row['meta/instanceID'] = $instance;
        if (isset($meta['deprecatedID'])) {
            $row['meta/deprecatedID'] = (string) $meta['deprecatedID'];
        }
        $row['meta/rootUuid'] = (string) ($entry['__id'] ?? $instance);
        $gps = is_array($entry['gps']['coordinates'] ?? null) ? $entry['gps']['coordinates'] : [null, null];

        return [...$row,
            '_xform_id_string' => self::KOBO_ASSET,
            '_uuid' => str_starts_with($instance, 'uuid:') ? substr($instance, 5) : $instance,
            '_attachments' => [],
            '_status' => 'submitted_via_web',
            '_geolocation' => [$gps[1] ?? null, $gps[0] ?? null],
            '_submission_time' => CarbonImmutable::parse((string) ($system['submissionDate'] ?? 'now'))->utc()->format('Y-m-d\TH:i:s'),
            '_tags' => [],
            '_notes' => [],
            '_validation_status' => (object) [],
            '_submitted_by' => isset($system['submitterName']) ? (string) $system['submitterName'] : null,
        ];
    }

    /**
     * A KPI v2 data row as an OData entry, through Kobo's own normaliser.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function odkEntry(array $row): array
    {
        $envelope = (new KoboPayloadNormalizer)->normalise($row);
        $meta = $envelope->meta;
        $data = $envelope->data;
        if (is_string($data['gps'] ?? null)) {
            $parts = array_map(floatval(...), preg_split('/\s+/', trim($data['gps'])) ?: []);
            $data['gps'] = ['type' => 'Point', 'coordinates' => array_values(array_filter([$parts[1] ?? null, $parts[0] ?? null, $parts[2] ?? null], fn ($v): bool => $v !== null))];
        }
        $version = self::odkVersion($meta->formVersion);

        return [
            '__id' => $meta->instanceId,
            '__system' => [
                'submissionDate' => ($meta->submittedAt ?? CarbonImmutable::now())->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'updatedAt' => null, 'submitterId' => '900', 'submitterName' => $meta->submitter,
                'attachmentsPresent' => 0, 'attachmentsExpected' => 0, 'status' => null, 'reviewState' => null,
                'deviceId' => $meta->deviceId, 'edits' => 0, 'formVersion' => $version,
            ],
            'meta' => ['instanceID' => $meta->instanceId],
            ...$data,
        ];
    }

    /**
     * The facility codes a mock visits, read through its source's field maps.
     *
     * @return list<string>
     */
    private function facilityCodes(string $source): array
    {
        $dir = $source === 'kobo' ? $this->koboDir : $this->odkDir;
        $normaliser = $source === 'kobo' ? new KoboPayloadNormalizer : new OdkPayloadNormalizer;
        $codes = [];
        foreach (glob("{$dir}/submissions/*.json") ?: [] as $file) {
            $envelope = $normaliser->normalise(self::read($file));
            $map = FieldMap::for($source, $envelope->meta->formVersion);
            $path = $map?->path('facility_code');
            $code = $path === null ? null : FieldMap::get($envelope->data, $path);
            if (is_string($code) && $code !== '') {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /** The Kobo deployment uid mapped to the same field map as an ODK form version (or a placeholder). */
    private static function koboVersion(string $odkVersion): string
    {
        $map = config('edqa_field_maps.sources')['odk'][$odkVersion] ?? null;
        foreach ((array) (config('edqa_field_maps.sources')['kobo'] ?? []) as $uid => $name) {
            if ($name === $map) {
                return (string) $uid;
            }
        }

        return 'vKdUnmapped'.str_replace('.', '', $odkVersion);
    }

    private static function odkVersion(string $koboVersion): string
    {
        $map = config('edqa_field_maps.sources')['kobo'][$koboVersion] ?? null;
        foreach ((array) (config('edqa_field_maps.sources')['odk'] ?? []) as $version => $name) {
            if ($name === $map) {
                return (string) $version;
            }
        }

        return $koboVersion;
    }

    /**
     * Groups as "group/question" keys; every answer as text, as Kobo returns it; unanswered left out.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, mixed>  $row
     */
    private static function flatten(array $node, string $prefix, array &$row): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value) && ($value['type'] ?? null) === 'Point' && is_array($value['coordinates'] ?? null)) {
                $c = $value['coordinates'];
                $row[$path] = trim(implode(' ', array_map(strval(...), array_filter([$c[1] ?? null, $c[0] ?? null, $c[2] ?? null], fn ($v): bool => $v !== null))));
            } elseif (is_array($value) && ! array_is_list($value)) {
                self::flatten($value, "{$path}/", $row);
            } elseif ($value === null) {
                continue;
            } else {
                $row[$path] = is_array($value) ? $value : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }
    }

    /** @return array<string, mixed> */
    private static function attachment(string $base, int $id, int $n, string $name): array
    {
        return [
            'download_url' => "{$base}/api/v2/assets/".self::KOBO_ASSET."/data/{$id}/attachments/{$n}/",
            'filename' => "edqa-mock/attachments/{$id}/{$name}",
            'media_file_basename' => $name,
            'mimetype' => 'image/jpeg',
            'is_deleted' => false,
            'uid' => sprintf('attMock%05d%02d', $id, $n),
        ];
    }

    private function koboBaseUrl(): string
    {
        $url = config('edqa.sources.kobo.url');

        return rtrim(is_string($url) && $url !== '' ? $url : 'http://kobo-mock:8080', '/');
    }

    private function writeKoboConfig(string $version): void
    {
        $versions = array_values(array_unique([self::koboVersion($version), ...array_keys((array) (config('edqa_field_maps.sources')['kobo'] ?? []))]));
        $asset = config('edqa.sources.kobo.asset_uid');
        file_put_contents("{$this->koboDir}/config.json", json_encode([
            'token' => self::KOBO_TOKEN,
            'username' => 'edqa-mock',
            'asset' => ['uid' => is_string($asset) && $asset !== '' ? $asset : self::KOBO_ASSET, 'name' => 'Data Quality Assessment (mock)', 'deployed_version_id' => $versions[0]],
            'versions' => array_map(fn (string $uid, int $i): array => ['uid' => $uid, 'date_deployed' => CarbonImmutable::parse('2026-01-05T09:00:00Z')->subMonths(6 * $i)->format('Y-m-d\TH:i:s.u\Z')], $versions, array_keys($versions)),
            'base_url' => $this->koboBaseUrl(),
            'edits_applied' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private static function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, mixed>  $data */
    private static function write(string $file, array $data): void
    {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
