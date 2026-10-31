<?php

declare(strict_types=1);

use App\Support\Config\EnvNumber;

// config/edqa.php sources: every numeric env value (page size, backfill throttle) falls back to
// its default when blank or not a positive whole number, so a typo in .env can never page by 0
// or stop throttling a live server.

/** config/edqa.php read afresh with these env values (others as they are). */
function edqaConfigWith(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
    try {
        return require config_path('edqa.php');
    } finally {
        foreach ($saved as $key => [$env, $server, $put]) {
            if ($env === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $env;
            }
            if ($server === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $server;
            }
            putenv($put === false ? $key : "{$key}={$put}");
        }
    }
}

it('gives the default page size for a blank KOBO_PULL_PAGE_SIZE, not 0', function (): void {
    expect(edqaConfigWith(['KOBO_PULL_PAGE_SIZE' => ''])['sources']['kobo']['page_size'])->toBe(500);
});

it('falls back to the default for any page size or throttle that is not a positive whole number', function (string $value): void {
    $config = edqaConfigWith([
        'ODK_PULL_PAGE_SIZE' => $value, 'KOBO_PULL_PAGE_SIZE' => $value,
        'ODK_BACKFILL_THROTTLE' => $value, 'KOBO_BACKFILL_THROTTLE' => $value,
    ]);

    expect($config['sources']['odk']['page_size'])->toBe(500)
        ->and($config['sources']['kobo']['page_size'])->toBe(500)
        ->and($config['sources']['odk']['backfill_throttle_seconds'])->toBe(5)
        ->and($config['sources']['kobo']['backfill_throttle_seconds'])->toBe(5);
})->with(['', ' ', '0', '-5', 'abc', '2.5', '1e3', '0x10', '25 ', 'null']);

it('takes a positive whole number as given', function (): void {
    $config = edqaConfigWith(['KOBO_PULL_PAGE_SIZE' => '250', 'KOBO_BACKFILL_THROTTLE' => '12', 'ODK_PULL_PAGE_SIZE' => '1000']);

    expect($config['sources']['kobo']['page_size'])->toBe(250)
        ->and($config['sources']['kobo']['backfill_throttle_seconds'])->toBe(12)
        ->and($config['sources']['odk']['page_size'])->toBe(1000);
});

it('reads a positive whole number, whatever type env() hands it', function (mixed $value, int $expected): void {
    expect(EnvNumber::positiveInt($value, 7))->toBe($expected);
})->with([
    [null, 7], ['', 7], [false, 7], [true, 7], [0, 7], [-3, 7], [3.0, 7], ['3', 3], [42, 42], ['007', 7], ['10', 10],
]);
