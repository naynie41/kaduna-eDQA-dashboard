<?php

declare(strict_types=1);

/*
 * Defect scan of the saved KoboToolbox submissions, through the portal's own pipeline: the Kobo
 * normaliser, the parser with the real field map and item rules (config/edqa_field_maps.php,
 * config/edqa_item_rules.php) and all 14 validation rules (ARCHITECTURE.md §6). The "defect_scan"
 * of the Phase 0 plan; rule_preview.py re-implements the rules in Python and cannot read item rules.
 *
 * READ-ONLY. The database session is set read-only before anything runs, so nothing can be
 * written; nothing is stored, validated into an assessment, or quarantined. The reference data
 * (LGAs, facilities, rounds, accepted assessments) is whatever the database it runs against holds:
 * on a developer machine that is the synthetic master list, so FACILITY_UNKNOWN and ROUND_WINDOW
 * there say little about the real data.
 *
 * Reads discovery/data/submissions/kobo/page_*.json (kobo_submissions.py) and
 * discovery/data/forms/kobo/facility_choices.csv (kobo_item_rules.py, for facility names).
 * Writes discovery/out/kobo_defect_scan.md (counts and anonymised examples: no assessor names, no
 * answers) and discovery/data/kobo_facilities.csv (the distinct facilities submitted, for
 * facility_reconcile.py; git-ignored).
 *
 * Run inside the app container: php discovery/defect_scan.php
 */

use App\Domain\Ingestion\DTOs\ItemResponseData;
use App\Domain\Ingestion\DTOs\ParsedSubmission;
use App\Domain\Ingestion\FieldMap;
use App\Domain\Ingestion\Kobo\KoboPayloadNormalizer;
use App\Domain\Ingestion\SubmissionParser;
use App\Domain\Validation\DTOs\RuleFailure;
use App\Domain\Validation\ValidationContextFactory;
use App\Domain\Validation\ValidationPipeline;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'); // nothing below can write

$dir = __DIR__.'/data/submissions/kobo';
$rows = [];
foreach (glob("{$dir}/page_*.json") ?: [] as $file) {
    $page = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    array_push($rows, ...($page['results'] ?? []));
}
if ($rows === []) {
    fwrite(STDERR, "No submissions in {$dir}; run kobo_submissions.py first\n");
    exit(1);
}

$normaliser = app(KoboPayloadNormalizer::class);
$parser = app(SubmissionParser::class);
/** @var list<ParsedSubmission> $parsed */
$parsed = array_map(fn (array $row): ParsedSubmission => $parser->parse($normaliser->normalise($row)), $rows);

// The batch, as the pipeline validates one: one context, each accepted one registered as it passes.
$context = app(ValidationContextFactory::class)->forBatch(array_values(array_filter($parsed, fn (ParsedSubmission $p): bool => $p->outOfScope === null)));
$pipeline = app(ValidationPipeline::class);
$outcome = ['out_of_scope' => 0, 'accepted' => 0, 'flagged' => 0, 'quarantined' => 0];
$byRuleYear = [];
$byRuleVersion = [];
$examples = [];
$severity = [];
$assessors = array_values(array_unique(array_filter(array_map(fn (ParsedSubmission $p): string => trim($p->assessorName), $parsed))));
$mask = fn (string $id): string => preg_replace('/^(uuid:)?.*(.{4})$/', '$1…$2', $id) ?? '…';
$anonymise = function (string $detail) use ($assessors, $parsed, $mask): string {
    foreach ($assessors as $name) {
        $detail = str_ireplace($name, '[assessor]', $detail);
    }
    foreach ($parsed as $p) {
        $detail = str_replace($p->instanceId, $mask($p->instanceId), $detail);
    }

    return $detail;
};

foreach ($parsed as $p) {
    if ($p->outOfScope !== null) {
        $outcome['out_of_scope']++;

        continue;
    }
    $result = $pipeline->validate($p, $context);
    $outcome[! $result->passed() ? 'quarantined' : ($result->isFlagged() ? 'flagged' : 'accepted')]++;
    if ($result->passed()) {
        $context->registerAccepted($p);
    }
    $year = (string) ($p->roundYear ?? '?');
    foreach ($result->failures() as $failure) {
        /** @var RuleFailure $failure */
        $severity[$failure->code] = $failure->severity->value;
        $byRuleYear[$failure->code][$year] = ($byRuleYear[$failure->code][$year] ?? 0) + 1;
        $byRuleVersion[$failure->code][$p->formVersion] = ($byRuleVersion[$failure->code][$p->formVersion] ?? 0) + 1;
        if (count($examples[$failure->code] ?? []) < 3) {
            $examples[$failure->code][] = $mask($p->instanceId).': '.$anonymise($failure->detail);
        }
    }
}

// What the field map read, and what it could not.
$known = array_filter($parsed, fn (ParsedSubmission $p): bool => $p->formVersionKnown);
$unread = [
    'form version with no field map' => count($parsed) - count($known),
    'visit mode not read (period_visit)' => 0, 'reviewed month or quarter not read' => 0,
    'no start time' => 0, 'no end time' => 0, 'no LGA' => 0, 'no ward' => 0, 'no facility' => 0, 'no assessor' => 0,
];
$invalid = [];
$naShare = [];
foreach ($known as $i => $p) {
    $map = FieldMap::for($p->source, $p->formVersion);
    $data = $normaliser->normalise($rows[$i])->data;
    if ($map?->hasVisitMode() && $map->visitMode($data) === null) {
        $unread['visit mode not read (period_visit)']++;
    } elseif ($p->roundQuarter === null) {
        $unread['reviewed month or quarter not read']++;
    }
    $unread['no start time'] += $p->startedAt === null ? 1 : 0;
    $unread['no end time'] += $p->endedAt === null ? 1 : 0;
    $unread['no LGA'] += trim((string) $p->lgaRef) === '' ? 1 : 0;
    $unread['no ward'] += trim((string) $p->wardRef) === '' ? 1 : 0;
    $unread['no facility'] += trim((string) $p->facilityRef) === '' ? 1 : 0;
    $unread['no assessor'] += trim($p->assessorName) === '' ? 1 : 0;
    foreach ($p->items as $item) {
        if ($item->value === ItemResponseData::INVALID) {
            $invalid[$item->dimension->value.' '.$item->itemCode] = ($invalid[$item->dimension->value.' '.$item->itemCode] ?? 0) + 1;
        }
    }
    $na = count(array_filter($p->items, fn (ItemResponseData $it): bool => $it->value === 'na'));
    $naShare[] = $p->items === [] ? null : $na / count($p->items);
}

// LGAs: every distinct value, and whether it is one of the 23.
$lgaNames = DB::table('lgas')->pluck('name', 'code')->all();
$lgaKeys = [];
foreach ($lgaNames as $code => $name) {
    $lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower((string) $code))] = $name;
    $lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower((string) $name))] = $name;
}
$lgas = [];
foreach ($parsed as $p) {
    $value = $p->lgaRef === null || trim($p->lgaRef) === '' ? '(none)' : $p->lgaRef;
    $lgas[$value] = ($lgas[$value] ?? 0) + 1;
}
ksort($lgas);

// Edits, chains and duplicated instance IDs.
$byInstance = array_count_values(array_map(fn (ParsedSubmission $p): string => $p->instanceId, $parsed));
$duplicated = array_keys(array_filter($byInstance, fn (int $n): bool => $n > 1));
$edits = array_values(array_filter($parsed, fn (ParsedSubmission $p): bool => $p->deprecatedId !== null));
$byExternal = [];
foreach ($parsed as $p) {
    $byExternal[(string) $p->externalId][] = $p->instanceId;
}
$sameExternal = array_filter($byExternal, fn (array $ids): bool => count($ids) > 1);
$byVisit = [];
foreach ($parsed as $p) {
    $byVisit[($p->facilityRef ?? '?').'|'.($p->roundYear ?? '?').'Q'.($p->roundQuarter ?? '?').'|'.json_encode($p->monthSlots)][] = $p->instanceId;
}
$sameVisit = array_filter($byVisit, fn (array $ids): bool => count($ids) > 1);

// The distinct facilities, for facility_reconcile.py (D-72).
$choices = [];
$choicesFile = __DIR__.'/data/forms/kobo/facility_choices.csv';
if (is_file($choicesFile) && ($h = fopen($choicesFile, 'r')) !== false) {
    $header = fgetcsv($h, escape: '\\');
    while (($line = fgetcsv($h, escape: '\\')) !== false) {
        if (is_array($header) && count($line) === count($header)) {
            $r = array_combine($header, $line);
            $choices[$r['ref']] = $r;
        }
    }
    fclose($h);
}
$facilities = [];
foreach ($rows as $i => $row) {
    $ref = (string) ($parsed[$i]->facilityRef ?? '');
    if ($ref === '') {
        continue;
    }
    $facilities[$ref] ??= ['kobo_ref' => $ref, 'facility_name' => $choices[$ref]['facility_name'] ?? '', 'ward' => $choices[$ref]['ward'] ?? (string) $parsed[$i]->wardRef,
        'lga' => $lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower((string) $parsed[$i]->lgaRef))] ?? (string) $parsed[$i]->lgaRef,
        'facility_level' => (string) ($row['description_facility/level_care'] ?? ''), 'ownership' => (string) ($row['description_facility/facility_type'] ?? ''), 'submissions' => 0];
    $facilities[$ref]['submissions']++;
}
$csv = fopen(__DIR__.'/data/kobo_facilities.csv', 'w');
fputcsv($csv, ['kobo_ref', 'facility_name', 'ward', 'lga', 'facility_level', 'ownership', 'submissions'], escape: '\\');
foreach ($facilities as $f) {
    fputcsv($csv, array_values($f), escape: '\\');
}
fclose($csv);

// ---- The report
$years = array_values(array_unique(array_merge(...array_map('array_keys', array_values($byRuleYear ?: [[]])))));
sort($years);
$versions = array_values(array_unique(array_map(fn (ParsedSubmission $p): string => $p->formVersion, $parsed)));
$short = fn (string $v): string => '`'.substr($v, 0, 8).'…`';
ksort($byRuleYear);
$L = ['# KoboToolbox defect scan', '',
    'Generated '.now()->format('Y-m-d H:i').' by `discovery/defect_scan.php`: '.count($rows).' submission(s) from '
    .'`data/submissions/kobo/`, through the portal\'s own normaliser, parser (the real field map and item rules) and all 14 '
    .'validation rules, read-only. Reference data from the `'.DB::connection()->getDatabaseName().'` database'
    .(app()->environment('local') ? ' (**local: a synthetic master list and demo rounds**, so FACILITY_UNKNOWN and ROUND_WINDOW below reflect that, not the real data)' : '').'.', '',
    '## Outcome', '', '| | Submissions |', '|---|---|',
    '| Scanned | '.count($rows).' |', '| Would be accepted | '.$outcome['accepted'].' |', '| Would be accepted with a flag | '.$outcome['flagged'].' |',
    '| Would be quarantined | '.$outcome['quarantined'].' |', '| Not a facility assessment (out of scope, D-74) | '.$outcome['out_of_scope'].' |', '',
    '## Failures by rule and year (the reviewed year)', '',
    '| Rule | Severity | '.implode(' | ', $years).' | Total |', '|---|---|'.str_repeat('---|', count($years) + 1)];
foreach ($byRuleYear as $code => $counts) {
    $L[] = "| `{$code}` | {$severity[$code]} | ".implode(' | ', array_map(fn ($y) => $counts[$y] ?? 0, $years)).' | '.array_sum($counts).' |';
}
if ($byRuleYear === []) {
    $L[] = '| none | | | |';
}
$L = [...$L, '', '## Failures by rule and form version', '', '| Rule | '.implode(' | ', array_map($short, $versions)).' |', '|---|'.str_repeat('---|', count($versions))];
foreach ($byRuleVersion as $code => $counts) {
    $L[] = "| `{$code}` | ".implode(' | ', array_map(fn ($v) => $counts[$v] ?? 0, $versions)).' |';
}
$L = [...$L, '', '## Examples (up to 3 per rule; instance IDs shortened, assessor names removed)', ''];
foreach ($examples as $code => $list) {
    $L[] = "**`{$code}`**";
    foreach ($list as $e) {
        $L[] = '- `'.str_replace('`', "'", $e).'`';
    }
    $L[] = '';
}
$L = [...$L, '## LGAs and facilities', '', '| LGA value submitted | Submissions | One of the 23? |', '|---|---|---|'];
foreach ($lgas as $value => $n) {
    $match = $value === '(none)' ? '**no: blank**' : (isset($lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower($value))]) ? 'yes: '.$lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower($value))] : '**no: not an LGA**');
    $L[] = "| `{$value}` | {$n} | {$match} |";
}
$blank = $lgas['(none)'] ?? 0;
$foreign = count(array_filter(array_keys($lgas), fn ($v) => $v !== '(none)' && ! isset($lgaKeys[preg_replace('/[^a-z0-9]/', '', strtolower($v))])));
$L = [...$L, '', '- A blank LGA: '.($blank > 0 ? "**yes, {$blank}**" : 'none').'. A value that is not one of the 23 (a "24th LGA"): '.($foreign > 0 ? "**yes, {$foreign}**" : 'none').'.',
    '- Distinct facility references: '.count($facilities).' (written to `data/kobo_facilities.csv` for `facility_reconcile.py`).', '',
    '## Edits and duplicates', '',
    '- Edits (`meta/deprecatedID` set): '.count($edits).'.'.($edits === [] ? '' : ' '.implode(', ', array_map(fn ($p) => $mask((string) $p->deprecatedId).' → '.$mask($p->instanceId), $edits))),
    '- Instance IDs that appear more than once: '.(count($duplicated) === 0 ? 'none' : count($duplicated).': '.implode(', ', array_map($mask, $duplicated))).'.',
    '- Kobo `_id`s with more than one version (an edit chain): '.(count($sameExternal) === 0 ? 'none' : (string) count($sameExternal)).'.',
    '- Visits to the same facility for the same reviewed period and month slot (would be DUPLICATE_ASSESSMENT): '.(count($sameVisit) === 0 ? 'none' : count($sameVisit).': '.implode('; ', array_map(fn ($ids) => implode(', ', array_map($mask, $ids)), $sameVisit))).'.', '',
    '## What the field map could not read', '', '| | Submissions |', '|---|---|'];
foreach ($unread as $what => $n) {
    $L[] = "| {$what} | ".($n > 0 ? "**{$n}**" : '0').' |';
}
$L[] = '';
$L[] = $invalid === [] ? '- No item answer outside what its rule reads (no `invalid` item).' : '- Item answers outside what the rule reads (`invalid`): '.implode(', ', array_map(fn ($k, $n) => "`{$k}` ({$n})", array_keys($invalid), $invalid)).'.';
$shares = array_filter($naShare, fn ($s) => $s !== null);
$L[] = '- Share of items not applicable per submission: '.($shares === [] ? 'n/a' : 'from '.round(min($shares) * 100).'% to '.round(max($shares) * 100).'%').' (services not offered, or questions left unanswered).';
file_put_contents(__DIR__.'/out/kobo_defect_scan.md', implode("\n", $L)."\n");
echo 'Scanned '.count($rows).': '.json_encode($outcome).". Wrote discovery/out/kobo_defect_scan.md, discovery/data/kobo_facilities.csv\n";
