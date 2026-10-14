<?php

declare(strict_types=1);

use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Database\Seeders\ScoringRuleVersionSeeder;

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create();
    // ids keep counting across tests: never assume version 1 is id 1.
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole()->id;
});

it('refuses every scoring-rules endpoint to an unauthenticated user', function (string $method, string $uri): void {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/scoring-rules'],
    ['POST', '/api/scoring-rules'],
    ['POST', '/api/scoring-rules/1/preview'],
    ['POST', '/api/scoring-rules/1/publish'],
]);

it('lists the versions, newest first, marking the current one and any draft', function (): void {
    $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => []])->assertCreated();

    $this->actingAs($this->admin)->getJson('/api/scoring-rules')
        ->assertOk()
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.0.status', 'draft')
        ->assertJsonPath('data.1.version', 1)
        ->assertJsonPath('data.1.status', 'current')
        ->assertJsonPath('data.1.config.bands.strong', 90);
});

it('creates a draft, refusing a second with 409', function (): void {
    $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => ['bands' => ['strong' => 95, 'acceptable' => 85, 'review' => 75]]])
        ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.config.bands.strong', 95);

    $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => []])
        ->assertStatus(409)->assertJsonPath('code', 'draft_exists');
});

it('answers an invalid config with 409 and the reason', function (): void {
    $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => ['bands' => ['strong' => 80, 'acceptable' => 90, 'review' => 70]]])
        ->assertStatus(409)->assertJsonPath('code', 'bands_not_descending');
});

it('validates the draft request', function (array $body, string $field): void {
    // 'V1' stands for version 1's id, which datasets cannot know when they load.
    $body = array_map(fn (mixed $v): mixed => $v === 'V1' ? $this->v1 : $v, $body);

    $this->actingAs($this->admin)->postJson('/api/scoring-rules', $body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no source version' => [['changes' => []], 'from'],
    'unknown source version' => [['from' => 999_999, 'changes' => []], 'from'],
    'changes not an object' => [['from' => 'V1', 'changes' => 'raise strong'], 'changes'],
]);

it('previews a draft per open round', function (): void {
    $draft = $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => []])->json('data.id');

    $this->actingAs($this->admin)->postJson("/api/scoring-rules/{$draft}/preview")
        ->assertOk()->assertJsonStructure(['data' => ['rounds']]);
});

it('publishes a draft with a reason, and only a draft', function (): void {
    $draft = $this->actingAs($this->admin)->postJson('/api/scoring-rules', ['from' => $this->v1, 'changes' => []])->json('data.id');

    $this->actingAs($this->admin)->postJson("/api/scoring-rules/{$draft}/publish", ['reason' => 'short'])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/api/scoring-rules/{$draft}/publish", ['reason' => 'Agreed at the October review'])
        ->assertOk()->assertJsonPath('data.status', 'current');
    $this->actingAs($this->admin)->postJson("/api/scoring-rules/{$draft}/publish", ['reason' => 'Agreed at the October review'])
        ->assertStatus(409)->assertJsonPath('code', 'not_a_draft');

    expect(ScoringRuleVersion::query()->findOrFail($draft)->published_reason)->toBe('Agreed at the October review');
});
