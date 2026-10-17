<?php

declare(strict_types=1);

// edqa:demo:quarantine (docs/demo-script.md).

return [
    'local_only' => 'The demo quarantine row is for local demos only.',
    'already_held' => 'The demo row is already held in quarantine: see GET /api/quarantine?group=rule.',
    'no_round' => 'There is no round to send the demo row to: seed the demo data first.',
    'held' => 'Submission :status. Quarantine record :record, held for: :codes. See GET /api/quarantine?group=rule.',
];
