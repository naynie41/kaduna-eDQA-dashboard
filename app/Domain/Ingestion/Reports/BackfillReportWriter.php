<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Reports;

use App\Domain\Ingestion\DTOs\BackfillReport;
use App\Domain\Round\Support\RoundLabel;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Writes the backfill report (BackfillReportQuery) to the private exports disk
 * (storage/app/exports) as Markdown, to read, or CSV, to open in a spreadsheet: one long table,
 * one figure a row (section, source, rule, round, year, version, measure, value, detail). The
 * Markdown has a section per source, then all sources, then the duplicates between them. It only lays
 * out the query's figures; the totals come from the query too. Excel follows in Phase 6.
 */
final class BackfillReportWriter
{
    public const FORMATS = ['md', 'csv'];

    /** @return string the path on the exports disk */
    public function write(BackfillReport $report, string $format): string
    {
        $body = match ($format) {
            'md' => $this->markdown($report),
            'csv' => $this->csv($report),
            default => throw new InvalidArgumentException("Unknown report format {$format}."),
        };
        $path = 'backfill-report-'.now()->format('Ymd-His').'.'.$format;
        Storage::disk('exports')->put($path, $body);

        return $path;
    }

    private function markdown(BackfillReport $report): string
    {
        $t = fn (string $key, array $replace = []): string => (string) __("backfill.report.{$key}", $replace);
        $lines = [
            '# '.$t('title'),
            '',
            $t('generated', ['at' => now()->toDayDateTimeString()]),
            '',
            $t('totals', ['submissions' => $report->submissions, 'superseded' => $report->superseded]),
        ];
        $columns = ['pulled', 'accepted', 'flagged', 'quarantined', 'rejected', 'unprocessed'];

        // One section per source, its rounds then its total; the grand total last.
        $bySource = [];
        $grand = null;
        foreach ($report->rounds as $round) {
            if ($round['source'] === null) {
                $grand = $round;
            } else {
                $bySource[$round['source']][] = $round;
            }
        }
        foreach ($bySource as $source => $rounds) {
            array_push($lines, '', '## '.self::sourceName($source), '', '### '.$t('by_round'), '');
            $lines[] = self::row([$t('round'), ...array_map($t, $columns)]);
            $lines[] = self::row(array_fill(0, count($columns) + 1, '---'));
            foreach ($rounds as $round) {
                $label = $round['total'] ? '**'.$t('total').'**' : $this->roundLabel($round['year'], $round['quarter']);
                $lines[] = self::row([$label, ...array_map(fn (string $c): string => (string) $round[$c], $columns)]);
            }
        }
        if ($grand !== null) {
            array_push($lines, '', '## '.$t('all_sources'), '');
            $lines[] = self::row(['', ...array_map($t, $columns)]);
            $lines[] = self::row(array_fill(0, count($columns) + 1, '---'));
            $lines[] = self::row(['**'.$t('total').'**', ...array_map(fn (string $c): string => (string) $grand[$c], $columns)]);
            $lines[] = self::row([$t('superseded'), (string) $report->superseded, '', '', '', '', '']);
        }

        array_push($lines, '', '## '.$t('duplicates'), '', $t('duplicates_note'), '');
        $lines[] = self::row([$t('source'), 'DUPLICATE_ASSESSMENT', $t('against_other_source'), $t('cross_source_duplicates')]);
        $lines[] = self::row(['---', '---:', '---:', '---:']);
        foreach ($report->duplicates as $row) {
            $lines[] = self::row([self::sourceName($row['source']), (string) $row['duplicate_assessment'], (string) $row['against_other_source'], (string) $row['cross_source_duplicates']]);
        }

        $sections = [
            ['by_rule_year', 'year', $report->byYear],
            ['by_rule_version', 'version', $report->byVersion],
        ];
        foreach ($sections as [$title, $key, $rows]) {
            array_push($lines, '', '## '.$t($title), '', $t('rule_note'), '');
            if ($rows === []) {
                $lines[] = $t('none');

                continue;
            }
            $lines[] = self::row([$t('source'), $t('rule'), $t($key), $t('records')]);
            $lines[] = self::row(['---', '---', '---', '---:']);
            foreach ($rows as $row) {
                $lines[] = self::row([self::sourceName($row['source']), $row['code'], (string) $row[$key], (string) $row['records']]);
            }
        }

        array_push($lines, '', '## '.$t('examples'), '');
        if ($report->examples === []) {
            $lines[] = $t('none');
        } else {
            $lines[] = self::row([$t('rule'), $t('detail'), $t('records')]);
            $lines[] = self::row(['---', '---', '---:']);
            foreach ($report->examples as $example) {
                $lines[] = self::row([$example['code'], $example['detail'], (string) $example['records']]);
            }
        }

        return implode("\n", $lines)."\n";
    }

    private function csv(BackfillReport $report): string
    {
        $rows = [['section', 'source', 'rule', 'round', 'year', 'version', 'measure', 'value', 'detail']];
        $rows[] = ['totals', '', '', '', '', '', 'submissions', $report->submissions, ''];
        $rows[] = ['totals', '', '', '', '', '', 'superseded', $report->superseded, ''];
        foreach ($report->rounds as $round) {
            $label = $round['total'] ? 'total' : $this->roundLabel($round['year'], $round['quarter']);
            foreach (['pulled', 'accepted', 'flagged', 'quarantined', 'rejected', 'unprocessed'] as $measure) {
                $rows[] = ['by_round', $round['source'] ?? '', '', $label, $round['year'] ?? '', '', $measure, $round[$measure], ''];
            }
        }
        foreach ($report->duplicates as $row) {
            foreach (['duplicate_assessment', 'against_other_source', 'cross_source_duplicates'] as $measure) {
                $rows[] = ['duplicates', $row['source'], '', '', '', '', $measure, $row[$measure], ''];
            }
        }
        foreach ($report->byYear as $row) {
            $rows[] = ['by_rule_year', $row['source'], $row['code'], '', $row['year'], '', 'records', $row['records'], ''];
        }
        foreach ($report->byVersion as $row) {
            $rows[] = ['by_rule_version', $row['source'], $row['code'], '', '', $row['version'], 'records', $row['records'], ''];
        }
        foreach ($report->examples as $row) {
            $rows[] = ['examples', '', $row['code'], '', '', '', 'records', $row['records'], $row['detail']];
        }

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new InvalidArgumentException('Cannot open a buffer for the CSV.');
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /** A source as administrators know it ("ODK Central"); its key when config has no name. */
    private static function sourceName(string $key): string
    {
        $name = config("edqa.sources.{$key}.name");

        return is_string($name) && $name !== '' ? $name : $key;
    }

    private function roundLabel(?int $year, ?int $quarter): string
    {
        return $year === null || $quarter === null ? (string) __('backfill.report.no_round') : RoundLabel::of($year, $quarter);
    }

    /** @param  list<string>  $cells */
    private static function row(array $cells): string
    {
        return '| '.implode(' | ', array_map(fn (string $c): string => str_replace(['|', "\n"], ['\\|', ' '], $c), $cells)).' |';
    }

    /** A spreadsheet must not read a detail as a formula (CSV injection). */
    private static function cell(string|int $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
