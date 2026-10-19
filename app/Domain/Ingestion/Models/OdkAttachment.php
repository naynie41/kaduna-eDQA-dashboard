<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use App\Domain\Ingestion\Enums\AttachmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OdkAttachmentFactory;
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
 * @property AttachmentStatus $status
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $path
 * @property CarbonImmutable|null $fetched_at
 */
#[UseFactory(OdkAttachmentFactory::class)]
final class OdkAttachment extends Model
{
    /** @use HasFactory<OdkAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['submission_id', 'filename', 'mime', 'size', 'path', 'fetched_at', 'status', 'reason'];

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
