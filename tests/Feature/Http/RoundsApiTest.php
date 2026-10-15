<?php

declare(strict_types=1);

use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole()->id;
});

it('refuses every rounds endpoint to an unauthenticated user', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/rounds'],
    ['POST', '/api/rounds'],
    ['PATCH', '/api/rounds/1'],
    ['POST', '/api/rounds/1/close'],
    ['POST', '/api/rounds/1/reopen'],
]);

it('lists rounds, newest first, with status, window and pinned version', function (): void {
    Round::factory()->closed()->create(['year' => 2027, 'quarter' => 1]);
    Round::factory()->create(['year' => 2027, 'quarter' => 2]);

    $this->actingAs($this->admin)->getJson('/api/rounds')
        ->assertOk()
        ->assertJsonPath('data.0.label', 'Q2 2027')
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.window_start', '2027-04-01')
        ->assertJsonPath('data.0.rule_version_id', null)
        ->assertJsonPath('data.0.reads_rule_version_id', $this->v1)
        ->assertJsonPath('data.1.label', 'Q1 2027')
        ->assertJsonPath('data.1.status', 'closed')
        ->assertJsonPath('data.1.rule_version_id', $this->v1);
});

it('creates a round, returning any warning', function (): void {
    $this->actingAs($this->admin)
        ->postJson('/api/rounds', ['year' => 2027, 'quarter' => 2, 'window_start' => '2027-04-01', 'window_end' => '2027-07-31'])
        ->assertCreated()
        ->assertJsonPath('data.label', 'Q2 2027')
        ->assertJsonPath('data.window_end', '2027-07-31')
        ->assertJsonPath('warnings.0.code', 'window_past_quarter_end');
});

it('validates the create request and refuses domain conflicts with 409', function (): void {
    $this->actingAs($this->admin)->postJson('/api/rounds', ['year' => 2027, 'quarter' => 5, 'window_start' => 'soon'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quarter', 'window_start', 'window_end']);

    Round::factory()->create(['year' => 2027, 'quarter' => 2]);
    $this->actingAs($this->admin)
        ->postJson('/api/rounds', ['year' => 2027, 'quarter' => 2, 'window_start' => '2027-04-01', 'window_end' => '2027-06-30'])
        ->assertConflict()
        ->assertJsonPath('code', 'round_exists');
});

it('moves a window with PATCH', function (): void {
    $round = Round::factory()->create(['year' => 2027, 'quarter' => 2]);

    $this->actingAs($this->admin)->patchJson("/api/rounds/{$round->id}", ['window_start' => '2027-04-03', 'window_end' => '2027-06-30'])
        ->assertOk()
        ->assertJsonPath('data.window_start', '2027-04-03')
        ->assertJsonPath('warnings', []);
});

it('closes and reopens through the API, with the reason required to reopen', function (): void {
    $round = Round::factory()->create(['year' => 2027, 'quarter' => 2]);

    $this->actingAs($this->admin)->postJson("/api/rounds/{$round->id}/close")
        ->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.rule_version_id', $this->v1);
    $this->actingAs($this->admin)->postJson("/api/rounds/{$round->id}/close")
        ->assertConflict()->assertJsonPath('code', 'already_closed');

    $this->actingAs($this->admin)->postJson("/api/rounds/{$round->id}/reopen", ['reason' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/api/rounds/{$round->id}/reopen", ['reason' => 'Late returns from two LGAs', 'rescore' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.reopened_reason', 'Late returns from two LGAs')
        ->assertJsonPath('rescore.round_id', $round->id);

    expect(DB::table('activity_log')->where('subject_id', $round->id)->where('subject_type', $round->getMorphClass())->whereIn('event', ['closed', 'reopened'])->where('causer_id', $this->admin->id)->count())
        ->toBe(2);
});

it('answers 404 for an unknown round', function (): void {
    $this->actingAs($this->admin)->postJson('/api/rounds/999999/close')->assertNotFound();
});
