<?php

declare(strict_types=1);

use App\Domain\Assessment\Models\Assessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// ARCHITECTURE.md §9.2.7: the admin facility view at /admin/facilities/{id}, everything the
// public drawer leaves out: every round (the open one too), the assessor, soft flags, attachments,
// the audit trail, the source with its instance ID and deep link, and "Correct this assessment".

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    $this->admin = User::factory()->create();
    $this->facility = $this->f->facilities['F1'];
    $this->assessment = Assessment::query()->where('round_id', $this->f->q2)->where('facility_id', $this->facility)->firstOrFail();
});

it('shows an administrator the full detail, on the open round by default', function (): void {
    DB::table('submission_attachments')->insert(['submission_id' => $this->assessment->submission_id, 'filename' => 'register.jpg', 'status' => 'fetched', 'mime' => 'image/jpeg', 'size' => 2048, 'path' => 'x/register.jpg']);

    $this->actingAs($this->admin)->get("/admin/facilities/{$this->facility}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/facility')
            ->where('detail.facility.id', $this->facility)
            ->where('detail.facility.code', 'KD-F1')
            ->where('detail.round.label', 'Q2 2026')
            ->where('detail.round.open', true)
            ->where('detail.assessment.assessor', 'Assessor')
            ->has('detail.flags')
            ->has('detail.activity')
            ->where('detail.attachments.0.url', "/admin/attachments/{$this->assessment->submission_id}/register.jpg")
            ->has('detail.source.instance_id')
            ->has('detail.source.url')
            ->where('detail.history', fn ($history): bool => array_column($history->all(), 'label') === ['Q1 2026', 'Q2 2026']));
});

it('opens the round asked for, closed or open', function (): void {
    $this->actingAs($this->admin)->get("/admin/facilities/{$this->facility}?round={$this->f->q1}")
        ->assertInertia(fn (Assert $page) => $page->where('detail.round.label', 'Q1 2026')->where('detail.round.open', false));
});

it('is 404 for an unknown facility', function (): void {
    $this->actingAs($this->admin)->get('/admin/facilities/999999')->assertNotFound();
});

it('sends a guest to /login, for the view and for an attachment', function (): void {
    $this->get("/admin/facilities/{$this->facility}")->assertRedirect('/login');
    $this->get("/admin/attachments/{$this->assessment->submission_id}/register.jpg")->assertRedirect('/login');
    // The old attachment URL is gone.
    $this->actingAs($this->admin)->get("/attachments/{$this->assessment->submission_id}/register.jpg")->assertNotFound();
});
