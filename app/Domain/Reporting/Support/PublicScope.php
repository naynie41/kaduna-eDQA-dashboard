<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Models\Round;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Public data is closed rounds only (SECURITY.md §3, D-77). Applied where rounds are chosen,
 * RoundSelection and FilterOptionsQuery, which every aggregator, the facility query and the
 * round_aggregates reads go through: they read only the rounds chosen there. Withdrawn assessments
 * are left out by the aggregators' own SQL for everyone. config('edqa.public.show_open_round')
 * opens the public view to open rounds too.
 */
final class PublicScope
{
    /** Whether this audience is held to closed rounds. */
    public static function restricts(ReportAudience $audience): bool
    {
        return $audience === ReportAudience::Public && ! config('edqa.public.show_open_round');
    }

    /**
     * @template TBuilder of Builder<Round>|QueryBuilder
     *
     * @param  TBuilder  $rounds  a query on the rounds table
     * @return TBuilder
     */
    public static function apply(Builder|QueryBuilder $rounds, ReportAudience $audience): Builder|QueryBuilder
    {
        return self::restricts($audience) ? $rounds->where('status', 'closed') : $rounds;
    }

    /** The scope's part of a cache key: the same rounds give the same key, a different scope never does. */
    public static function key(ReportAudience $audience): string
    {
        return self::restricts($audience) ? 'closed' : 'all';
    }
}
