<?php

declare(strict_types=1);

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

it('accepts every FacilityLevel the app defines', function (FacilityLevel $level): void {
    expect(Rows::facility(['level' => $level->value]))->toBeInt();
})->with(fn (): array => FacilityLevel::cases());

it('accepts every Ownership the app defines', function (Ownership $ownership): void {
    expect(Rows::facility(['ownership' => $ownership->value]))->toBeInt();
})->with(fn (): array => Ownership::cases());

it('rejects a level outside Primary/Secondary/Tertiary', function (): void {
    expectCheckViolation(fn () => Rows::facility(['level' => 'quaternary']), 'facilities_level_valid');
});

it('rejects ownership outside Public/Private', function (): void {
    // e.g. a registry value like "faith_based" must be mapped first (open question Q-13).
    expectCheckViolation(fn () => Rows::facility(['ownership' => 'faith_based']), 'facilities_ownership_valid');
});

it('rejects two facilities with the same code', function (): void {
    Rows::facility(['code' => 'KD/CHK/001']);

    expectUniqueViolation(fn () => Rows::facility(['code' => 'KD/CHK/001']), 'facilities_code_unique');
});

it('is active unless deactivated', function (): void {
    $id = Rows::facility();

    expect(DB::table('facilities')->where('id', $id)->value('is_active'))->toBeTrue();
});

it('stores coordinates as numeric(9,6)', function (): void {
    $id = Rows::facility(['lat' => 10.5264, 'lng' => 7.4386]);

    expect(DB::table('facilities')->where('id', $id)->first(['lat', 'lng']))
        ->lat->toBe('10.526400')
        ->lng->toBe('7.438600');
});

it('has a partial index on lga_id for active facilities (cascade export)', function (): void {
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'facilities')
        ->where('indexname', 'facilities_lga_id_active_index')
        ->value('indexdef');

    expect($definition)->toContain('(lga_id)')->toContain('WHERE is_active');
});
