<?php

declare(strict_types=1);

namespace App\Http\Requests\Quarantine;

use App\Domain\Validation\Enums\QuarantineStatus;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class QuarantineIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var array<string, string> $rules */
        $rules = config('edqa.validation.rules');

        return [
            'group' => ['nullable', Rule::in(['rule', 'flat'])],
            'status' => ['nullable', Rule::enum(QuarantineStatus::class)],
            'rule' => ['nullable', Rule::in([...array_keys($rules), ValidationPipeline::RULE_ERROR])],
            'round' => ['nullable', 'integer', Rule::exists('rounds', 'id')],
            'lga' => ['nullable', 'string', Rule::exists('lgas', 'code')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{status: ?string, rule: ?string, round: ?int, lga: ?string} */
    public function filters(): array
    {
        return [
            'status' => $this->string('status')->toString() ?: null,
            'rule' => $this->string('rule')->toString() ?: null,
            'round' => $this->filled('round') ? $this->integer('round') : null,
            'lga' => $this->string('lga')->toString() ?: null,
        ];
    }

    public function grouped(): bool
    {
        return $this->string('group')->toString() === 'rule';
    }
}
