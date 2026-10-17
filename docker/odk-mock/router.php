<?php

declare(strict_types=1);

// PHP built-in server entry for the mock ODK Central (compose.dev.yml service odk-mock, profile
// odk): `php -S 0.0.0.0:8080 docker/odk-mock/router.php`. Serves handler.php's answers from the
// data directory (ODK_MOCK_DATA, default storage/app/odk-mock).

require __DIR__.'/handler.php';

$dataDir = getenv('ODK_MOCK_DATA') ?: dirname(__DIR__, 2).'/storage/app/odk-mock';
$headers = [];
foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
    $headers[strtolower((string) $name)] = (string) $value;
}

$response = odk_mock_handle((string) $_SERVER['REQUEST_METHOD'], (string) $_SERVER['REQUEST_URI'], $headers, (string) file_get_contents('php://input'), $dataDir);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header("{$name}: {$value}");
}
if (isset($response['file'])) {
    readfile($response['file']);
} else {
    echo $response['body'];
}
