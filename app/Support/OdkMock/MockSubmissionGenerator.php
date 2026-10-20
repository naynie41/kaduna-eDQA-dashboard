<?php

declare(strict_types=1);

namespace App\Support\OdkMock;

use App\Domain\Ingestion\Odk\FieldMap;
use App\Domain\Round\Models\Round;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Writes the mock ODK Central's data (docker/odk-mock): realistic raw OData entries for the seeded
 * facilities, through the field map of the chosen form version. Local development only.
 *
 * Every entry is a visit in the chosen round's window by one of a pool of assessors (at most four
 * visits each a day, so ASSESSOR_VOLUME fires only where meant), to an active facility with no
 * visit in that round yet (so nothing is a duplicate by accident). The mix:
 *   - broken (--broken): each a defect validation must quarantine, in turn: unknown LGA, numeric
 *     facility, typed score 347.66, missing slot, unexpected answer, shifted columns, ends before
 *     it starts, outside every round, blank LGA, facility in another LGA;
 *   - flagged (about 4%): every answer "yes", or a 12-minute visit;
 *   - edited (about one in fifty): an original plus, in edits/, its edited version (Central keeps
 *     __id and submissionDate, gives a new meta.instanceID with meta.deprecatedID), served once
 *     edits are applied;
 *   - two with a register photo (a small generated JPEG);
 *   - the rest ordinary visits whose answers follow a per-facility quality.
 * Seeded, so the same options give the same data.
 */
final class MockSubmissionGenerator
{
    public const CODES = ['nhmis_summary', 'register_present', 'tally_sheet', 'reporting_rate', 'data_elements'];

    public const BROKEN = ['unknown_lga', 'numeric_facility', 'typed_score', 'missing_slot', 'unexpected_choice', 'shifted_columns', 'ends_before_start', 'outside_round', 'blank_lga', 'lga_mismatch'];

    private const ASSESSORS = [
        'Hauwa Ibrahim', 'Musa Abdullahi', 'Grace Danjuma', 'Aisha Bello', 'John Gambo', 'Fatima Yusuf', 'Peter Auta',
        'Zainab Lawal', 'Daniel Kure', 'Halima Sani', 'Samuel Bako', 'Rahila Ishaya', 'Ibrahim Tanko', 'Esther Musa', 'Yakubu Garba',
    ];

    private const DIMENSIONS = [['avail', 'availability', 1.04], ['consist', 'consistency', 0.88], ['valid', 'validity', 1.03]];

    /** @var array<string, int> */
    public array $written = [];

    public function __construct(
        private readonly string $dataDir,
    ) {}

    /**
     * @return array<string, int> entries written, by kind
     */
    public function generate(int $count, int $broken, ?Round $round, string $version, int $seed = 2026): array
    {
        $round ??= Round::query()->where('status', 'open')->orderByDesc('year')->orderByDesc('quarter')->first()
            ?? Round::query()->orderByDesc('year')->orderByDesc('quarter')->first()
            ?? throw new RuntimeException('There is no round: seed the demo data first (make fresh).');

        return $this->generateSpan($count, $broken, [[$round, $version]], $seed);
    }

    /**
     * The mix above over several periods (the backfill, §5.6): $count and $broken shared out
     * evenly, oldest first, each period's visits in its own window through its own version's map.
     * A period is a Round, saved or not: a quarter whose round does not exist yet is an unsaved
     * Round with the quarter's dates, so the data can be surveyed before the rounds are created.
     *
     * @param  list<array{0: Round, 1: string}>  $periods  each with the form version it is submitted with
     * @return array<string, int> entries written, by kind
     */
    public function generateSpan(int $count, int $broken, array $periods, int $seed = 2026): array
    {
        if ($periods === []) {
            throw new RuntimeException('No period to write.');
        }
        mt_srand($seed);

        File::deleteDirectory($this->dataDir);
        foreach (['submissions', 'edits', 'attachments'] as $folder) {
            File::ensureDirectoryExists("{$this->dataDir}/{$folder}");
        }
        $this->writeConfig($periods[array_key_last($periods)][1]);

        $this->written = ['valid' => 0, 'flagged' => 0, 'broken' => 0, 'edited' => 0, 'attachments' => 0];
        $photos = 2;
        $index = 0;
        $broken = min($broken, $count);
        foreach ($periods as $p => [$round, $version]) {
            $map = FieldMap::resolve($version) ?? FieldMap::resolve((string) config('edqa_field_maps.default'));
            if ($map === null) {
                throw new RuntimeException('No field map to write with.');
            }
            $share = intdiv($count, count($periods)) + ($p < $count % count($periods) ? 1 : 0);
            $brokenShare = intdiv($broken, count($periods)) + ($p < $broken % count($periods) ? 1 : 0);

            $facilities = $this->freeFacilities($round);
            $kinds = $this->kinds($share, min($brokenShare, $share));
            if (count($kinds) > count($facilities)) {
                throw new RuntimeException(sprintf('Only %d active facilities have no visit in Q%d %d: ask for fewer.', count($facilities), $round->quarter, $round->year));
            }

            $slots = $this->visitSlots($round, count($kinds));
            foreach ($kinds as $i => $kind) {
                $entry = $this->entry($map, $version, $round, $facilities[$i], $slots[$i], $kind, $facilities);
                if ($kind === 'valid' && $photos > 0) {
                    $photos--;
                    $entry = $this->withPhoto($entry);
                }
                $this->write('submissions', $index, $entry);
                $this->written[match (true) {
                    in_array($kind, self::BROKEN, true) => 'broken',
                    $kind === 'valid', $kind === 'edited' => 'valid',
                    default => 'flagged',
                }]++;
                if ($kind === 'edited') {
                    $this->write('edits', $index, $this->edited($entry, $map));
                    $this->written['edited']++;
                }
                $index++;
            }
        }

        return $this->written;
    }

    /**
     * One period's kinds of visit, shuffled: broken ones in turn, about 4% flagged, one in fifty
     * edited, the rest ordinary.
     *
     * @return list<string>
     */
    private function kinds(int $count, int $broken): array
    {
        $flagged = (int) round($count * 0.04);
        $edits = max(1, intdiv($count, 50));
        $plain = $count - $broken - $flagged - 2 * $edits;
        if ($plain < 0) {
            [$flagged, $edits, $plain] = [0, 0, $count - $broken];
        }
        $kinds = [
            ...array_map(fn (int $i): string => self::BROKEN[$i % count(self::BROKEN)], $broken > 0 ? range(0, $broken - 1) : []),
            ...array_map(fn (int $i): string => $i % 2 === 0 ? 'all_perfect' : 'short_visit', $flagged > 0 ? range(0, $flagged - 1) : []),
            ...array_fill(0, $edits, 'edited'),
            ...array_fill(0, max(0, $plain), 'valid'),
        ];
        shuffle($kinds);

        return $kinds;
    }

    /** Lets the next pull see the edited versions (Central would show them once made). */
    public function applyEdits(): void
    {
        $file = "{$this->dataDir}/config.json";
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($config)) {
            throw new RuntimeException('No mock data yet: run the generator first.');
        }
        $config['edits_applied'] = true;
        file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Active facilities with no visit in the round, with the codes a form would carry.
     *
     * @return list<array{code: string, lga: string, ward: string, quality: float}>
     */
    private function freeFacilities(Round $round): array
    {
        $rows = DB::table('facilities as f')
            ->join('lgas as l', 'l.id', '=', 'f.lga_id')
            ->join('wards as w', 'w.id', '=', 'f.ward_id')
            ->where('f.is_active', true)
            ->whereNotExists(fn ($q) => $q->from('assessments as a')->whereColumn('a.facility_id', 'f.id')->where('a.round_id', $round->id))
            ->orderBy('f.code')
            ->get(['f.code', 'l.code as lga', 'w.code as ward', 'f.ownership']);

        $facilities = [];
        foreach ($rows as $row) {
            $base = $row->ownership === 'private' ? 0.74 : 0.87;
            $facilities[] = ['code' => (string) $row->code, 'lga' => (string) $row->lga, 'ward' => (string) $row->ward, 'quality' => min(0.99, max(0.45, $base + (mt_rand(-120, 120) / 1000)))];
        }
        // A fixed shuffle (seeded), so visits are spread over LGAs.
        shuffle($facilities);

        return $facilities;
    }

    /**
     * A start time for each visit: days through the window, each assessor at most four visits a day.
     *
     * @return list<array{assessor: string, device: string, submitter: int, start: CarbonImmutable}>
     */
    private function visitSlots(Round $round, int $count): array
    {
        $timezone = (string) config('app.timezone');
        $first = CarbonImmutable::parse($round->window_start->toDateString(), $timezone);
        $last = CarbonImmutable::parse(min($round->window_end->toDateString(), CarbonImmutable::now($timezone)->toDateString()), $timezone);
        $days = max(1, (int) $first->diffInDays($last) + 1);
        $slots = [];
        for ($i = 0; $i < $count; $i++) {
            $assessor = $i % count(self::ASSESSORS);
            $visit = intdiv($i, count(self::ASSESSORS)); // the assessor's nth visit
            $day = intdiv($visit, 4) % $days;
            $slots[] = [
                'assessor' => self::ASSESSORS[$assessor],
                'device' => sprintf('collect:%s%02d', strtolower(substr(str_replace(' ', '', self::ASSESSORS[$assessor]), 0, 6)), $assessor + 1),
                'submitter' => 100 + $assessor,
                'start' => $first->addDays($day)->setTime(8 + 2 * ($visit % 4), mt_rand(0, 50)),
            ];
        }

        return $slots;
    }

    /**
     * @param  array{code: string, lga: string, ward: string, quality: float}  $facility
     * @param  array{assessor: string, device: string, submitter: int, start: CarbonImmutable}  $slot
     * @param  list<array{code: string, lga: string, ward: string, quality: float}>  $facilities
     * @return array<string, mixed>
     */
    private function entry(FieldMap $map, string $version, Round $round, array $facility, array $slot, string $kind, array $facilities): array
    {
        $start = $slot['start'];
        if ($kind === 'outside_round') {
            // Three years before the window: outside every round even when the data spans quarters.
            $start = CarbonImmutable::parse($round->window_start->toDateString(), (string) config('app.timezone'))->subYears(3)->setTime(10, 0);
        }
        $minutes = match ($kind) {
            'short_visit' => 12,
            'ends_before_start' => -30,
            default => mt_rand(35, 80),
        };
        $end = $start->addMinutes($minutes);
        $submitted = $start->addMinutes(max(5, $minutes) + mt_rand(5, 90));
        $instance = self::uuid();

        $lga = match ($kind) {
            'unknown_lga' => 'Kaduna Central',
            'blank_lga' => null,
            'lga_mismatch' => $this->otherLga($facility['lga'], $facilities),
            default => $facility['lga'],
        };
        $entry = [
            '__id' => $instance,
            '__system' => [
                'submissionDate' => $submitted->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'updatedAt' => null,
                'submitterId' => (string) $slot['submitter'],
                'submitterName' => strtolower(str_replace(' ', '.', $slot['assessor'])),
                'attachmentsPresent' => 0,
                'attachmentsExpected' => 0,
                'status' => null,
                'reviewState' => null,
                'deviceId' => $slot['device'],
                'edits' => 0,
                'formVersion' => $version,
            ],
            'meta' => ['instanceID' => $instance],
        ];
        $set = function (string $field, mixed $value) use (&$entry, $map): void {
            $path = $map->path($field);
            if ($path !== null) {
                $entry = self::put($entry, $path, $value);
            }
        };
        $set('started_at', $kind === 'shifted_columns' ? 'FEBRUARY' : $start->format('Y-m-d\TH:i:s.vP'));
        $set('ended_at', $end->format('Y-m-d\TH:i:s.vP'));
        $set('assessor', $slot['assessor']);
        $set('round_year', $round->year);
        $set('round_quarter', $round->quarter);
        $set('lga_code', $lga);
        $set('ward_code', $kind === 'shifted_columns' ? 'Quarterly' : $facility['ward']);
        $set('facility_code', $kind === 'numeric_facility' ? '2019.00' : $facility['code']);
        $set('gps', ['type' => 'Point', 'coordinates' => [round(7.2 + mt_rand(0, 2000) / 1000, 4), round(9.8 + mt_rand(0, 1700) / 1000, 4), round(500 + mt_rand(0, 3000) / 10, 1)]]);

        foreach (self::DIMENSIONS as [$prefix, $group, $lift]) {
            foreach ([1, 2, 3] as $slotNo) {
                if ($kind === 'missing_slot' && $prefix === 'valid' && $slotNo === 3) {
                    continue;
                }
                foreach (self::CODES as $n => $code) {
                    $answer = match (true) {
                        $kind === 'all_perfect' => 'yes',
                        $n > 0 && mt_rand(1, 100) <= 4 => 'na',
                        default => mt_rand(1, 1000) / 1000 < $facility['quality'] * $lift ? 'yes' : 'no',
                    };
                    $entry[$group][$map->questionName("{$prefix}_m{$slotNo}_{$code}")] = $answer;
                }
            }
        }
        if ($kind === 'unexpected_choice') {
            $entry['availability'][$map->questionName('avail_m1_tally_sheet')] = 'maybe';
        }
        if ($kind === 'typed_score') {
            $entry['availability']['avail_m1_score'] = 347.66;
        }

        return $entry;
    }

    /**
     * The edited version: as Central shows it, the same __id and submissionDate, a new instance ID
     * that deprecates the one before, updatedAt set, one edit, one answer changed.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function edited(array $entry, FieldMap $map): array
    {
        $previous = $entry['meta']['instanceID'];
        $new = self::uuid();
        $submitted = CarbonImmutable::parse($entry['__system']['submissionDate']);
        $entry['meta'] = ['instanceID' => $new, 'deprecatedID' => $previous];
        $entry['__system']['updatedAt'] = $submitted->addDays(2)->utc()->format('Y-m-d\TH:i:s.v\Z');
        $entry['__system']['edits'] = 1;
        $entry['__system']['reviewState'] = 'edited';
        $question = $map->questionName('consist_m2_register_present');
        $entry['consistency'][$question] = ($entry['consistency'][$question] ?? 'yes') === 'yes' ? 'no' : 'yes';

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withPhoto(array $entry): array
    {
        $name = 'register-'.substr(str_replace('uuid:', '', (string) $entry['__id']), 0, 8).'.jpg';
        $dir = "{$this->dataDir}/attachments/".str_replace(':', '_', (string) $entry['meta']['instanceID']);
        File::ensureDirectoryExists($dir);
        file_put_contents("{$dir}/{$name}", self::jpeg());
        $entry['register_photo'] = $name;
        $entry['__system']['attachmentsPresent'] = 1;
        $entry['__system']['attachmentsExpected'] = 1;
        $this->written['attachments']++;

        return $entry;
    }

    /** A small JPEG: a pale page with a few dark "register lines". */
    private static function jpeg(): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            // 1×1 grey JPEG, if GD is missing.
            return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
        }
        $image = imagecreatetruecolor(96, 64);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 246, 243, 232));
        $ink = (int) imagecolorallocate($image, 40, 52, 80);
        for ($y = 10; $y < 60; $y += 8) {
            imageline($image, 6, $y, 6 + mt_rand(50, 84), $y, $ink);
        }
        ob_start();
        imagejpeg($image, null, 70);

        return (string) ob_get_clean();
    }

    /** @param  list<array{code: string, lga: string, ward: string, quality: float}>  $facilities */
    private function otherLga(string $lga, array $facilities): string
    {
        foreach ($facilities as $facility) {
            if ($facility['lga'] !== $lga) {
                return $facility['lga'];
            }
        }

        return $lga === 'ZAR' ? 'CHK' : 'ZAR';
    }

    private function writeConfig(string $version): void
    {
        $versions = array_values(array_unique([$version, ...array_keys((array) config('edqa_field_maps.versions'))]));
        file_put_contents("{$this->dataDir}/config.json", json_encode([
            'email' => 'mock@odk.local',
            'password' => 'mock-password',
            'account' => ['id' => 1, 'displayName' => 'eDQA portal (mock)'],
            'project' => ['id' => 1, 'name' => 'Kaduna DQA (mock)'],
            'form' => ['xmlFormId' => 'dqa', 'name' => 'Data Quality Assessment (mock)', 'version' => $version],
            'versions' => array_map(fn (string $v, int $i): array => ['version' => $v, 'publishedAt' => CarbonImmutable::parse('2026-01-05T09:00:00Z')->subMonths(6 * $i)->format('Y-m-d\TH:i:s.v\Z')], $versions, array_keys($versions)),
            'edits_applied' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param  array<string, mixed>  $entry */
    private function write(string $folder, int $i, array $entry): void
    {
        file_put_contents(sprintf('%s/%s/%04d.json', $this->dataDir, $folder, $i + 1), json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function put(array $entry, string $path, mixed $value): array
    {
        $node = &$entry;
        foreach (explode('.', $path) as $step) {
            if (! is_array($node)) {
                $node = [];
            }
            $node = &$node[$step];
        }
        $node = $value;
        unset($node);

        return $entry;
    }

    /** A v4-shaped UUID from the seeded generator, so a run reproduces. */
    private static function uuid(): string
    {
        $hex = '';
        for ($i = 0; $i < 8; $i++) {
            $hex .= sprintf('%04x', mt_rand(0, 0xFFFF));
        }

        return sprintf('uuid:%s-%s-4%s-%s%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), dechex(8 + mt_rand(0, 3)), substr($hex, 17, 3), substr($hex, 20, 12));
    }
}
