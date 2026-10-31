<?php

declare(strict_types=1);

/*
 * A fake KoboToolbox (docker/kobo-mock/README.md): only what KoboSource calls, served from JSON
 * files written by `artisan edqa:source-mock:generate kobo`. A pure function, so the router serves
 * it and tests call it directly.
 *
 * Data directory:
 *   config.json                 token, username, asset (uid, name, deployed version), versions,
 *                               base_url, edits_applied
 *   submissions/NNNN.json       one KPI v2 data row each (the version first submitted)
 *   edits/NNNN.json             the edited version of submissions/NNNN.json, served once
 *                               edits_applied
 *   attachments/<_id>/<name>    files
 *
 * Like Kobo, an edit replaces its row in place: the same _id and _submission_time, a new
 * meta/instanceID with meta/deprecatedID. There is no last-edited field to filter on.
 */

/**
 * @param  array<string, string>  $headers  lower-case names
 * @return array{status: int, headers: array<string, string>, body: string, file?: string}
 */
function kobo_mock_handle(string $method, string $uri, array $headers, string $dataDir): array
{
    $config = kobo_mock_json($dataDir.'/config.json');
    if ($config === null) {
        return kobo_mock_error(503, 'The mock has no data: run `make sources-mock-seed`.');
    }
    if ($method !== 'GET') {
        return kobo_mock_error(405, 'The mock is read-only.');
    }
    if (($headers['authorization'] ?? '') !== 'Token '.($config['token'] ?? '')) {
        return kobo_mock_error(401, 'Invalid token.');
    }
    $path = (string) parse_url($uri, PHP_URL_PATH);
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

    if ($path === '/me/' || $path === '/api/v2/users/me/') {
        return kobo_mock_ok(['username' => $config['username'] ?? 'edqa-mock']);
    }
    $asset = (array) ($config['asset'] ?? []);
    if (preg_match('#^/api/v2/assets/([^/]+)/(versions/|data/|data/(\d+)/attachments/(\d+)/)?$#', $path, $m) !== 1) {
        return kobo_mock_error(404, 'Not found.');
    }
    if (rawurldecode($m[1]) !== ($asset['uid'] ?? null)) {
        return kobo_mock_error(404, 'Not found.');
    }
    $rest = $m[2] ?? '';
    if ($rest === '') {
        return kobo_mock_ok($asset);
    }
    if ($rest === 'versions/') {
        return kobo_mock_ok(kobo_mock_paged(array_values((array) ($config['versions'] ?? [])), $query));
    }
    if ($rest === 'data/') {
        return kobo_mock_ok(kobo_mock_data($dataDir, $config, $query));
    }

    // One attachment of one submission, by its _id and its position in _attachments (1-based).
    $id = (int) $m[3];
    $row = null;
    foreach (kobo_mock_rows($dataDir, $config) as $candidate) {
        if ((int) ($candidate['_id'] ?? 0) === $id) {
            $row = $candidate;
        }
    }
    $attachment = is_array($row) ? ($row['_attachments'][(int) $m[4] - 1] ?? null) : null;
    $file = is_array($attachment) ? $dataDir.'/attachments/'.$id.'/'.basename((string) ($attachment['media_file_basename'] ?? '')) : '';
    if ($file === '' || ! is_file($file)) {
        return kobo_mock_error(404, 'Not found.');
    }

    return ['status' => 200, 'headers' => ['Content-Type' => (string) ($attachment['mimetype'] ?? 'application/octet-stream')], 'body' => '', 'file' => $file];
}

/**
 * The asset's data: the query (a MongoDB document: _submission_time with $gte / $gt / $lt / $lte,
 * or equality on _id or meta/instanceID), sorted (_submission_time then _id, ascending unless -1),
 * paged with start and limit (at most 1000; 100 by default).
 *
 * @param  array<string, mixed>  $config
 * @param  array<array-key, mixed>  $query
 * @return array{count: int, next: ?string, previous: ?string, results: list<array<string, mixed>>}
 */
function kobo_mock_data(string $dataDir, array $config, array $query): array
{
    $filter = json_decode(is_string($query['query'] ?? null) ? $query['query'] : '{}', true);
    $filter = is_array($filter) ? $filter : [];
    $rows = array_values(array_filter(kobo_mock_rows($dataDir, $config), function (array $row) use ($filter): bool {
        foreach ($filter as $field => $condition) {
            $value = $row[$field] ?? null;
            if (is_array($condition)) {
                foreach ($condition as $op => $operand) {
                    $ok = match ($op) {
                        '$gte' => (string) $value >= (string) $operand,
                        '$gt' => (string) $value > (string) $operand,
                        '$lte' => (string) $value <= (string) $operand,
                        '$lt' => (string) $value < (string) $operand,
                        default => false,
                    };
                    if (! $ok) {
                        return false;
                    }
                }
            } elseif ($value !== $condition) {
                return false;
            }
        }

        return true;
    }));

    $sort = json_decode(is_string($query['sort'] ?? null) ? $query['sort'] : '{"_id": 1}', true);
    $sort = is_array($sort) && $sort !== [] ? $sort : ['_id' => 1];
    usort($rows, function (array $a, array $b) use ($sort): int {
        foreach ($sort as $field => $direction) {
            $order = ($a[$field] ?? null) <=> ($b[$field] ?? null);
            if ($order !== 0) {
                return (int) $direction < 0 ? -$order : $order;
            }
        }

        return 0;
    });

    return kobo_mock_paged($rows, $query);
}

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<array-key, mixed>  $query
 * @return array{count: int, next: ?string, previous: ?string, results: list<array<string, mixed>>}
 */
function kobo_mock_paged(array $items, array $query): array
{
    $start = max(0, (int) ($query['start'] ?? 0));
    $limit = max(1, min(1000, (int) ($query['limit'] ?? 100)));
    $page = array_slice($items, $start, $limit);

    return [
        'count' => count($items),
        // Kobo gives the next page as a URL; the portal never follows it (KoboSource).
        'next' => $start + count($page) < count($items) ? '?start='.($start + $limit).'&limit='.$limit : null,
        'previous' => $start > 0 ? '?start='.max(0, $start - $limit).'&limit='.$limit : null,
        'results' => $page,
    ];
}

/**
 * Every row as Kobo shows it now: an edited submission in its edited version, once edits apply.
 *
 * @param  array<string, mixed>  $config
 * @return list<array<string, mixed>>
 */
function kobo_mock_rows(string $dataDir, array $config): array
{
    $rows = [];
    foreach (glob($dataDir.'/submissions/*.json') ?: [] as $file) {
        $row = kobo_mock_json($file);
        $edit = ($config['edits_applied'] ?? false) === true ? kobo_mock_json($dataDir.'/edits/'.basename($file)) : null;
        if (is_array($edit)) {
            $row = $edit;
        }
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/** @return array<string, mixed>|null */
function kobo_mock_json(string $file): ?array
{
    if (! is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : null;
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function kobo_mock_ok(mixed $body): array
{
    return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
}

/** @return array{status: int, headers: array<string, string>, body: string} */
function kobo_mock_error(int $status, string $detail): array
{
    return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode(['detail' => $detail])];
}
