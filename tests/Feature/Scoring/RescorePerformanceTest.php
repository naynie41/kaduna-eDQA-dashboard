<?php

declare(strict_types=1);

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Actions\RescoreRound;
use App\Domain\Scoring\Enums\Dimension;
use App\Domain\Scoring\Enums\RescoreStatus;
use App\Domain\Scoring\Enums\RescoreTrigger;
use Illuminate\Support\Facades\DB;
use Tests\Support\RescoreFixture;
use Tests\Support\Rows;

// ARCHITECTURE.md §10: one round (~2,200 assessments) rescored in under 5 minutes. Run with
// `make test-slow`; the default suite leaves the slow group out.

const PERF_ASSESSMENTS = 2200;
const PERF_ITEMS_PER_SLOT = 10; // the dev data averages 10 items per dimension × month

it('rescores a round of 2,200 assessments in under five minutes', function (): void {
    $f = RescoreFixture::build();
    mt_srand(2200);

    $now = now();
    foreach (array_chunk(range(1, PERF_ASSESSMENTS), 200) as $chunk) {
        $facilities = [];
        foreach ($chunk as $i) {
            $facilities[] = ['code' => "PERF-{$i}", 'name' => "Perf {$i}", 'lga_id' => $f->lga, 'ward_id' => $f->ward,
                'level' => 'primary', 'ownership' => $i % 10 === 0 ? 'private' : 'public', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('facilities')->insert($facilities);
        $facilityIds = DB::table('facilities')->whereIn('code', array_column($facilities, 'code'))->pluck('id')->all();

        $assessmentIds = [];
        foreach ($facilityIds as $facility) {
            $assessmentIds[] = Rows::assessment(['round_id' => $f->q2, 'facility_id' => $facility]);
        }

        $items = [];
        foreach ($assessmentIds as $assessment) {
            foreach (Dimension::cases() as $dimension) {
                foreach ([1, 2, 3] as $month) {
                    foreach (range(1, PERF_ITEMS_PER_SLOT) as $n) {
                        $roll = mt_rand(1, 20);
                        $items[] = ['assessment_id' => $assessment, 'dimension' => $dimension->value, 'month_slot' => $month,
                            'item_code' => "item_{$n}", 'value' => $roll === 1 ? 'na' : ($roll <= 15 ? 'yes' : 'no'), 'is_applicable' => true];
                    }
                }
            }
        }
        foreach (array_chunk($items, 5000) as $part) {
            DB::table('item_responses')->insert($part);
        }
    }

    $round = Round::query()->findOrFail($f->q2);

    $started = hrtime(true);
    $first = app(RescoreRound::class)->start($round, RescoreTrigger::Console)->refresh();
    $firstSeconds = (hrtime(true) - $started) / 1e9;

    $started = hrtime(true);
    $second = app(RescoreRound::class)->start($round, RescoreTrigger::Console)->refresh();
    $secondSeconds = (hrtime(true) - $started) / 1e9;

    fwrite(STDERR, sprintf("\n  Rescore of %d assessments (%d items): first %.1f s (%d score rows written), repeat %.1f s (%d written)\n",
        PERF_ASSESSMENTS, PERF_ASSESSMENTS * 9 * PERF_ITEMS_PER_SLOT, $firstSeconds, $first->changed_scores, $secondSeconds, $second->changed_scores));

    expect($first->status)->toBe(RescoreStatus::Succeeded)
        ->and($first->processed_assessments)->toBe(PERF_ASSESSMENTS)
        ->and($first->changed_scores)->toBe(PERF_ASSESSMENTS * 9)
        ->and($second->changed_scores)->toBe(0)
        ->and($firstSeconds)->toBeLessThan(300.0)
        ->and($secondSeconds)->toBeLessThan(300.0);
})->group('slow');
