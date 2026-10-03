<?php

declare(strict_types=1);

use App\Support\Config\EnvFlag;

return [

    'auth' => [
        // SECURITY.md §2 / D-26: 2FA is required unless EDQA_REQUIRE_2FA is explicitly false.
        // When off, an account that has set up 2FA is still challenged at sign-in.
        'require_two_factor' => EnvFlag::enabledUnlessFalse(env('EDQA_REQUIRE_2FA')),
    ],

];
