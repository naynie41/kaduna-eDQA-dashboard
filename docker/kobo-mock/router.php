<?php

declare(strict_types=1);

// PHP built-in server entry for the mock KoboToolbox (compose.dev.yml service kobo-mock, profile
// sources): `php -S 0.0.0.0:8080 docker/kobo-mock/router.php`. Serves handler.php's answers from
// the data directory (KOBO_MOCK_DATA, default storage/app/kobo-mock).

require __DIR__.'/handler.php';

$dataDir = getenv('KOBO_MOCK_DATA') ?: dirname(__DIR__, 2).'/storage/app/kobo-mock';
$headers = [];
foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
    $headers[strtolower((string) $name)] = (string) $value;
}

$response = kobo_mock_handle((string) $_SERVER['REQUEST_METHOD'], (string) $_SERVER['REQUEST_URI'], $headers, $dataDir);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
if (isset($response['file'])) {
    readfile($response['file']);
} else {
    echo $response['body'];
}
