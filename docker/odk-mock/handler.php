<?php

declare(strict_types=1);

/*
 * A fake ODK Central (docker/odk-mock/README.md): only what OdkCentralSource calls, served from
 * JSON files written by `artisan edqa:odk-mock:generate`. A pure function, so the router serves it
 * and tests call it directly.
 *
 * Data directory:
 *   config.json                 credentials, project, form, versions, edits_applied
 *   submissions/*.json          one OData entry each (the version first submitted)
 *   edits/*.json                edited versions (meta.deprecatedID), served once edits_applied
 *   attachments/<instance>/*    files, the instance ID with ':' as '_' (Windows mounts)
 *
 * Like Central, the Submissions feed shows one row per submission (__id): its latest version. An
 * edit keeps the original __id and submissionDate, takes a new meta.instanceID with
 * meta.deprecatedID, sets __system.updatedAt and counts __system.edits.
 */

/**
 * @param  array<string, string>  $headers  lower-case names
 * @return array{status: int, headers: array<string, string>, body: string, file?: string}
 */
function odk_mock_handle(string $method, string $uri, array $headers, string $body, string $dataDir): array
{
    $config = odk_mock_json($dataDir.'/config.json');
    if ($config === null) {
        return odk_mock_error(503, 'The mock has no data: run `make odk-mock-seed`.');
    }
    $path = (string) parse_url($uri, PHP_URL_PATH);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
    $token = 'mock-'.substr(hash('sha256', (string) ($config['email'] ?? '')), 0, 32);

    if ($method === 'POST' && $path === '/v1/sessions') {
        $credentials = json_decode($body, true);
        if (! is_array($credentials) || ($credentials['email'] ?? null) !== ($config['email'] ?? null) || ($credentials['password'] ?? null) !== ($config['password'] ?? null)) {
            return odk_mock_error(401, 'Could not authenticate with the provided credentials.');
        }

        return odk_mock_ok(['token' => $token, 'expiresAt' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 86400), 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')]);
    }

    if ($method !== 'GET') {
        return odk_mock_error(405, 'The mock is read-only.');
    }
    if (($headers['authorization'] ?? '') !== 'Bearer '.$token) {
        return odk_mock_error(401, 'Could not authenticate with the provided credentials.');
    }
    if ($path === '/v1/users/current') {
        return odk_mock_ok($config['account'] ?? ['displayName' => 'eDQA portal (mock)']);
    }

    $project = (string) ($config['project']['id'] ?? '');
    $form = (string) ($config['form']['xmlFormId'] ?? '');
    if (preg_match('#^/v1/projects/([^/]+)(/forms/([^/]+?)(\.svc/Submissions|/versions|/submissions/([^/]+)/attachments(/([^/]+))?)?)?$#', $path, $m) !== 1) {
        return odk_mock_error(404, 'Not found.');
    }
    if (rawurldecode($m[1]) !== $project || (isset($m[3]) && $m[3] !== '' && rawurldecode($m[3]) !== $form)) {
        return odk_mock_error(404, 'Could not find the resource you were looking for.');
    }
    if (! isset($m[2]) || $m[2] === '') {
        return odk_mock_ok($config['project'] ?? []);
    }
    $rest = $m[4] ?? '';
    if ($rest === '') {
        return odk_mock_ok($config['form'] ?? []);
    }
    if ($rest === '/versions') {
        return odk_mock_ok($config['versions'] ?? []);
    }
    if ($rest === '.svc/Submissions') {
        return odk_mock_submissions($dataDir, $config, $query);
    }

    // Attachments of one submission (by its current instance ID, as Central lists them).
    $instance = rawurldecode($m[5] ?? '');
    $dir = $dataDir.'/attachments/'.str_replace(':', '_', $instance);
    if (($m[7] ?? '') === '') {
        $files = is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], fn (string $f): bool => $f[0] !== '.')) : [];

        return odk_mock_ok(array_map(fn (string $f): array => ['name' => $f, 'exists' => true], $files));
    }
    $name = rawurldecode($m[7]);
    $file = $dir.'/'.basename($name);
    if ($name !== basename($name) || ! is_file($file)) {
        return odk_mock_error(404, 'Could not find the resource you were looking for.');
    }
    $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'geojson' => 'application/geo+json', default => 'application/octet-stream',
    };

    return ['status' => 200, 'headers' => ['Content-Type' => $type, 'Content-Length' => (string) filesize($file)], 'body' => '', 'file' => $file];
}

/**
 * The OData feed: latest version per __id, $filter on __system/submissionDate or updatedAt
 * (comparisons joined by "and" / "or"), oldest submission first, $top, $skip and $count.
 *
 * @param  array<string, mixed>  $config
 * @param  array<array-key, mixed>  $query
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function odk_mock_submissions(string $dataDir, array $config, array $query): array
{
    $latest = [];
    $folders = ($config['edits_applied'] ?? false) === true ? ['submissions', 'edits'] : ['submissions'];
    foreach ($folders as $folder) {
        foreach (glob($dataDir.'/'.$folder.'/*.json') ?: [] as $file) {
            $row = odk_mock_json($file);
            if (is_array($row) && is_string($row['__id'] ?? null)) {
                $current = $latest[$row['__id']] ?? null;
                if ($current === null || (int) ($row['__system']['edits'] ?? 0) >= (int) ($current['__system']['edits'] ?? 0)) {
                    $latest[$row['__id']] = $row;
                }
            }
        }
    }
    $rows = array_values($latest);

    $filter = is_string($query['$filter'] ?? null) ? trim($query['$filter']) : '';
    if ($filter !== '') {
        $test = odk_mock_filter($filter);
        if ($test === null) {
            return odk_mock_error(400, 'The mock supports $filter only on __system/submissionDate and __system/updatedAt.');
        }
        $rows = array_values(array_filter($rows, $test));
    }
    // $orderby on __system/submissionDate (the default) or __system/updatedAt, ascending.
    $order = preg_match('#^__system/(submissionDate|updatedAt)( asc)?$#', (string) ($query['$orderby'] ?? ''), $o) === 1 ? $o[1] : 'submissionDate';
    usort($rows, fn (array $a, array $b): int => [$a['__system'][$order] ?? '', $a['__id']] <=> [$b['__system'][$order] ?? '', $b['__id']]);

    $total = count($rows);
    $skip = max(0, (int) ($query['$skip'] ?? 0));
    $top = isset($query['$top']) ? max(0, (int) $query['$top']) : $total;
    $page = array_slice($rows, $skip, $top);
    $body = ['@odata.context' => 'mock/$metadata#Submissions', 'value' => $page];
    if (($query['$count'] ?? '') === 'true') {
        $body = ['@odata.context' => $body['@odata.context'], '@odata.count' => $total, 'value' => $page];
    }

    return odk_mock_ok($body);
}

/** @return (callable(array<string, mixed>): bool)|null */
function odk_mock_filter(string $filter): ?callable
{
    $any = [];
    foreach (preg_split('/\s+or\s+/i', $filter) ?: [] as $disjunct) {
        $all = [];
        foreach (preg_split('/\s+and\s+/i', trim($disjunct, ' ()')) ?: [] as $part) {
            if (preg_match('#^\(?__system/(submissionDate|updatedAt)\s+(gt|ge|lt|le|eq)\s+(\S+?)\)?$#', trim($part), $m) !== 1 || strtotime($m[3]) === false) {
                return null;
            }
            $all[] = [$m[1], $m[2], (float) (new DateTimeImmutable($m[3]))->format('U.u')];
        }
        $any[] = $all;
    }

    return function (array $row) use ($any): bool {
        foreach ($any as $all) {
            $ok = true;
            foreach ($all as [$field, $op, $value]) {
                $raw = $row['__system'][$field] ?? null;
                $time = is_string($raw) ? (float) (new DateTimeImmutable($raw))->format('U.u') : null;
                $ok = $ok && $time !== null && match ($op) {
                    'gt' => $time > $value, 'ge' => $time >= $value, 'lt' => $time < $value, 'le' => $time <= $value, default => $time === $value,
                };
            }
            if ($ok) {
                return true;
            }
        }

        return false;
    };
}

/** @return array<array-key, mixed>|null */
function odk_mock_json(string $file): ?array
{
    if (! is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function odk_mock_ok(mixed $data): array
{
    return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($data, JSON_UNESCAPED_SLASHES)];
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function odk_mock_error(int $status, string $message): array
{
    return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode(['code' => $status * 1000, 'message' => $message])];
}
