<?php

declare(strict_types=1);

return [
    // Cross-module dependencies must target reviewed public Application/Domain boundaries.
    // Populate this map only from reviewed current edges; new edges require an explicit diff.
    'allowed_module_dependencies' => [],

    // Strongly connected module components are forbidden by default. Any legacy exception must
    // be named as a sorted `ModuleA|ModuleB` component and justified in review before landing.
    'cycle_exceptions' => [],

    // Critical persistence ownership. The analyzer only evaluates mutating Query Builder calls.
    'protected_table_owners' => [
        '#^ledger_#' => 'Wallet',
        '#^wallet_#' => 'Wallet',
        '#^payment_#' => 'Payments',
        '#^(administrators|roles|permissions|role_permissions|administrator_.*|sensitive_action_.*|audit_logs)$#' => 'AccessControl',
        '#^panel_#' => 'Panels',
        '#^worker_heartbeats$#' => 'Operations',
    ],

    // Format: `relative/path.php|table_name`. Keep empty unless an existing reviewed ownership
    // exception cannot be removed safely in the same bounded task.
    'persistence_exceptions' => [],
];
