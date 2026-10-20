<?php

declare(strict_types=1);

use App\Domain\Facility\Exports\CascadeExporter;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Rows;

// The ODK cascading-select media (ARCHITECTURE.md §5.1, §8, D-54): LGA → ward → facility, active
// facilities only, to a private path, downloaded through a signed link.

beforeEach(function (): void {
    Storage::fake('exports');
    $this->admin = User::factory()->withTwoFactor()->create();

    $chikun = Rows::lga(['code' => 'CHK', 'name' => 'Chikun']);
    $zaria = Rows::lga(['code' => 'ZAR', 'name' => 'Zaria']);
    $empty = Rows::lga(['code' => 'GWA', 'name' => 'Giwa']); // only an inactive facility
    $kujama = Rows::ward(['lga_id' => $chikun, 'code' => 'CHK-KUJ', 'name' => 'Kujama']);
    $narayi = Rows::ward(['lga_id' => $chikun, 'code' => 'CHK-NAR', 'name' => 'Narayi']);
    $tudun = Rows::ward(['lga_id' => $zaria, 'code' => 'ZAR-TUD', 'name' => 'Tudun Wada']);
    $closed = Rows::ward(['lga_id' => $empty, 'code' => 'GWA-SHI', 'name' => 'Shika']);
    Rows::facility(['code' => 'KD-0002', 'name' => 'Narayi PHC', 'lga_id' => $chikun, 'ward_id' => $narayi]);
    Rows::facility(['code' => 'KD-0001', 'name' => 'Kujama Clinic', 'lga_id' => $chikun, 'ward_id' => $kujama]);
    Rows::facility(['code' => 'KD-0003', 'name' => 'Tudun Wada MCH', 'lga_id' => $zaria, 'ward_id' => $tudun]);
    Rows::facility(['code' => 'KD-0009', 'name' => 'Closed Dispensary', 'lga_id' => $chikun, 'ward_id' => $kujama, 'is_active' => false]);
    Rows::facility(['code' => 'KD-0010', 'name' => 'Shika Post', 'lga_id' => $empty, 'ward_id' => $closed, 'is_active' => false]);
    Rows::facility(['code' => 'KD-0011', 'name' => '=HYPERLINK("x")', 'lga_id' => $zaria, 'ward_id' => $tudun]);
});

/** @return array<string, list<array<string, string>>> file => rows by header */
function cascadeFiles(string $path): array
{
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('exports')->path($path)))->toBeTrue();
    $files = [];
    foreach (['lgas.csv', 'wards.csv', 'facilities.csv'] as $name) {
        $lines = array_map(fn (string $line): array => str_getcsv($line, escape: ''), explode("\n", rtrim((string) $zip->getFromName($name), "\n")));
        $header = array_shift($lines);
        $files[$name] = array_map(fn (array $row): array => array_combine($header, $row), $lines);
    }
    $zip->close();

    return $files;
}

it('writes the three cascade files, active facilities only, codes as names, sorted by label', function (): void {
    $export = app(CascadeExporter::class)->export();

    $files = cascadeFiles($export['path']);
    expect($export)->toMatchArray(['lgas' => 2, 'wards' => 3, 'facilities' => 4])
        ->and($export['path'])->toStartWith('cascade/odk-cascade-')
        ->and($files['lgas.csv'])->toBe([['name' => 'CHK', 'label' => 'Chikun'], ['name' => 'ZAR', 'label' => 'Zaria']])
        ->and($files['wards.csv'])->toBe([
            ['name' => 'CHK-KUJ', 'label' => 'Kujama', 'lga' => 'CHK'],
            ['name' => 'CHK-NAR', 'label' => 'Narayi', 'lga' => 'CHK'],
            ['name' => 'ZAR-TUD', 'label' => 'Tudun Wada', 'lga' => 'ZAR'],
        ])
        ->and($files['facilities.csv'])->toBe([
            ['name' => 'KD-0011', 'label' => "'=HYPERLINK(\"x\")", 'lga' => 'ZAR', 'ward' => 'ZAR-TUD'],
            ['name' => 'KD-0001', 'label' => 'Kujama Clinic', 'lga' => 'CHK', 'ward' => 'CHK-KUJ'],
            ['name' => 'KD-0002', 'label' => 'Narayi PHC', 'lga' => 'CHK', 'ward' => 'CHK-NAR'],
            ['name' => 'KD-0003', 'label' => 'Tudun Wada MCH', 'lga' => 'ZAR', 'ward' => 'ZAR-TUD'],
        ]);
});

it('leaves out inactive facilities, and an LGA or ward left with none', function (): void {
    $files = cascadeFiles(app(CascadeExporter::class)->export()['path']);

    $codes = array_column($files['facilities.csv'], 'name');
    expect($codes)->not->toContain('KD-0009')->not->toContain('KD-0010')
        ->and(array_column($files['lgas.csv'], 'name'))->not->toContain('GWA')
        ->and(array_column($files['wards.csv'], 'name'))->not->toContain('GWA-SHI');
});

it('generates on request, audited, and answers with a signed link valid for 30 minutes', function (): void {
    $this->freezeSecond(); // the expiry is compared to the second
    $response = $this->actingAs($this->admin)->postJson('/api/facility-list/export-cascade')->assertCreated();

    $data = $response->json('data');
    expect($data)->toMatchArray(['lgas' => 2, 'wards' => 3, 'facilities' => 4])
        ->and($data['url'])->toContain('/api/facility-list/export-cascade/'.$data['filename'])->toContain('signature=')
        ->and(Storage::disk('exports')->exists('cascade/'.$data['filename']))->toBeTrue()
        ->and($data['expires_at'])->toBe(now()->addMinutes(30)->toIso8601String());
    $entry = Activity::query()->where('event', 'cascade_exported')->sole();
    expect($entry->causer?->is($this->admin))->toBeTrue()
        ->and($entry->properties['attributes'])->toMatchArray(['file' => $data['filename'], 'facilities' => 4]);
});

it('downloads only through the signed link, signed in, and audits the download', function (): void {
    $data = $this->actingAs($this->admin)->postJson('/api/facility-list/export-cascade')->json('data');
    $unsigned = '/api/facility-list/export-cascade/'.$data['filename'];

    $this->actingAs($this->admin)->get($unsigned)->assertForbidden();
    $download = $this->actingAs($this->admin)->get($data['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($download->headers->get('Content-Disposition'))->toContain($data['filename'])
        ->and(Activity::query()->where('event', 'cascade_downloaded')->sole()->causer?->is($this->admin))->toBeTrue();

    $this->travel(31)->minutes();
    $this->actingAs($this->admin)->get($data['url'])->assertForbidden();
});

it('refuses the export and the download to a request that is not signed in', function (): void {
    $this->postJson('/api/facility-list/export-cascade')->assertUnauthorized();
    expect(Storage::disk('exports')->allFiles())->toBe([])
        ->and(Activity::query()->where('event', 'like', 'cascade_%')->count())->toBe(0);
});

it('serves nothing but a cascade file, even with a valid signature', function (string $file): void {
    Storage::disk('exports')->put('backfill-report.md', 'secret report');
    $url = URL::temporarySignedRoute('api.facility-list.cascade.download', now()->addMinutes(5), ['file' => $file]);

    $this->actingAs($this->admin)->get($url)->assertNotFound();
})->with(['backfill-report.md', '..', 'odk-cascade-20261020-000000.zip']);
