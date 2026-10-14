<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { from: version id, changes: { bands?, item_weights?, na_policy?, choice_map? } }. Which keys
 * and values make a valid config is the Action's and RuleConfig's to enforce (409 otherwise).
 */
final class CreateDraftRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'integer', Rule::exists('scoring_rule_versions', 'id')],
            'changes' => ['present', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $changes = $this->input('changes', []);

        return is_array($changes) ? $changes : [];
    }
}
