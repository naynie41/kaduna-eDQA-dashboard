<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Reports;

use App\Domain\Ingestion\DTOs\BackfillReport;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Writes the backfill report (BackfillReportQuery) to the private exports disk
 * (storage/app/exports) as Markdown, to read, or CSV, to open in a spreadsheet: one long table,
 * one figure a row (section, rule, round, year, version, measure, value, detail). It only lays
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
            '',
            '## '.$t('by_round'),
            '',
        ];
        $columns = ['pulled', 'accepted', 'flagged', 'quarantined', 'rejected', 'unprocessed'];
        $lines[] = self::row([$t('round'), ...array_map($t, $columns)]);
        $lines[] = self::row(array_fill(0, count($columns) + 1, '---'));
        foreach ($report->rounds as $round) {
            $label = $round['total'] ? '**'.$t('total').'**' : $this->roundLabel($round['year'], $round['quarter']);
            $lines[] = self::row([$label, ...array_map(fn (string $c): string => (string) $round[$c], $columns)]);
        }
        $lines[] = self::row([$t('superseded'), (string) $report->superseded, '', '', '', '', '']);

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
            $lines[] = self::row([$t('rule'), $t($key), $t('records')]);
            $lines[] = self::row(['---', '---', '---:']);
            foreach ($rows as $row) {
                $lines[] = self::row([$row['code'], (string) $row[$key], (string) $row['records']]);
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
        $rows = [['section', 'rule', 'round', 'year', 'version', 'measure', 'value', 'detail']];
        $rows[] = ['totals', '', '', '', '', 'submissions', $report->submissions, ''];
        $rows[] = ['totals', '', '', '', '', 'superseded', $report->superseded, ''];
        foreach ($report->rounds as $round) {
            $label = $round['total'] ? 'total' : $this->roundLabel($round['year'], $round['quarter']);
            foreach (['pulled', 'accepted', 'flagged', 'quarantined', 'rejected', 'unprocessed'] as $measure) {
                $rows[] = ['by_round', '', $label, $round['year'] ?? '', '', $measure, $round[$measure], ''];
            }
        }
        foreach ($report->byYear as $row) {
            $rows[] = ['by_rule_year', $row['code'], '', $row['year'], '', 'records', $row['records'], ''];
        }
        foreach ($report->byVersion as $row) {
            $rows[] = ['by_rule_version', $row['code'], '', '', $row['version'], 'records', $row['records'], ''];
        }
        foreach ($report->examples as $row) {
            $rows[] = ['examples', $row['code'], '', '', '', 'records', $row['records'], $row['detail']];
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

    private function roundLabel(?int $year, ?int $quarter): string
    {
        return $year === null || $quarter === null ? (string) __('backfill.report.no_round') : "Q{$quarter} {$year}";
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
