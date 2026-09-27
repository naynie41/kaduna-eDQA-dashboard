<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the app and run against the edqa_test Postgres database (never SQLite).
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
