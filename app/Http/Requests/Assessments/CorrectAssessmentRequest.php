<?php

declare(strict_types=1);

namespace App\Http\Requests\Assessments;

use App\Domain\Assessment\Actions\CorrectAssessment;
use App\Domain\Assessment\Models\Assessment;
use App\Domain\Assessment\Models\ItemResponse;
use App\Domain\Assessment\Support\ItemKey;
use App\Domain\Validation\Support\Reason;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/assessments/{assessment}/corrections (D-58): item results keyed as the form names
 * them, the visit start and end with their offset, and the reason. A score in any form is
 * refused (CLAUDE.md rule 2); every item key must be one of this assessment's items.
 */
final class CorrectAssessmentRequest extends FormRequest
{
    private const OFFSET_TIME = 'date_format:Y-m-d\TH:i:sP,Y-m-d\TH:i:s.vP';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.Reason::MIN_LENGTH],
            'items' => ['required_without_all:started_at,ended_at', 'array', 'max:200'],
            'items.*' => ['string', 'in:'.implode(',', CorrectAssessment::OUTCOMES)],
            'started_at' => ['nullable', 'string', self::OFFSET_TIME],
            'ended_at' => ['nullable', 'string', self::OFFSET_TIME, 'after_or_equal:started_at'],
            // Scores are derived, never sent (CLAUDE.md rule 2).
            'scores' => ['prohibited'],
            'score' => ['prohibited'],
            'overall' => ['prohibited'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $items = $this->input('items');
            if (! is_array($items) || $items === []) {
                return;
            }
            /** @var Assessment $assessment */
            $assessment = $this->route('assessment');
            $known = ItemResponse::query()->where('assessment_id', $assessment->id)->get(['dimension', 'month_slot', 'item_code'])
                ->map(fn (ItemResponse $r): string => ItemKey::make($r->dimension, $r->month_slot, (string) $r->item_code))
                ->flip();

            foreach (array_keys($items) as $key) {
                $key = (string) $key;
                if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                    $validator->errors()->add('items', (string) __('assessments.corrections.validation.not_a_key', ['key' => $key]));
                } elseif (ItemKey::parse($key) === null || ! $known->has($key)) {
                    $validator->errors()->add("items.{$key}", (string) __('assessments.corrections.validation.not_this_assessment', ['key' => $key]));
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function items(): array
    {
        /** @var array<string, string> $items */
        $items = $this->validated('items') ?? [];

        return $items;
    }

    public function time(string $field): ?CarbonImmutable
    {
        $value = $this->validated($field);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
