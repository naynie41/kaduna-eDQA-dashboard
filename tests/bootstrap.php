<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// In the containers, Compose passes .env as real environment variables, which PHP exposes in
// $_SERVER. PHPUnit applies phpunit.xml's <env force="true"> values only to getenv() and $_ENV,
// and Laravel reads $_SERVER first, so without this the tests would run with the development
// settings, and RefreshDatabase would wipe the development database. Make PHPUnit's values win.
foreach ($_ENV as $key => $value) {
    if (is_string($value) && getenv($key) === $value) {
        $_SERVER[$key] = $value;
    }
}

if (($_SERVER['DB_DATABASE'] ?? null) !== 'edqa_test') {
    fwrite(STDERR, "Refusing to run tests: DB_DATABASE is not edqa_test.\n");
    exit(1);
}
