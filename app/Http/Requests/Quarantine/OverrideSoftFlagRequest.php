<?php

declare(strict_types=1);

namespace App\Http\Requests\Quarantine;

use App\Domain\Validation\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;

final class OverrideSoftFlagRequest extends FormRequest
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
