<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// ARCHITECTURE.md §9.0–9.1: an anonymous visitor's page carries only what the public layout needs:
// no signed-in user, no admin counts, no source status, no admin strings. The admin preview adds the
// administrator (for the preview bar), never the source status; admin pages get everything.

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
});

/** The shared props, without the page's own. @return array<string, mixed> */
function shared(Assert $page): array
{
    $props = $page->toArray()['props'];

    return array_intersect_key($props, array_flip(['errors', 'name', 'auth', 'shell', 'admin', 'translations', 'filters', 'filterOptions', 'summary', 'unpublished', 'figuresUpdatedAt']));
}

it('gives an anonymous visitor only what the public layout needs', function (string $path): void {
    $this->get($path)->assertOk()->assertInertia(function (Assert $page): void {
        $props = shared($page);

        expect(array_keys($props))->toEqualCanonicalizing(['errors', 'name', 'auth', 'translations', 'filters', 'filterOptions', 'summary', 'unpublished', 'figuresUpdatedAt'])
            ->and($props['auth'])->toBe(['user' => null])
            ->and(array_keys($props['translations']))->toEqualCanonicalizing(['common', 'dashboard', 'enums'])
            ->and(array_keys($props['summary']))->toBe(['facilities', 'lgas', 'overall']);
    });
})->with(['/', '/facilities', '/lgas', '/monitor', '/visuals', '/plan']);

it('sends no source status, admin counts or admin strings to a visitor', function (): void {
    $html = (string) $this->get('/monitor')->getContent();

    expect($html)->not->toContain('"shell":{"assessments"')->not->toContain('"openIssueCount"')->not->toContain('"staleIngestion"')
        ->not->toContain('"facility_view"')->not->toContain('"user_menu"');
});

it('gives the admin preview the administrator, but not the source status', function (): void {
    $admin = User::factory()->create(['name' => 'Amina Bello']);

    $this->actingAs($admin)->get('/')->assertInertia(function (Assert $page) use ($admin): void {
        $props = shared($page);

        expect($props['auth']['user'])->toBe(['id' => $admin->id, 'name' => 'Amina Bello', 'email' => $admin->email])
            ->and($props)->not->toHaveKey('shell')->not->toHaveKey('admin')
            ->and(array_keys($props['translations']))->toEqualCanonicalizing(['common', 'dashboard', 'enums', 'admin']);
    });
});

it('gives admin pages the source status, the admin counts and their strings', function (): void {
    $this->actingAs(User::factory()->create())->get('/admin/sources')->assertInertia(fn (Assert $page) => $page
        ->has('shell.sources')
        ->has('shell.assessments')
        ->has('admin.openIssueCount')
        ->has('translations.admin')
        ->missing('filters'));
});

it('gives the login page its strings and nothing about the dashboard or admin', function (): void {
    $this->get('/login')->assertInertia(function (Assert $page): void {
        $props = shared($page);

        expect($props)->not->toHaveKey('shell')->not->toHaveKey('admin')->not->toHaveKey('filters')
            ->and(array_keys($props['translations']))->toEqualCanonicalizing(['common', 'auth']);
    });
});

it('says when the public figures last changed: the latest close, or a later rescore of a closed round', function (): void {
    DB::table('assessment_scores')->update(['computed_at' => '2026-04-01 08:00:00+01']);
    DB::table('rounds')->where('id', $this->f->q1)->update(['closed_at' => '2026-04-03 10:00:00+01']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('figuresUpdatedAt', '2026-04-03T10:00:00+01:00'));

    // A rescore of the closed round's scores after it closed moves the date on.
    $q1Assessment = (int) DB::table('assessments')->where('round_id', $this->f->q1)->value('id');
    DB::table('assessment_scores')->where('assessment_id', $q1Assessment)->update(['computed_at' => '2026-05-09 14:30:00+01']);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('figuresUpdatedAt', '2026-05-09T14:30:00+01:00'));

    // The open round's scores never count for the public.
    DB::table('assessment_scores')->where('assessment_id', '!=', $q1Assessment)->update(['computed_at' => '2026-06-01 09:00:00+01']);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('figuresUpdatedAt', '2026-05-09T14:30:00+01:00'));
});
