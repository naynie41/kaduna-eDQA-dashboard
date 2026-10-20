<?php

declare(strict_types=1);

namespace App\Domain\Facility\Exports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The ODK cascading-select media files (ARCHITECTURE.md §5.1, D-54): LGA → ward → facility, so an
 * assessor picks a facility from the master list instead of typing it. Three CSVs in the layout
 * select_one_from_file reads, a name column holding the code the field map reads back:
 *
 *   lgas.csv        name, label              LGAs with at least one active facility
 *   wards.csv       name, label, lga         wards with at least one active facility
 *   facilities.csv  name, label, lga, ward   active facilities only
 *
 * The form filters wards by `lga = ${lga}` and facilities by `ward = ${ward}`. Read through the
 * facilities' partial index on active rows (facilities_lga_id_active_index); every list sorted by
 * label, then code. Written as one zip to the private exports disk (cascade/), never to a public
 * path; uploading it to ODK Central as form media is a manual step (docs/odk-cascade-media.md).
 */
final class CascadeExporter
{
    public const DIRECTORY = 'cascade';

    public const FILE_PATTERN = '/^odk-cascade-\d{8}-\d{6}\.zip$/';

    /** @return array{path: string, filename: string, lgas: int, wards: int, facilities: int} */
    public function export(): array
    {
        $facilities = DB::table('facilities as f')
            ->join('lgas as l', 'l.id', '=', 'f.lga_id')
            ->join('wards as w', 'w.id', '=', 'f.ward_id')
            ->where('f.is_active', true)
            ->orderBy('f.lga_id')
            ->get(['f.code', 'f.name', 'l.code as lga_code', 'l.name as lga_name', 'w.code as ward_code', 'w.name as ward_name']);

        $lgas = [];
        $wards = [];
        $rows = [];
        foreach ($facilities as $f) {
            $lgas[(string) $f->lga_code] = [(string) $f->lga_code, (string) $f->lga_name];
            $wards[(string) $f->ward_code] = [(string) $f->ward_code, (string) $f->ward_name, (string) $f->lga_code];
            $rows[] = [(string) $f->code, (string) $f->name, (string) $f->lga_code, (string) $f->ward_code];
        }
        $byLabel = fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]];
        $lgas = array_values($lgas);
        $wards = array_values($wards);
        usort($lgas, $byLabel);
        usort($wards, $byLabel);
        usort($rows, $byLabel);

        $filename = 'odk-cascade-'.now()->format('Ymd-His').'.zip';
        $path = self::DIRECTORY.'/'.$filename;
        $disk = Storage::disk('exports');
        $disk->makeDirectory(self::DIRECTORY);

        $zip = new ZipArchive;
        if ($zip->open($disk->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write the cascade media file.');
        }
        $zip->addFromString('lgas.csv', self::csv(['name', 'label'], $lgas));
        $zip->addFromString('wards.csv', self::csv(['name', 'label', 'lga'], $wards));
        $zip->addFromString('facilities.csv', self::csv(['name', 'label', 'lga', 'ward'], $rows));
        $zip->close();

        return ['path' => $path, 'filename' => $filename, 'lgas' => count($lgas), 'wards' => count($wards), 'facilities' => count($rows)];
    }

    /**
     * UTF-8, comma-separated, quoted where needed, one header row, "\n" line ends (pyxform's
     * expectation). A cell starting = + - @ is prefixed with ' so a spreadsheet never runs it.
     *
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    public static function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new RuntimeException('Cannot open a buffer for the CSV.');
        }
        fputcsv($out, $header, eol: "\n", escape: '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn (string $cell): string => $cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true) ? "'".$cell : $cell, $row), eol: "\n", escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
