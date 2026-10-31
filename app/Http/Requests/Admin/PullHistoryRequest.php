<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Ingestion\Enums\PullTrigger;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The Data sources page's history filters: ?source= (a configured key), ?trigger=, ?outcome= (or running), ?page=. */
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
            'source' => ['nullable', 'string', Rule::in(app(SourceRegistry::class)->keys())],
            'trigger' => ['nullable', Rule::enum(PullTrigger::class)],
            'outcome' => ['nullable', Rule::in(PullHistoryQuery::OUTCOMES)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{source: ?string, trigger: ?string, outcome: ?string} */
    public function filters(): array
    {
        return [
            'source' => $this->string('source')->toString() ?: null,
            'trigger' => $this->string('trigger')->toString() ?: null,
            'outcome' => $this->string('outcome')->toString() ?: null,
        ];
    }
}
