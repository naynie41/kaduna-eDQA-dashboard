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
            // pass | fail | na for an item; na or a count of checks for a question answered as
            // several checks (which one fits each item is checked in after()).
            'items.*' => ['string', 'regex:/^(pass|fail|na|[0-9])$/'],
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
            // Checks per item key: 1 for an item, more for a question answered as several checks.
            $known = ItemResponse::query()->where('assessment_id', $assessment->id)->get(['dimension', 'month_slot', 'item_code'])
                ->countBy(fn (ItemResponse $r): string => ItemKey::make($r->dimension, $r->month_slot, (string) $r->item_code));

            foreach ($items as $key => $value) {
                $key = (string) $key;
                if (preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                    $validator->errors()->add('items', (string) __('assessments.corrections.validation.not_a_key', ['key' => $key]));
                } elseif (ItemKey::parse($key) === null || ! $known->has($key)) {
                    $validator->errors()->add("items.{$key}", (string) __('assessments.corrections.validation.not_this_assessment', ['key' => $key]));
                } elseif (is_string($value) && ! CorrectAssessment::fits($value, (int) $known->get($key))) {
                    $total = (int) $known->get($key);
                    $validator->errors()->add("items.{$key}", (string) ($total === 1
                        ? __('assessments.corrections.validation.result_for_item', ['key' => $key])
                        : __('assessments.corrections.validation.count_for_checks', ['key' => $key, 'total' => $total])));
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
