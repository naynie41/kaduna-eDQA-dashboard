<?php

declare(strict_types=1);

namespace App\Http\Requests\Quarantine;

use App\Domain\Validation\Actions\BulkResolveQuarantine;
use App\Domain\Validation\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkResolveQuarantineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'action' => ['required', Rule::in(BulkResolveQuarantine::ACTIONS)],
            'corrections' => ['required_if:action,correct', 'array'],
            'reason' => ['required', 'string', 'min:'.Reason::MIN_LENGTH],
        ];
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map(intval(...), (array) $this->input('ids', [])));
    }

    /** @return array<string, mixed> */
    public function corrections(): array
    {
        $corrections = $this->input('corrections', []);

        return is_array($corrections) ? $corrections : [];
    }
}
