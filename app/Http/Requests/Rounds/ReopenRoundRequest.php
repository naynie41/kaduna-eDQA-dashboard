<?php

declare(strict_types=1);

namespace App\Http\Requests\Rounds;

use App\Domain\Round\Actions\ReopenRound;
use Illuminate\Foundation\Http\FormRequest;

final class ReopenRoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.ReopenRound::MIN_REASON],
            'rescore' => ['sometimes', 'boolean'],
        ];
    }
}
