<?php

declare(strict_types=1);

/*
 * `make e2e-sources` only: makes one accepted KoboToolbox submission disappear from the Kobo mock,
 * so the next `edqa:reconcile kobo` finds it deleted at source (D-62), or puts it back. The mock
 * serves whatever is in storage/app/kobo-mock/submissions (docker/kobo-mock/handler.php), so the
 * file is moved aside, never deleted.
 *
 *   php tests/e2e/support/kobo-mock-delete.php delete    # move one accepted Kobo visit's file aside
 *   php tests/e2e/support/kobo-mock-delete.php restore   # put it back
 */
$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$mock = $root.'/storage/app/kobo-mock';
$aside = $mock.'/e2e-deleted';
$mode = $argv[1] ?? '';

if ($mode === 'restore') {
    foreach (glob($aside.'/*.json') ?: [] as $file) {
        rename($file, $mock.'/submissions/'.basename($file));
    }
    @rmdir($aside);
    echo "Kobo mock: deleted submission restored.\n";
    exit(0);
}
if ($mode !== 'delete') {
    fwrite(STDERR, "Usage: delete|restore\n");
    exit(1);
}

// An accepted Kobo visit in an open round, so it can be withdrawn.
$instance = DB::table('assessments as a')
    ->join('submissions as s', 's.id', '=', 'a.submission_id')
    ->join('submission_sources as src', 'src.id', '=', 's.source_id')
    ->join('rounds as r', 'r.id', '=', 'a.round_id')
    ->where('src.key', 'kobo')->where('a.status', 'accepted')->where('r.status', 'open')->whereNull('s.deleted_at_source')
    ->orderBy('a.id')->value('s.instance_id');
if (! is_string($instance)) {
    fwrite(STDERR, "No accepted KoboToolbox assessment in an open round: run Pull all first.\n");
    exit(1);
}

foreach (glob($mock.'/submissions/*.json') ?: [] as $file) {
    $row = json_decode((string) file_get_contents($file), true);
    if (is_array($row) && ($row['meta/instanceID'] ?? null) === $instance) {
        @mkdir($aside);
        rename($file, $aside.'/'.basename($file));
        echo "Kobo mock: {$instance} deleted at source.\n";
        exit(0);
    }
}
fwrite(STDERR, "The mock has no file for {$instance}.\n");
exit(1);
