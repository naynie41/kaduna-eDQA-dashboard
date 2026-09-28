<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

it('accepts a valid LGA', function (): void {
    expect(Rows::lga(['name' => 'Chikun', 'code' => 'CHK']))->toBeInt();
});

it('rejects two LGAs with the same name', function (): void {
    Rows::lga(['name' => 'Chikun']);

    expectUniqueViolation(fn () => Rows::lga(['name' => 'Chikun']), 'lgas_name_unique');
});

it('rejects two LGAs with the same code', function (): void {
    Rows::lga(['code' => 'CHK']);

    expectUniqueViolation(fn () => Rows::lga(['code' => 'CHK']), 'lgas_code_unique');
});

it('rejects two wards with the same name in one LGA', function (): void {
    $lgaId = Rows::lga();
    Rows::ward(['lga_id' => $lgaId, 'name' => 'Kawo']);

    expectUniqueViolation(
        fn () => Rows::ward(['lga_id' => $lgaId, 'name' => 'Kawo']),
        'wards_lga_id_name_unique',
    );
});

it('allows the same ward name in different LGAs', function (): void {
    Rows::ward(['name' => 'Township']);
    Rows::ward(['name' => 'Township']);

    expect(DB::table('wards')->where('name', 'Township')->count())->toBe(2);
});
