<?php

declare(strict_types=1);

namespace App\Domain\Validation\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Validation\Contracts\ValidationRule;
use App\Domain\Validation\Enums\Severity;
use App\Domain\Validation\Exceptions\QuarantineActionRefused;
use App\Domain\Validation\Support\Reason;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * "Override soft flag" (ARCHITECTURE.md §6): an administrator clears one soft-rule flag from an
 * accepted assessment, with a typed reason. The override is the audit entry (old and new flags,
 * code, reason). The assessment goes back to accepted once no soft flag is left; informational
 * notes such as SLOT_ALL_NA are not flags and cannot be overridden.
 */
final class OverrideSoftFlag
{
    /** @param  array<string, string>  $rules  edqa.validation.rules */
    public function __construct(
        private readonly Container $container,
        #[Config('edqa.validation.rules')] private readonly array $rules,
    ) {}

    public function execute(Assessment $assessment, string $code, User $actor, string $reason): Assessment
    {
        $reason = Reason::require($reason);
        $softCodes = $this->softCodes();

        if (! in_array($code, $softCodes, true)) {
            throw QuarantineActionRefused::notASoftFlag($code);
        }

        return DB::transaction(function () use ($assessment, $code, $actor, $reason, $softCodes): Assessment {
            $assessment = Assessment::query()->lockForUpdate()->findOrFail($assessment->id);
            /** @var list<string> $flags */
            $flags = $assessment->flags;
            if (! in_array($code, $flags, true)) {
                throw QuarantineActionRefused::flagNotFound($code);
            }

            $remaining = array_values(array_filter($flags, fn (string $flag): bool => $flag !== $code));
            $status = array_intersect($remaining, $softCodes) === [] ? 'accepted' : 'flagged';
            $old = ['flags' => $flags, 'status' => $assessment->status];

            activity()->withoutLogs(fn () => $assessment->update(['flags' => $remaining, 'status' => $status]));

            AuditLog::record($assessment, $actor, 'flag_overridden',
                old: $old,
                new: ['flags' => $remaining, 'status' => $status],
                reason: $reason,
                extra: ['code' => $code],
            );

            return $assessment;
        });
    }

    /** @return list<string> */
    private function softCodes(): array
    {
        $codes = [];
        foreach ($this->rules as $code => $class) {
            $rule = $this->container->make($class);
            if ($rule instanceof ValidationRule && $rule->severity() === Severity::Soft) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
