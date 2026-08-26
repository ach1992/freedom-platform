<?php

declare(strict_types=1);

return [
    'allowed_module_dependencies' => [
        'Agents' => ['AccessControl'],
        'Catalog' => ['AccessControl', 'Customers', 'Identity', 'Panels'],
        'Customers' => ['AccessControl', 'Identity'],
        'Identity' => ['AccessControl'],
        'Installer' => ['Operations'],
        'Orders' => ['AccessControl', 'Agents'],
        'Panels' => ['AccessControl'],
        'Payments' => ['AccessControl', 'Orders', 'Wallet'],
        'Promotions' => ['AccessControl', 'Wallet'],
        'Provisioning' => ['AccessControl', 'Catalog', 'Orders', 'Panels', 'Payments', 'Telegram', 'Wallet'],
        'Wallet' => ['AccessControl'],
    ],
    'domain_dependency_exceptions' => [
        'app/Modules/Catalog/Domain/CustomPlanPolicyDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/OfferingOperationPolicy.php|Panels\\Domain',
        'app/Modules/Catalog/Domain/PlanOfferingDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/PlanOfferingDefinition.php|Panels\\Domain',
        'app/Modules/Catalog/Domain/TrialPolicyDefinition.php|Customers\\Domain',
        'app/Modules/Catalog/Domain/TrialPolicyDefinition.php|Identity\\Domain',
    ],
    'cycle_exceptions' => [],
    'protected_table_owners' => [
        '#^ledger_#' => 'Wallet',
        '#^wallet_top_up_settlements$#' => 'Payments',
        '#^wallet_#' => 'Wallet',
        '#^payment_#' => 'Payments',
        '#^(administrators|roles|permissions|role_permissions|administrator_.*|sensitive_action_.*)$#' => 'AccessControl',
        '#^panel_#' => 'Panels',
        '#^worker_heartbeats$#' => 'Operations',
    ],
    'persistence_exceptions' => [],
];
