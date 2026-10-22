<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The ODK pull page's history filters: ?trigger=, ?outcome= (or running), ?page=. */
final class PullHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'trigger' => ['nullable', Rule::enum(PullTrigger::class)],
            'outcome' => ['nullable', Rule::in(PullHistoryQuery::OUTCOMES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{trigger: ?string, outcome: ?string} */
    public function filters(): array
    {
        return [
            'trigger' => $this->string('trigger')->toString() ?: null,
            'outcome' => $this->string('outcome')->toString() ?: null,
        ];
    }
}
