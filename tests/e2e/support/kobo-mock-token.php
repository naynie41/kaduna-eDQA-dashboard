<?php

declare(strict_types=1);

/*
 * `make e2e-sources` only: makes the Kobo mock refuse the portal's token, or puts it back. The mock
 * reads storage/app/kobo-mock/config.json on every request (docker/kobo-mock/handler.php), so
 * changing its token there makes every request answer HTTP 401 until it is restored.
 *
 *   php tests/e2e/support/kobo-mock-token.php refuse    # remember the token, then change it
 *   php tests/e2e/support/kobo-mock-token.php restore   # put the remembered token back
 */
$file = __DIR__.'/../../../storage/app/kobo-mock/config.json';
$mode = $argv[1] ?? '';
$config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
if (! is_array($config) || ! in_array($mode, ['refuse', 'restore'], true)) {
    fwrite(STDERR, "Usage: refuse|restore, with the Kobo mock seeded (make e2e-sources).\n");
    exit(1);
}

if ($mode === 'refuse') {
    $config['e2e_saved_token'] ??= $config['token'] ?? '';
    $config['token'] = 'refused-by-e2e';
} elseif (array_key_exists('e2e_saved_token', $config)) {
    $config['token'] = $config['e2e_saved_token'];
    unset($config['e2e_saved_token']);
}

file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "Kobo mock token: {$mode}d.\n";
