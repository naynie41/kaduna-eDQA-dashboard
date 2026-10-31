<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Models\SubmissionSource;
use App\Domain\Validation\ValidationContextFactory;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\ParsedSubmissionBuilder;
use Tests\Support\Rows;

// A source's own facility references matched to master-list facilities (D-72): the KoboToolbox
// form picks the facility from its own slugs, not the master list's codes. A client-signed CSV
// (ref,facility_code) is loaded as reference data by the console; validation then reads a slug
// as its facility. An unmapped slug stays unknown: FACILITY_UNKNOWN quarantines it.

beforeEach(function (): void {
    $this->kobo = Rows::sourceId('kobo');
    $this->facility = Facility::factory()->create(['code' => 'KD-0101']);
    $this->other = Facility::factory()->create(['code' => 'KD-0102']);
});

function sourceRefsCsv(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'refs').'.csv';
    file_put_contents($path, $body);

    return $path;
}

// ---- edqa:facilities:source-refs

it('loads a signed-off CSV for one source, replacing what was there, audited', function (): void {
    DB::table('facility_source_refs')->insert(['source_id' => $this->kobo, 'ref' => 'old_slug', 'facility_id' => $this->other->id, 'created_at' => now(), 'updated_at' => now()]);
    $file = sourceRefsCsv("ref,facility_code\nkujama_phc_1,KD-0101\nnarayi_phc_2, KD-0102 \n");

    $this->artisan('edqa:facilities:source-refs', ['source' => 'kobo', 'file' => $file])
        ->expectsOutputToContain('2 references loaded for kobo')->assertSuccessful();

    expect(DB::table('facility_source_refs')->where('source_id', $this->kobo)->orderBy('ref')->pluck('facility_id', 'ref')->all())
        ->toBe(['kujama_phc_1' => $this->facility->id, 'narayi_phc_2' => $this->other->id]);
    $log = Activity::query()->where('event', 'facility_source_refs_loaded')->sole();
    expect($log->subject?->is(SubmissionSource::query()->find($this->kobo)))->toBeTrue()
        ->and($log->properties['old'] ?? null)->toEqual(['count' => 1])
        ->and($log->properties['attributes'] ?? null)->toEqual(['count' => 2]);
});

it('refuses the whole file, changing nothing, when any row is wrong', function (string $body, string $why): void {
    $file = sourceRefsCsv($body);

    $this->artisan('edqa:facilities:source-refs', ['source' => 'kobo', 'file' => $file])
        ->expectsOutputToContain($why)->assertFailed();

    expect(DB::table('facility_source_refs')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'facility_source_refs_loaded')->count())->toBe(0);
})->with([
    'an unknown facility code' => ["ref,facility_code\nkujama_phc_1,KD-9999\n", 'KD-9999'],
    'a ref twice' => ["ref,facility_code\nkujama_phc_1,KD-0101\nkujama_phc_1,KD-0102\n", 'kujama_phc_1'],
    'a blank ref' => ["ref,facility_code\n,KD-0101\n", 'Line 2'],
    'the wrong header' => ["slug,code\nkujama_phc_1,KD-0101\n", 'ref,facility_code'],
]);

it('refuses a source not in config and a file that does not exist', function (): void {
    $this->artisan('edqa:facilities:source-refs', ['source' => 'dhis2', 'file' => sourceRefsCsv("ref,facility_code\n")])->assertFailed();
    $this->artisan('edqa:facilities:source-refs', ['source' => 'kobo', 'file' => '/nope/refs.csv'])->assertFailed();
});

it('checks a file without loading it with --dry-run', function (): void {
    $this->artisan('edqa:facilities:source-refs', ['source' => 'kobo', 'file' => sourceRefsCsv("ref,facility_code\nkujama_phc_1,KD-0101\n"), '--dry-run' => true])
        ->expectsOutputToContain('1 reference')->assertSuccessful();

    expect(DB::table('facility_source_refs')->count())->toBe(0);
});

// ---- Validation reads a source's slug as its facility

it('resolves a source\'s slug to its facility, for that source only; an unmapped slug stays unknown', function (): void {
    DB::table('facility_source_refs')->insert(['source_id' => $this->kobo, 'ref' => 'kujama_phc_1', 'facility_id' => $this->facility->id, 'created_at' => now(), 'updated_at' => now()]);
    $kobo = ParsedSubmissionBuilder::new()->fromSource('kobo')->withFacilityRef('kujama_phc_1')->build();
    $odk = ParsedSubmissionBuilder::new()->fromSource('odk')->withFacilityRef('kujama_phc_1')->build();
    $unmapped = ParsedSubmissionBuilder::new()->fromSource('kobo')->withFacilityRef('nowhere_phc_9')->build();

    expect(app(ValidationContextFactory::class)->forBatch([$kobo])->facility('kujama_phc_1')?->id)->toBe($this->facility->id)
        ->and(app(ValidationContextFactory::class)->forBatch([$odk])->facility('kujama_phc_1'))->toBeNull()
        ->and(app(ValidationContextFactory::class)->forBatch([$unmapped])->facility('nowhere_phc_9'))->toBeNull();
});

it('lets a real facility code win over a slug that happens to equal it', function (): void {
    DB::table('facility_source_refs')->insert(['source_id' => $this->kobo, 'ref' => 'KD-0101', 'facility_id' => $this->other->id, 'created_at' => now(), 'updated_at' => now()]);
    $kobo = ParsedSubmissionBuilder::new()->fromSource('kobo')->withFacilityRef('KD-0101')->build();

    expect(app(ValidationContextFactory::class)->forBatch([$kobo])->facility('KD-0101')?->id)->toBe($this->facility->id);
});
