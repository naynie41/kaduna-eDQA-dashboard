<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Support\PublicScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Figures last updated {date}" (D-81). For the public held to closed rounds (D-77): the last time
 * a public figure changed, the latest round close or a later rescore of a closed round's scores
 * (after a reopen and close). Otherwise: the latest score computed. Null while there is nothing
 * to show.
 */
final class FiguresUpdatedQuery
{
    public function at(ReportAudience $audience): ?CarbonImmutable
    {
        $at = PublicScope::restricts($audience)
            ? DB::selectOne(<<<'SQL'
                SELECT GREATEST(
                    (SELECT max(closed_at) FROM rounds WHERE status = 'closed'),
                    (SELECT max(s.computed_at) FROM assessment_scores s
                        JOIN assessments a ON a.id = s.assessment_id
                        JOIN rounds r ON r.id = a.round_id AND r.status = 'closed')
                ) AS at
                SQL)?->at
            : DB::table('assessment_scores')->max('computed_at');

        return $at === null ? null : CarbonImmutable::parse((string) $at)->setTimezone((string) config('app.timezone'));
    }
}
