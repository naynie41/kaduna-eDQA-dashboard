<?php

declare(strict_types=1);

namespace App\Domain\Validation;

/**
 * Soft flags raised by an event rather than by a validation rule. They behave as the soft rules'
 * flags do: listed on the Soft tab of Data issues under their own code, counted there, overridable
 * with a reason, and they make an assessment "flagged". RuleCatalogue lists them after the rules.
 */
final class EventFlags
{
    /**
     * Q-23 (default): an edit at source landed on an assessment an administrator had corrected in
     * the portal. The source edit wins; the correction stays in the audit log; this flag asks an
     * administrator to look.
     */
    public const SOURCE_EDIT_OVER_CORRECTION = 'SOURCE_EDIT_OVER_CORRECTION';

    public const ALL = [self::SOURCE_EDIT_OVER_CORRECTION];
}
