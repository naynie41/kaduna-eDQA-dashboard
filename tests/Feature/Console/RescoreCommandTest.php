<?php

declare(strict_types=1);

use App\Domain\Scoring\Models\RescoreRun;
use Tests\Support\RescoreFixture;
use Tests\Support\Rows;

beforeEach(function (): void {
    $this->f = RescoreFixture::build();
});

it('rescores one round and prints the batch', function (): void {
    $this->f->assessment($this->f->q2);

    $this->artisan('edqa:rescore', ['round' => $this->f->q2])
        ->expectsOutputToContain('Batch')
        ->assertSuccessful();

    $run = RescoreRun::query()->sole();
    expect($run->round_id)->toBe($this->f->q2)
        ->and($run->trigger->value)->toBe('console')
        ->and($run->processed_assessments)->toBe(1);
});

it('rescores every open round with --all-open', function (): void {
    $q3 = Rows::round(['year' => 2026, 'quarter' => 3, 'window_start' => '2026-07-01', 'window_end' => '2026-09-30']);

    $this->artisan('edqa:rescore', ['--all-open' => true])->assertSuccessful();

    expect(RescoreRun::query()->orderBy('round_id')->pluck('round_id')->all())->toBe([$this->f->q2, $q3]);
});

it('refuses a closed round, an unknown one, and no round at all', function (): void {
    $this->artisan('edqa:rescore', ['round' => $this->f->q1])
        ->expectsOutputToContain(__('scoring.errors.round_closed'))
        ->assertFailed();
    $this->artisan('edqa:rescore', ['round' => 999999])->assertFailed();
    $this->artisan('edqa:rescore')->assertFailed();
    $this->artisan('edqa:rescore', ['round' => $this->f->q2, '--all-open' => true])->assertFailed();

    expect(RescoreRun::query()->exists())->toBeFalse();
});
