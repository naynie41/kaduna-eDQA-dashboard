<?php

declare(strict_types=1);

use App\Domain\Scoring\Models\RescoreRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\RescoreFixture;

beforeEach(function (): void {
    $this->f = RescoreFixture::build();
    $this->admin = User::factory()->withTwoFactor()->create();
});

it('refuses the rescore endpoints to an unauthenticated user', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['POST', '/api/rounds/1/rescore'],
    ['GET', '/api/rescores/abc'],
]);

it('starts a rescore with a reason, audited, and returns the batch id', function (): void {
    $this->f->assessment($this->f->q2);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/rounds/{$this->f->q2}/rescore", ['reason' => 'Facility list corrected by the LGA'])
        ->assertAccepted()
        ->assertJsonPath('data.round_id', $this->f->q2)
        ->assertJsonPath('data.trigger', 'manual');

    $run = RescoreRun::query()->sole();
    $response->assertJsonPath('data.batch_id', $run->batch_id)->assertJsonPath('data.run_id', $run->id);
    expect($run->requested_by)->toBe($this->admin->id)
        ->and(DB::table('activity_log')->where('event', 'rescore_requested')->where('causer_id', $this->admin->id)->exists())->toBeTrue();
});

it('needs a reason of at least 10 characters', function (?string $reason): void {
    $this->actingAs($this->admin)->postJson("/api/rounds/{$this->f->q2}/rescore", ['reason' => $reason])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    expect(RescoreRun::query()->exists())->toBeFalse();
})->with([null, 'too short']);

it('refuses a closed round with 409 and an unknown one with 404', function (): void {
    $this->actingAs($this->admin)->postJson("/api/rounds/{$this->f->q1}/rescore", ['reason' => 'Rescore the closed round please'])
        ->assertConflict()->assertJsonPath('code', 'round_closed');
    $this->actingAs($this->admin)->postJson('/api/rounds/999999/rescore', ['reason' => 'Rescore a round that is not there'])
        ->assertNotFound();
});

it('reports progress from the batch and the run', function (): void {
    config(['edqa.rescore.chunk_size' => 1]);
    $this->f->assessment($this->f->q2);
    $this->f->assessment($this->f->q2);
    $batchId = $this->actingAs($this->admin)
        ->postJson("/api/rounds/{$this->f->q2}/rescore", ['reason' => 'Facility list corrected by the LGA'])
        ->json('data.batch_id');

    $this->actingAs($this->admin)->getJson("/api/rescores/{$batchId}")
        ->assertOk()
        ->assertJsonPath('data.batch_id', $batchId)
        ->assertJsonPath('data.round_id', $this->f->q2)
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.chunks.total', 2)
        ->assertJsonPath('data.chunks.processed', 2)
        ->assertJsonPath('data.chunks.failed', 0)
        ->assertJsonPath('data.progress', 100)
        ->assertJsonPath('data.assessments.total', 2)
        ->assertJsonPath('data.assessments.processed', 2)
        ->assertJsonPath('data.changed_scores', 18)
        ->assertJsonPath('data.reason', 'Facility list corrected by the LGA');

    $this->actingAs($this->admin)->getJson('/api/rescores/not-a-batch')->assertNotFound();
});
