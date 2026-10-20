<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Queries;

use App\Domain\Ingestion\DTOs\BackfillReport;
use Illuminate\Support\Facades\DB;

/**
 * The backfill report's figures (ARCHITECTURE.md §5.6, D-53): what every stored ODK submission
 * became, per round, and why the quarantined ones were held.
 *
 * Operational counts of raw submissions and quarantine, never a score: like QuarantineQuery it
 * counts in SQL outside Reporting, because Reporting may not read either table (CLAUDE.md hard
 * rule 6); the architecture test allows these two files by name.
 *
 * - Per round, then a total row (from SQL, GROUPING SETS): each submission once, as it stands now. Its round is its assessment's (accepted,
 *   flagged) or its latest quarantine record's (quarantined, rejected); none is "no round"
 *   (outside every window, or not processed). A superseded submission (replaced by an ODK edit)
 *   is counted once, apart: its replacement carries the round.
 * - By rule: the open quarantine records; a record counts once under each hard rule it failed,
 *   so the rule totals can exceed the quarantined total. The year is the visit's (its start, or
 *   the submission time), in the app's zone; the version is the submission's.
 * - Examples: the most frequent failure details of each rule, at most three.
 */
final class BackfillReportQuery
{
    public function build(): BackfillReport
    {
        $timezone = (string) config('app.timezone');

        $rounds = DB::select(<<<'SQL'
            WITH current AS (
                SELECT s.id, s.status, COALESCE(a.round_id, q.round_id) AS round_id
                FROM submissions s
                LEFT JOIN assessments a ON a.submission_id = s.id
                LEFT JOIN LATERAL (
                    SELECT r.round_id FROM quarantined_records r WHERE r.submission_id = s.id ORDER BY r.id DESC LIMIT 1
                ) q ON true
                WHERE s.status <> 'superseded'
            )
            SELECT c.round_id, rd.year, rd.quarter, GROUPING(c.round_id) = 1 AS is_total,
                COUNT(*) AS pulled,
                COUNT(*) FILTER (WHERE c.status = 'accepted') AS accepted,
                COUNT(*) FILTER (WHERE c.status = 'flagged') AS flagged,
                COUNT(*) FILTER (WHERE c.status = 'quarantined') AS quarantined,
                COUNT(*) FILTER (WHERE c.status = 'rejected') AS rejected,
                COUNT(*) FILTER (WHERE c.status NOT IN ('accepted', 'flagged', 'quarantined', 'rejected')) AS unprocessed
            FROM current c
            LEFT JOIN rounds rd ON rd.id = c.round_id
            GROUP BY GROUPING SETS ((c.round_id, rd.year, rd.quarter), ())
            ORDER BY is_total, rd.year NULLS LAST, rd.quarter NULLS LAST
            SQL);

        $totals = DB::selectOne(<<<'SQL'
            SELECT COUNT(*) AS submissions, COUNT(*) FILTER (WHERE status = 'superseded') AS superseded FROM submissions
            SQL);

        $failures = <<<'SQL'
            FROM quarantined_records r
            JOIN submissions s ON s.id = r.submission_id
            CROSS JOIN LATERAL jsonb_array_elements(r.failures) f
            WHERE r.status = 'open' AND f->>'severity' = 'hard'
            SQL;

        $byYear = DB::select(<<<SQL
            SELECT f->>'code' AS code,
                EXTRACT(YEAR FROM (COALESCE((r.parsed->>'started_at')::timestamptz, s.submitted_at) AT TIME ZONE ?))::int AS year,
                COUNT(DISTINCT r.id) AS records
            {$failures}
            GROUP BY 1, 2 ORDER BY 1, 2
            SQL, [$timezone]);

        $byVersion = DB::select(<<<SQL
            SELECT f->>'code' AS code, s.form_version AS version, COUNT(DISTINCT r.id) AS records
            {$failures}
            GROUP BY 1, 2 ORDER BY 1, 2
            SQL);

        $examples = DB::select(<<<SQL
            SELECT code, detail, records FROM (
                SELECT f->>'code' AS code, f->>'detail' AS detail, COUNT(*) AS records,
                    ROW_NUMBER() OVER (PARTITION BY f->>'code' ORDER BY COUNT(*) DESC, f->>'detail') AS rank
                {$failures}
                GROUP BY 1, 2
            ) ranked WHERE rank <= 3 ORDER BY code, records DESC, detail
            SQL);

        return new BackfillReport(
            submissions: (int) ($totals->submissions ?? 0),
            superseded: (int) ($totals->superseded ?? 0),
            rounds: array_map(fn (object $row): array => [
                'total' => (bool) $row->is_total,
                'round_id' => $row->round_id === null ? null : (int) $row->round_id,
                'year' => $row->year === null ? null : (int) $row->year,
                'quarter' => $row->quarter === null ? null : (int) $row->quarter,
                'pulled' => (int) $row->pulled,
                'accepted' => (int) $row->accepted,
                'flagged' => (int) $row->flagged,
                'quarantined' => (int) $row->quarantined,
                'rejected' => (int) $row->rejected,
                'unprocessed' => (int) $row->unprocessed,
            ], $rounds),
            byYear: array_map(fn (object $row): array => ['code' => (string) $row->code, 'year' => (int) $row->year, 'records' => (int) $row->records], $byYear),
            byVersion: array_map(fn (object $row): array => ['code' => (string) $row->code, 'version' => (string) $row->version, 'records' => (int) $row->records], $byVersion),
            examples: array_map(fn (object $row): array => ['code' => (string) $row->code, 'detail' => (string) $row->detail, 'records' => (int) $row->records], $examples),
        );
    }
}
