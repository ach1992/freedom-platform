<?php

declare(strict_types=1);

return [
    // Reviewed current cross-module dependencies. New edges require an explicit diff/review.
    'allowed_module_dependencies' => [
        'Agents' => ['AccessControl'],
        'Catalog' => ['AccessControl', 'Customers', 'Identity', 'Panels'],
        'Customers' => ['AccessControl', 'Identity'],
        'Identity' => ['AccessControl'],
        'Installer' => ['Operations'],
        'Orders' => ['Agents'],
        'Panels' => ['AccessControl'],
        'Payments' => ['AccessControl', 'Orders', 'Wallet'],
        'Promotions' => ['AccessControl', 'Wallet'],
        'Provisioning' => ['AccessControl', 'Catalog', 'Orders', 'Panels', 'Payments'],
        'Wallet' => ['AccessControl'],
    ],

    // Legacy Domain-to-Domain imports discovered while establishing the baseline. These are
    // exact path/target exceptions, not permission for new Domain coupling.
    'domain_dependency_exceptions' => [
        'app/Modules/Catalog/Domain/CustomPlanPolicyDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/OfferingOperationPolicy.php|Panels\\Domain',
        'app/Modules/Catalog/Domain/PlanOfferingDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/PlanOfferingDefinition.php|Panels\\Domain',
        'app/Modules/Catalog/Domain/TrialPolicyDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/TrialPolicyDefinition.php|Identity\\Domain',
    ],

    // Strongly connected module components are forbidden by default. No current cycle exists.
    'cycle_exceptions' => [],

    // Critical persistence ownership. The analyzer only evaluates mutating Query Builder calls.
    // audit_logs is a cross-cutting append-only sink whose immutability is enforced separately.
    'protected_table_owners' => [
        '#^ledger_#' => 'Wallet',
        '#^wallet_top_up_settlements$#' => 'Payments',
        '#^wallet_#' => 'Wallet',
        '#^payment_#' => 'Payments',
        '#^(administrators|roles|permissions|role_permissions|administrator_.*|sensitive_action_.*)$#' => 'AccessControl',
        '#^panel_#' => 'Panels',
        '#^worker_heartbeats$#' => 'Operations',
    ],

    // Exact legacy persistence exceptions. New Presentation/root-route mutations remain blocked.
    'persistence_exceptions' => [
        'app/Modules/Telegram/Presentation/Console/RequeueTelegramUpdatesCommand.php|processed_telegram_updates',
    ],
];
