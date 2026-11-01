<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Domain\Validation\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/submissions/{submission}/deletion-review: a reason, nothing else. */
final class ReviewDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.Reason::MIN_LENGTH],
        ];
    }
}
