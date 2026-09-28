<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * label() for backed enums, read from lang/en/enums.php under the enum's key.
 */
trait HasLabel
{
    abstract protected static function labelGroup(): string;

    public function label(): string
    {
        $label = __('enums.'.static::labelGroup().'.'.$this->value);

        return is_string($label) ? $label : (string) $this->value;
    }
}
