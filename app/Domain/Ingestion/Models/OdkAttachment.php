<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Models;

use Database\Factories\OdkAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ODK attachment (register photo, GPS trace), fetched later on the low queue during backfill.
 */
#[UseFactory(OdkAttachmentFactory::class)]
final class OdkAttachment extends Model
{
    /** @use HasFactory<OdkAttachmentFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['submission_id', 'filename', 'mime', 'size', 'path', 'fetched_at', 'status'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
