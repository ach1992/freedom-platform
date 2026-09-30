<?php

declare(strict_types=1);

return [
    'entry_button' => 'Operations',
    'menu' => "Operations Center\n\nCurrent operational state is read from existing domain authorities. Unknown means there is not enough authoritative evidence to claim health.",
    'snapshot_button' => 'Current snapshot',
    'alerts_button' => 'Operational alerts',
    'dispatch_outbox_button' => 'Dispatch due Outbox work',
    'refresh_button' => 'Refresh',
    'snapshot_title' => 'Operations Center snapshot',
    'alerts_title' => 'Unresolved operational alerts',
    'alerts_empty' => 'No unresolved operational alert is currently recorded.',
    'alert_title' => 'Operational alert',
    'ack_button' => 'Acknowledge alert',
    'resolve_button' => 'Resolve alert',
    'acknowledged' => 'The alert was acknowledged with current authorization.',
    'resolved' => 'The alert was resolved with current authorization.',
    'dispatch_completed' => 'A bounded canonical Outbox dispatch pass completed. review_required and failed-job records were not replayed.',
    'operation_unavailable' => 'The operation is unavailable or failed closed. No generic retry/replay was attempted.',
];
