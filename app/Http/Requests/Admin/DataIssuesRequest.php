<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Domain\Validation\RuleCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Data issues page's filters, all in the URL (D-57): ?tab=hard|soft, ?state= (hard tab;
 * default open), ?rule=, ?lga= (code), ?round= (id), ?source= (a configured key), ?page=.
 */
final class DataIssuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $codes = array_map(fn (array $rule): string => $rule['code'], app(RuleCatalogue::class)->all());

        return [
            // hard: quarantined records; soft: flagged accepted assessments; deleted: assessments
            // whose submission was deleted at source (D-62).
            'tab' => ['nullable', Rule::in(['hard', 'soft', 'deleted'])],
            'state' => ['nullable', Rule::in(QuarantineQuery::STATES)],
            'rule' => ['nullable', Rule::in($codes)],
            'lga' => ['nullable', 'string', Rule::exists('lgas', 'code')],
            'round' => ['nullable', 'integer', Rule::exists('rounds', 'id')],
            'source' => ['nullable', 'string', Rule::in(app(SourceRegistry::class)->keys())],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{tab: string, state: string, rule: ?string, lga: ?string, round: ?int, source: ?string} */
    public function filters(): array
    {
        return [
            'tab' => $this->string('tab')->toString() ?: 'hard',
            'state' => $this->string('state')->toString() ?: 'open',
            'rule' => $this->string('rule')->toString() ?: null,
            'lga' => $this->string('lga')->toString() ?: null,
            'round' => $this->filled('round') ? $this->integer('round') : null,
            'source' => $this->string('source')->toString() ?: null,
        ];
    }
}
