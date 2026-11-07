<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// SECURITY.md §3.1, D-83: public output goes through Http\Resources\Public only, each an explicit
// whitelist. The public facility drawer shows facility name, LGA, ward, level, ownership, the score
// card of a closed round, pass/fail/N/A item results and the round history. Nothing else. The
// fixture: 2026 Q1 closed (F1 alone), 2026 Q2 open (all seven).

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
    $this->f1 = $this->f->facilities['F1'];
    DB::table('facilities')->where('id', $this->f1)->update(['lat' => 10.5222, 'lng' => 7.4383]);
    DB::table('assessments')->update(['assessor_name' => 'Hauwa Private-Name', 'device_id' => 'device-secret-42']);
    // One item result on F1's closed-round assessment, so the drawer has items to show.
    $q1Assessment = (int) DB::table('assessments')->where('round_id', $this->f->q1)->where('facility_id', $this->f1)->value('id');
    Rows::itemResponse(['assessment_id' => $q1Assessment, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => 'item_1', 'value' => 'yes']);
});

/** The keys a ScoreValue may carry. */
const SCORE = ['value', 'display', 'headline', 'band', 'bandLabel'];

/**
 * Fails on any key not listed, at any depth. A list's items are checked against the shape given for
 * '*'; a key mapped to null is a leaf.
 *
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $shape  key => null (a leaf), SCORE, a nested shape, or ['*' => shape] for a list
 */
function assertOnlyKeys(array $data, array $shape, string $path = 'data'): void
{
    $allowed = array_keys($shape);
    expect(array_values(array_diff(array_keys($data), $allowed)))->toBe([], "{$path} has keys outside the whitelist");
    expect(array_values(array_diff($allowed, array_keys($data))))->toBe([], "{$path} is missing whitelisted keys");

    foreach ($shape as $key => $inner) {
        $value = $data[$key];
        if ($inner === null || $value === null) {
            continue;
        }
        if ($inner === SCORE) {
            expect(array_keys($value))->toBe(SCORE, "{$path}.{$key} is not a ScoreValue");

            continue;
        }
        if (array_key_exists('*', $inner)) {
            foreach ($value as $i => $item) {
                $inner['*'] === SCORE
                    ? expect(array_keys($item))->toBe(SCORE)
                    : assertOnlyKeys($item, $inner['*'], "{$path}.{$key}.{$i}");
            }

            continue;
        }
        assertOnlyKeys($value, $inner, "{$path}.{$key}");
    }
}

const DRAWER = [
    'facility' => ['name' => null, 'lga' => null, 'ward' => null, 'level' => null, 'level_label' => null, 'ownership' => null, 'ownership_label' => null],
    'round' => ['id' => null, 'label' => null, 'year' => null, 'quarter' => null],
    'scores' => ['availability' => SCORE, 'consistency' => SCORE, 'validity' => SCORE, 'overall' => SCORE],
    'items' => ['*' => [
        'dimension' => null, 'dimension_label' => null, 'slot' => null, 'month' => null, 'score' => SCORE,
        'items' => ['*' => ['code' => null, 'label' => null, 'result' => null, 'checks' => null]],
    ]],
    'history' => ['*' => ['round_id' => null, 'label' => null, 'overall' => SCORE, 'selected' => null]],
];

/** Nothing from SECURITY.md §3.1 in a response body. */
function expectNothingPrivate(string $body): void
{
    expect($body)
        ->not->toContain('Hauwa Private-Name')->not->toContain('device-secret-42')
        ->not->toContain('instance_id')->not->toContain('attachments')->not->toContain('/admin')
        ->not->toContain('10.5222')->not->toContain('7.4383')->not->toContain('"lat"')->not->toContain('"lng"')
        ->not->toContain('assessor')->not->toContain('flags')->not->toContain('activity')->not->toContain('"source":{');
}

// ---- The public drawer

it('returns exactly the whitelisted drawer, for a closed round, to a guest', function (): void {
    $response = $this->getJson("/api/public/facilities/{$this->f1}")->assertOk();

    assertOnlyKeys($response->json('data'), DRAWER);
    expect($response->json('data.round.label'))->toBe('Q1 2026')
        ->and($response->json('data.scores.overall.display'))->toBe('10.0')
        ->and(array_column($response->json('data.history'), 'label'))->toBe(['Q1 2026'])
        ->and($response->json('data.items'))->not->toBeEmpty();
    expectNothingPrivate((string) $response->getContent());
});

it('ignores an open round asked for by a guest, opening the latest closed one', function (): void {
    $this->getJson("/api/public/facilities/{$this->f1}?round={$this->f->q2}")->assertOk()
        ->assertJsonPath('data.round.label', 'Q1 2026');
    $this->getJson("/api/public/facilities/{$this->f1}?year=2026&quarter=2")->assertOk()
        ->assertJsonPath('data.round.label', 'Q1 2026');
});

it('shows no round at all for a facility assessed only in the open round', function (): void {
    $response = $this->getJson('/api/public/facilities/'.$this->f->facilities['F4'])->assertOk();

    assertOnlyKeys($response->json('data'), DRAWER);
    expect($response->json('data.history'))->toBe([])
        ->and($response->json('data.items'))->toBe([])
        ->and($response->json('data.scores.overall.value'))->toBeNull();
});

it('answers 404 for a facility that does not exist, and 405 to anything but GET', function (): void {
    $this->getJson('/api/public/facilities/999999')->assertNotFound();
    $this->postJson("/api/public/facilities/{$this->f1}")->assertStatus(405);
});

// ---- The rest of the public API

it('serves the aggregates for the filters, closed rounds only, through the whitelist', function (): void {
    $response = $this->getJson('/api/public/aggregates?lga=AAA')->assertOk();

    expect(array_keys($response->json('data')))->toBe(['filters', 'summary', 'dimensions', 'owners', 'lgas'])
        ->and($response->json('data.summary.facilitiesVisited'))->toBe(1)
        ->and(array_keys($response->json('data.summary')))->toBe(['overall', 'facilitiesVisited', 'lgasCovered', 'facilitiesStrong', 'facilitiesWeak', 'strongAt', 'weakBelow'])
        ->and(array_keys($response->json('data.lgas.0')))->toBe(['lgaId', 'code', 'name', 'facilities', 'availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall']);
    expectNothingPrivate((string) $response->getContent());
});

it('serves the facility table, paginated and sortable, closed rounds only', function (): void {
    $response = $this->getJson('/api/public/facilities?sort=facility&dir=asc')->assertOk();

    expect($response->json('data.total'))->toBe(1)
        ->and(array_keys($response->json('data')))->toBe(['rows', 'total', 'page', 'perPage', 'lastPage', 'from', 'to'])
        ->and(array_keys($response->json('data.rows.0')))->toBe(['facilityId', 'code', 'name', 'lga', 'ward', 'ownership', 'level', 'roundLabel', 'assessedOn', 'm1', 'm2', 'm3', 'score', 'overall']);
    expectNothingPrivate((string) $response->getContent());
});

it('says when the public figures were last updated, and nothing else', function (): void {
    DB::table('assessment_scores')->update(['computed_at' => '2026-04-01 08:00:00+01']);
    DB::table('rounds')->where('id', $this->f->q1)->update(['closed_at' => '2026-04-03 10:00:00+01']);

    $response = $this->getJson('/api/public/status')->assertOk();

    expect($response->json('data'))->toBe(['figures_updated_at' => '2026-04-03T10:00:00+01:00']);
});

it('keeps the public API read-only and rate limited', function (string $path): void {
    $this->getJson($path)->assertOk()->assertHeader('X-RateLimit-Limit', '120');
    $this->deleteJson($path)->assertStatus(405);
})->with(['/api/public/aggregates', '/api/public/facilities', '/api/public/status']);

// ---- The public pages

it('sends a guest page props through the public whitelist', function (): void {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('hero.facilitiesVisited', 1)
        ->has('hero', 7)
        ->has('dimensions', 5)
        ->loadDeferredProps(fn (Assert $reload) => $reload->has('owners.public', 5)));
    expectNothingPrivate((string) $this->get('/facilities')->getContent());
});

it('keeps the counts by source to the admin preview on Monitor (D-81)', function (): void {
    $this->get('/monitor')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload->missing('sources')->has('windows')));

    $this->actingAs(User::factory()->create())->get('/monitor')->assertInertia(fn (Assert $page) => $page
        ->loadDeferredProps(fn (Assert $reload) => $reload->has('sources')));
});

it('shows plan owners as roles only, never a stored personal name (D-79)', function (): void {
    DB::table('plan_actions')->insert([
        'round_id' => $this->f->q1, 'lga_id' => $this->f->lgas['AAA'], 'dimension' => 'availability',
        'action_text' => 'Restock', 'owner_role' => 'state_me_team', 'owner_contact' => 'Musa Personal-Name', 'due_date' => '2026-07-01', 'status' => 'not_started',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $response = $this->get('/plan');
    $response->assertInertia(fn (Assert $page) => $page->loadDeferredProps(function (Assert $reload): void {
        $owners = array_column($reload->toArray()['props']['plan']['actions'], 'owner');
        expect($owners)->not->toContain('Musa Personal-Name')
            ->and(array_diff($owners, array_values((array) __('plan.owners'))))->toBe([]);
    }));
});
