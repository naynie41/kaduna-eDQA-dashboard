<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoring;

use App\Domain\Scoring\Actions\RescoreRound;
use Illuminate\Foundation\Http\FormRequest;

final class RescoreRoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.RescoreRound::MIN_REASON],
        ];
    }
}
