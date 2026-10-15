<?php

declare(strict_types=1);

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateRoundWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'window_start' => ['required', 'date_format:Y-m-d'],
            'window_end' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
