<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\AttachmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubmissionAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ODK attachment (register photo, GPS trace): recorded pending when the pull first sees its
 * submission, fetched on the low queue (FetchAttachmentsJob) to the private odk disk, served only
 * through the authenticated attachment route (SECURITY.md §7). filename is ODK's own name; path
 * is where the file sits on the disk once fetched. Not audited: raw input, like its submission.
 *
 * @property int $id
 * @property int $submission_id
 * @property string $filename
 * @property string|null $source_url
 * @property string|null $reason
 * @property AttachmentStatus $status
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $path
 * @property CarbonImmutable|null $fetched_at
 */
#[UseFactory(SubmissionAttachmentFactory::class)]
final class SubmissionAttachment extends Model
{
    /** @use HasFactory<SubmissionAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['submission_id', 'filename', 'mime', 'size', 'path', 'source_url', 'fetched_at', 'status', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'status' => AttachmentStatus::class,
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
