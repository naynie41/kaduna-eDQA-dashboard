<?php

declare(strict_types=1);

namespace App\Domain\Validation\DTOs;

use App\Domain\Validation\Enums\Severity;
use App\Support\Data\ArrayReader;

/**
 * One rule's finding about one submission. The detail is specific and already in words
 * ("availability_m1 derived score 347.66 exceeds 100"), built from the rule's template in
 * lang/en/validation_rules.php. field names the mapped field at fault, where there is one.
 */
final readonly class RuleFailure
{
    public function __construct(
        public string $code,
        public Severity $severity,
        public string $detail,
        public ?string $field = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $read = new ArrayReader($data, 'rule failure');

        return new self(
            $read->string('code'),
            Severity::from($read->string('severity')),
            $read->string('detail'),
            $read->nullableString('field'),
        );
    }

    /** @return array{code: string, severity: string, detail: string, field: ?string} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'detail' => $this->detail,
            'field' => $this->field,
        ];
    }
}
