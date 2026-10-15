<?php

declare(strict_types=1);

namespace App\Http\Requests\Rounds;

use Illuminate\Foundation\Http\FormRequest;

final class StoreRoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'between:2000,2100'],
            'quarter' => ['required', 'integer', 'between:1,4'],
            'window_start' => ['required', 'date_format:Y-m-d'],
            'window_end' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
