<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use RuntimeException;

/** Why a deletion at source cannot be marked reviewed: 409 { code, message }. */
final class DeletionReviewRefused extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
    ) {
        $message = __("sources.deletion_review.errors.{$errorCode}");

        parent::__construct(is_string($message) ? $message : $errorCode);
    }

    /** The submission is still at its source: there is no deletion to review. */
    public static function notDeleted(): self
    {
        return new self('not_deleted');
    }

    public static function alreadyReviewed(): self
    {
        return new self('already_reviewed');
    }
}
