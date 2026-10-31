<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingestion\Exceptions\SourceRefused;
use App\Domain\Ingestion\SourceRegistry;
use App\Support\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Loads a source's facility references (D-72): the KoboToolbox form's own slugs, each matched to a
 * master-list facility code, from a client-signed CSV with the header `ref,facility_code`.
 * Reference data, not assessment data (CLAUDE.md hard rule 1, as D-19).
 *
 * All or nothing: any row that is wrong (an unknown code, a ref twice, a blank) refuses the whole
 * file and changes nothing. A good file replaces every ref the source had, in one transaction,
 * audited with the counts. --dry-run checks the file and loads nothing.
 */
final class LoadFacilitySourceRefs extends Command
{
    protected $signature = 'edqa:facilities:source-refs
        {source : The source the references belong to (kobo)}
        {file : A CSV with the header ref,facility_code}
        {--dry-run : Check the file; load nothing}';

    protected $description = 'Load a source\'s own facility references (e.g. the Kobo form\'s slugs) matched to master-list codes';

    public function handle(SourceRegistry $sources): int
    {
        $key = (string) $this->argument('source');
        try {
            $source = $sources->model($key);
        } catch (SourceRefused $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $file = (string) $this->argument('file');
        $handle = is_file($file) ? fopen($file, 'r') : false;
        if ($handle === false) {
            $this->error((string) __('sources.refs.no_file', ['file' => $file]));

            return self::FAILURE;
        }

        $header = fgetcsv($handle, escape: '\\');
        if (! is_array($header) || array_map(fn (mixed $h): string => strtolower(trim((string) $h)), $header) !== ['ref', 'facility_code']) {
            fclose($handle);
            $this->error((string) __('sources.refs.header'));

            return self::FAILURE;
        }
        $rows = [];
        $problems = [];
        for ($line = 2; ($cells = fgetcsv($handle, escape: '\\')) !== false; $line++) {
            if ($cells === [null]) {
                continue; // a blank line
            }
            $ref = trim((string) ($cells[0] ?? ''));
            $code = trim((string) ($cells[1] ?? ''));
            if ($ref === '' || $code === '') {
                $problems[] = (string) __('sources.refs.blank', ['line' => $line]);
            } elseif (isset($rows[$ref])) {
                $problems[] = (string) __('sources.refs.twice', ['line' => $line, 'ref' => $ref]);
            } else {
                $rows[$ref] = $code;
            }
        }
        fclose($handle);

        $ids = DB::table('facilities')->whereIn('code', array_values(array_unique($rows)))->pluck('id', 'code')->all();
        foreach ($rows as $ref => $code) {
            if (! isset($ids[$code])) {
                $problems[] = (string) __('sources.refs.unknown_code', ['ref' => $ref, 'code' => $code]);
            }
        }
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            $this->error((string) __('sources.refs.refused'));

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info((string) __('sources.refs.checked', ['count' => count($rows), 'source' => $key]));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($source, $rows, $ids): void {
            $before = DB::table('facility_source_refs')->where('source_id', $source->id)->count();
            DB::table('facility_source_refs')->where('source_id', $source->id)->delete();
            $now = now();
            foreach (array_chunk($rows, 500, preserve_keys: true) as $chunk) {
                DB::table('facility_source_refs')->insert(array_map(fn (string $ref, string $code): array => [
                    'source_id' => $source->id, 'ref' => $ref, 'facility_id' => $ids[$code], 'created_at' => $now, 'updated_at' => $now,
                ], array_keys($chunk), $chunk));
            }
            AuditLog::record($source, null, 'facility_source_refs_loaded', old: ['count' => $before], new: ['count' => count($rows)]);
        });
        $this->info((string) __('sources.refs.loaded', ['count' => count($rows), 'source' => $key]));

        return self::SUCCESS;
    }
}
