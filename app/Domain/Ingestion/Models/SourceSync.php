<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SourceSyncFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A source's pull cursor (ARCHITECTURE.md §5.3), one row per source. cursor_at advances only
 * after a whole page commits; edit_cursor_at is the edits pass's cursor (D-51);
 * backfill_checkpoint is the backfill's position (D-53). form_ref names the form the cursor
 * follows ("project/form" on ODK Central): when the configured form changes, forForm() starts the
 * cursor again, as a new form needs.
 *
 * @property int $id
 * @property int $source_id
 * @property int $submission_count
 * @property string|null $last_error
 * @property string|null $form_ref
 * @property CarbonImmutable|null $cursor_at
 * @property CarbonImmutable|null $edit_cursor_at
 * @property string|null $cursor_instance_id
 * @property int $backfill_checkpoint
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $last_reconciled_at
 */
#[UseFactory(SourceSyncFactory::class)]
final class SourceSync extends Model
{
    /** @use HasFactory<SourceSyncFactory> */
    use HasFactory;

    protected $fillable = [
        'source_id', 'form_ref', 'cursor_at', 'edit_cursor_at', 'cursor_instance_id', 'backfill_checkpoint',
        'last_success_at', 'last_reconciled_at', 'last_error', 'submission_count',
    ];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['backfill_checkpoint' => 0, 'submission_count' => 0];

    /** The cursor columns a new form starts from. */
    private const CURSOR = ['cursor_at' => null, 'edit_cursor_at' => null, 'cursor_instance_id' => null, 'backfill_checkpoint' => 0, 'submission_count' => 0, 'last_error' => null];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'backfill_checkpoint' => 'integer',
            'submission_count' => 'integer',
            'cursor_at' => 'immutable_datetime',
            'edit_cursor_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_reconciled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * The source's cursor for this form, created when missing. A cursor that followed another form
     * starts again (as the per-form cursor rows did before Phase 2C).
     */
    public static function forForm(int $sourceId, string $formRef): self
    {
        $sync = self::query()->firstOrCreate(['source_id' => $sourceId], ['form_ref' => $formRef]);
        if ($sync->form_ref !== $formRef) {
            $sync->update(['form_ref' => $formRef, ...self::CURSOR]);
        }

        return $sync;
    }

    /** The source's cursor if it follows this form, else null (nothing pulled from it yet). */
    public static function current(int $sourceId, string $formRef): ?self
    {
        return self::query()->where('source_id', $sourceId)->where('form_ref', $formRef)->first();
    }

    /** The form itself, without the project ("project/form" → "form"): what submissions.form_ref holds. */
    public function formId(): string
    {
        $ref = (string) $this->form_ref;
        $slash = strpos($ref, '/');

        return $slash === false ? $ref : substr($ref, $slash + 1);
    }

    /** @return BelongsTo<SubmissionSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(SubmissionSource::class, 'source_id');
    }
}
