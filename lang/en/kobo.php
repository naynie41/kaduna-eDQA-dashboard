<?php

declare(strict_types=1);

// KoboToolbox (ARCHITECTURE.md §5.0, §5.3, SECURITY.md §6). Never the token in any of these.

return [
    'errors' => [
        'not_configured' => 'KoboToolbox is not configured. Set in .env: :problems',
        'auth_credentials' => 'KoboToolbox refused the API token (HTTP :status). Pulls from KoboToolbox stop until the token is fixed.',
        'auth_forbidden' => 'The KoboToolbox account has no access to the form (HTTP :status). It needs view form and view submissions on the asset.',
        'unexpected_status' => 'KoboToolbox answered HTTP :status for :what.',
        'unexpected_body' => 'KoboToolbox answered HTTP :status for :what, but not in the documented shape.',
        'unreachable_connection' => 'KoboToolbox could not be reached after :attempts attempts.',
        'unreachable_server' => 'KoboToolbox kept answering HTTP :status after :attempts attempts.',
        'attachment_missing' => 'KoboToolbox lists no attachment named :name for this submission.',
        'unsafe_scheme' => 'Refused an attachment URL that is not https.',
        'unsafe_host' => 'Refused an attachment URL on :host, which is not the configured KoboToolbox server.',
        'unsafe_redirect' => 'Refused a redirect to :host, which is not the configured KoboToolbox server.',
    ],
];
