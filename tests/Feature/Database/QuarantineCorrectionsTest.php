<?php

declare(strict_types=1);

use App\Domain\Validation\Models\QuarantinedRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

// quarantined_records.parsed / corrections: hold the parsed snapshot and an admin's correction
// overlay, so a record can be corrected and reprocessed without touching the raw payload.

it('stores the parsed snapshot and corrections as jsonb', function (): void {
    expect(columnType('quarantined_records', 'parsed'))->toBe('jsonb')
        ->and(columnType('quarantined_records', 'corrections'))->toBe('jsonb');
});

it('defaults corrections to an empty object and allows no parsed snapshot', function (): void {
    $record = QuarantinedRecord::factory()->create();

    $row = DB::table('quarantined_records')->where('id', $record->id)->first(['parsed', 'corrections']);
    expect($row->parsed)->toBeNull()
        ->and($row->corrections)->toBe('{}');
});

it('never lets corrections be null', function (): void {
    $record = QuarantinedRecord::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('quarantined_records')->where('id', $record->id)->update(['corrections' => null])))
        ->toThrow(QueryException::class, 'not-null');
});

it('audits a change to the corrections', function (): void {
    $record = QuarantinedRecord::factory()->create();

    $record->update(['corrections' => ['lga_ref' => 'CHK']]);

    $entry = Activity::query()->forSubject($record)->where('event', 'updated')->sole();
    expect($entry->properties['attributes'])->toMatchArray(['corrections' => ['lga_ref' => 'CHK']])
        ->and($entry->properties['old'])->toMatchArray(['corrections' => []]);
});
