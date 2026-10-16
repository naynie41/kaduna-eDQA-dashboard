<?php

declare(strict_types=1);

namespace App\Http\Requests\Facilities;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Round\Models\Round;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FacilityDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'round' => ['nullable', 'integer', Rule::exists('rounds', 'id')],
            // The dashboard's filters, same names as its URL (ARCHITECTURE.md §8 Conventions).
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ];
    }

    /** The asked round; null to open on the rounds the filters put in view. */
    public function round(): ?Round
    {
        return $this->filled('round') ? Round::query()->find($this->integer('round')) : null;
    }

    /** Year and quarter as the dashboard reads them: a quarter means nothing without a year. */
    public function filter(): ReportFilter
    {
        return new ReportFilter(
            year: $this->filled('year') ? $this->integer('year') : null,
            quarter: $this->filled('year') && $this->filled('quarter') ? $this->integer('quarter') : null,
        );
    }
}
