<?php

declare(strict_types=1);

namespace App\Http\Requests\Quarantine;

use App\Domain\Validation\Actions\BulkResolveQuarantine;
use App\Domain\Validation\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * { action: correct|reject, corrections?, reason }. Soft flags are overridden on the assessment
 * (POST /api/assessments/{id}/flags/{code}/override), not here. Which fields can be corrected,
 * and with what types, is the Action's to enforce (CorrectAndReprocess::CORRECTABLE).
 */
final class ResolveQuarantineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(BulkResolveQuarantine::ACTIONS)],
            'corrections' => ['required_if:action,correct', 'array'],
            'reason' => ['required', 'string', 'min:'.Reason::MIN_LENGTH],
        ];
    }

    /** @return array<string, mixed> */
    public function corrections(): array
    {
        $corrections = $this->input('corrections', []);

        return is_array($corrections) ? $corrections : [];
    }
}
