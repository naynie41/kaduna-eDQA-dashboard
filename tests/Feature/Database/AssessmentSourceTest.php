<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Rows;

// assessments.source_id (Phase 2C step 7): taken from the assessment's submission by the
// database, never by a writer, so Reporting can count by source without reading submissions.

it('takes the source from the submission on insert, whatever the writer says', function (): void {
    $kobo = Rows::sourceId('kobo');
    $submission = Rows::submission(['source_id' => $kobo]);

    $id = Rows::assessment(['submission_id' => $submission, 'source_id' => Rows::sourceId('odk')]);

    expect((int) DB::table('assessments')->where('id', $id)->value('source_id'))->toBe($kobo);
});

it('follows the submission when an edit moves the assessment to another one', function (): void {
    $id = Rows::assessment(['submission_id' => Rows::submission(['source_id' => Rows::sourceId('odk')])]);
    $kobo = Rows::sourceId('kobo');

    DB::table('assessments')->where('id', $id)->update(['submission_id' => Rows::submission(['source_id' => $kobo])]);

    expect((int) DB::table('assessments')->where('id', $id)->value('source_id'))->toBe($kobo);
});

it('cannot be set apart from its submission\'s source', function (): void {
    $id = Rows::assessment(['submission_id' => Rows::submission(['source_id' => Rows::sourceId('odk')])]);

    DB::table('assessments')->where('id', $id)->update(['source_id' => Rows::sourceId('kobo')]);

    expect((int) DB::table('assessments')->where('id', $id)->value('source_id'))->toBe(Rows::sourceId('odk'));
});

it('moves with the submission if the submission\'s source is changed', function (): void {
    $submission = Rows::submission(['source_id' => Rows::sourceId('odk')]);
    $id = Rows::assessment(['submission_id' => $submission]);

    DB::table('submissions')->where('id', $submission)->update(['source_id' => Rows::sourceId('kobo')]);

    expect((int) DB::table('assessments')->where('id', $id)->value('source_id'))->toBe(Rows::sourceId('kobo'));
});

it('is never null', function (): void {
    expect(DB::scalar("SELECT is_nullable FROM information_schema.columns WHERE table_name = 'assessments' AND column_name = 'source_id'"))->toBe('NO');
});

it('ties the pair to the submission through a unique (id, source_id) key on submissions', function (): void {
    $constraints = DB::table('pg_constraint')->whereIn('conname', ['submissions_id_source_id_unique', 'assessments_submission_source_foreign'])->pluck('contype', 'conname')->all();

    expect($constraints)->toEqual(['submissions_id_source_id_unique' => 'u', 'assessments_submission_source_foreign' => 'f']);
});
