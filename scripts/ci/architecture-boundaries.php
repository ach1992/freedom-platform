<?php

declare(strict_types=1);

return [
    'allowed_module_dependencies' => [
        'Agents' => ['AccessControl', 'Identity'],
        'Catalog' => ['AccessControl', 'Customers', 'Identity', 'Panels', 'Telegram'],
        'Customers' => ['AccessControl', 'Identity'],
        'Identity' => ['AccessControl'],
        'Installer' => ['Operations'],
        'Orders' => ['AccessControl', 'Agents', 'Telegram'],
        'Panels' => ['AccessControl'],
        'Payments' => ['AccessControl', 'Orders', 'Telegram', 'Wallet'],
        'Promotions' => ['AccessControl', 'Wallet'],
        'Provisioning' => ['AccessControl', 'Catalog', 'Orders', 'Panels', 'Payments', 'Telegram', 'Wallet'],
        'Telegram' => ['Agents', 'Customers', 'Identity', 'Promotions', 'Wallet'],
        'Wallet' => ['AccessControl'],
    ],
    // Exact dependency-inversion seams where an owning module implements a
    // consumer-owned Application contract without creating a broad module edge.
    // Each entry is source-path plus exact imported symbol.
    'module_dependency_reference_exceptions' => [
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\Contracts\\QuoteDiscountAuthority',
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\QuoteDiscountAuthorization',
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\QuoteDiscountAuthorizationRequest',
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\QuoteDiscountConsumptionReceipt',
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\QuoteDiscountConsumptionRequest',
        'app/Modules/Promotions/Application/BenefitCodeDiscountQuoteAuthority.php|App\\Modules\\Orders\\Application\\QuoteDiscountSourceQuoteUnavailable',
        'app/Modules/Promotions/Application/PurchasePromotionUsageAuthorityService.php|App\\Modules\\Payments\\Application\\Contracts\\PurchasePromotionUsageAuthority',
        'app/Modules/Promotions/Application/PurchasePromotionUsageAuthorityService.php|App\\Modules\\Payments\\Application\\PurchasePromotionUsageMaintenanceResult',
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

    // Exact production call sites allowed to construct/consume the generic
    // persistable Telegram presentation path. Each addition is a deliberate
    // data-classification review boundary; RESTRICTED owners must keep using
    // their protected/reference delivery authority instead.
    'telegram_confidential_presentation_sources' => [
        'app/Modules/Telegram/Application/TelegramAgentNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramCardToCardReceiptStatusDelivery.php',
        'app/Modules/Telegram/Application/TelegramCardToCardReceiptStatusPresentation.php',
        'app/Modules/Telegram/Application/TelegramGiftCardNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramUsdtNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramZarinpalNavigationHandler.php',
    ],
    'telegram_non_restricted_presentation_sources' => [
        'app/Modules/Telegram/Application/TelegramGiftCardNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramInteractiveDeliveryOutboxHandler.php',
        'app/Modules/Telegram/Application/TelegramProtectedReferenceDeliveryOutboxHandler.php',
        'app/Modules/Telegram/Application/TelegramNavigationHandler.php',
        'app/Modules/Telegram/Application/TelegramUsdtNavigationHandler.php',
    ],

    // Critical MariaDB authority-surface lifecycle contracts. Every registered surface
    // must disposition every lifecycle rule with a concrete strategy and a PHP method/function
    // that CI executes or production readiness code exposes. Evidence is resolved by PHP tokens,
    // so comments/string mentions cannot satisfy the contract.
    'critical_mariadb_authority_surfaces' => [
        'telegram_outbound_delivery_v1' => [
            'migration' => 'database/migrations/2026_08_25_000200_enable_telegram_outbound_delivery_authority.php',
            'rules' => [
                'metadata_evidence' => [
                    'strategy' => 'semantic_metadata_attestation',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryDatabaseAuthoritySurfaceV1.php',
                        'symbol' => 'semanticFingerprint',
                    ]],
                ],
                'install_upgrade_fencing' => [
                    'strategy' => 'serialized_installation_lock',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramOutboundDeliveryMigrationSafetyTest.php',
                        'symbol' => 'test_database_installation_lock_serializes_concurrent_runners_before_any_reset_decision',
                    ]],
                ],
                'interrupted_reentry' => [
                    'strategy' => 'fail_closed_rebuild_and_reentry',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramOutboundDeliveryMigrationSafetyTest.php',
                        'symbol' => 'test_zero_data_interrupted_install_rebuilds_and_activates_complete_surface',
                    ]],
                ],
                'rollback_preflight' => [
                    'strategy' => 'durable_authority_and_runtime_fence_preflight',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramOutboundDeliveryRollbackRuntimeRaceTest.php',
                        'symbol' => 'test_entered_runtime_queue_drains_before_rollback_and_forces_durable_refusal_without_data_loss',
                    ]],
                ],
                'ddl_toctou' => [
                    'strategy' => 'explicit_reference_fence_serialization',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramOutboundDeliveryRollbackToctouTest.php',
                        'symbol' => 'test_operation_reference_fence_excludes_incoming_fk_and_parent_index_ddl_until_drop',
                    ]],
                ],
                'dependency_checks' => [
                    'strategy' => 'privileged_fk_metadata_inventory',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryForeignKeyMetadataAttestor.php',
                        'symbol' => 'matchesExpected',
                    ]],
                ],
                'postflight_readiness' => [
                    'strategy' => 'semantic_surface_readiness',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryDatabaseAuthoritySurfaceV1.php',
                        'symbol' => 'isReady',
                    ]],
                ],
            ],
        ],
        'telegram_interactive_delivery_v1' => [
            'migration' => 'database/migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php',
            'rules' => [
                'metadata_evidence' => [
                    'strategy' => 'semantic_metadata_attestation',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryInteractivePresentationDatabaseSurfaceV1.php',
                        'symbol' => 'isReady',
                    ]],
                ],
                'install_upgrade_fencing' => [
                    'strategy' => 'shared_lifecycle_plus_ddl_session_installation_locks',
                    'evidence' => [
                        [
                            'file' => 'database/migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php',
                            'symbol' => 'withInstallationLock',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                            'symbol' => 'test_interactive_ddl_lock_owner_session_loss_blocks_protected_ddl_after_contender_acquires_ddl_lock',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                            'symbol' => 'test_interactive_lifecycle_session_loss_cannot_overlap_ddl_guarded_by_runtime_session',
                        ],
                    ],
                ],
                'interrupted_reentry' => [
                    'strategy' => 'zero_data_fail_closed_rebuild',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                        'symbol' => 'test_interactive_migration_rebuilds_empty_incomplete_surface_and_restores_exact_readiness',
                    ]],
                ],
                'rollback_preflight' => [
                    'strategy' => 'durable_authority_and_runtime_fence_preflight',
                    'evidence' => [
                        [
                            'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                            'symbol' => 'test_interactive_migration_down_refuses_durable_snapshots_before_destructive_ddl',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramInteractiveDeliveryRollbackRuntimeRaceTest.php',
                            'symbol' => 'test_late_trigger_only_writer_fails_closed_after_persistent_fence_without_deadlock_or_row_loss',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramInteractiveDeliveryRollbackRuntimeRaceTest.php',
                            'symbol' => 'test_staged_trigger_only_writer_remains_fail_closed_until_destructive_drop_commits',
                        ],
                    ],
                ],
                'ddl_toctou' => [
                    'strategy' => 'mariadb_dependency_ddl_fail_closed_reentry',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                        'symbol' => 'test_interactive_migration_down_fails_closed_on_external_fk_preserves_guards_and_retries_cleanly',
                    ]],
                ],
                'dependency_checks' => [
                    'strategy' => 'semantic_no_fk_contract_plus_ddl_restrict',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramInteractiveDeliveryAuthorityTest.php',
                        'symbol' => 'test_interactive_migration_down_fails_closed_on_external_fk_preserves_guards_and_retries_cleanly',
                    ]],
                ],
                'postflight_readiness' => [
                    'strategy' => 'semantic_surface_readiness',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryInteractivePresentationDatabaseSurfaceV1.php',
                        'symbol' => 'isReady',
                    ]],
                ],
            ],
        ],
        'telegram_confidential_delivery_v1' => [
            'migration' => 'database/migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php',
            'rules' => [
                'metadata_evidence' => [
                    'strategy' => 'semantic_metadata_attestation',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1.php',
                        'symbol' => 'isReady',
                    ]],
                ],
                'install_upgrade_fencing' => [
                    'strategy' => 'shared_lifecycle_plus_ddl_session_installation_locks',
                    'evidence' => [
                        [
                            'file' => 'database/migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php',
                            'symbol' => 'withInstallationLock',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                            'symbol' => 'test_ddl_lock_owner_session_loss_blocks_protected_confidential_ddl_after_contender_acquires_lock',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                            'symbol' => 'test_lifecycle_session_loss_cannot_overlap_confidential_ddl_guarded_by_runtime_session',
                        ],
                    ],
                ],
                'interrupted_reentry' => [
                    'strategy' => 'persistent_state0_staging_and_post_drop_reentry',
                    'evidence' => [
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                            'symbol' => 'test_down_reentry_resumes_from_persistent_fence_and_staged_table',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                            'symbol' => 'test_down_reentry_restores_v1_after_interrupted_post_drop_fence',
                        ],
                    ],
                ],
                'rollback_preflight' => [
                    'strategy' => 'durable_authority_and_runtime_fence_preflight',
                    'evidence' => [
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                            'symbol' => 'test_migration_down_refuses_durable_confidential_presentations_before_destructive_ddl',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryRollbackRuntimeRaceTest.php',
                            'symbol' => 'test_late_trigger_only_writer_fails_closed_after_persistent_fence_without_deadlock_or_row_loss',
                        ],
                        [
                            'file' => 'tests/Feature/TelegramConfidentialDeliveryRollbackRuntimeRaceTest.php',
                            'symbol' => 'test_staged_trigger_only_writer_remains_fail_closed_until_destructive_drop_commits',
                        ],
                    ],
                ],
                'ddl_toctou' => [
                    'strategy' => 'mariadb_dependency_ddl_fail_closed_reentry',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                        'symbol' => 'test_migration_down_external_fk_failure_preserves_guards_and_retry',
                    ]],
                ],
                'dependency_checks' => [
                    'strategy' => 'semantic_no_fk_contract_plus_ddl_restrict',
                    'evidence' => [[
                        'file' => 'tests/Feature/TelegramConfidentialDeliveryMigrationSafetyTest.php',
                        'symbol' => 'test_migration_down_external_fk_failure_preserves_guards_and_retry',
                    ]],
                ],
                'postflight_readiness' => [
                    'strategy' => 'semantic_surface_readiness',
                    'evidence' => [[
                        'file' => 'app/Modules/Telegram/Application/TelegramDeliveryConfidentialPresentationDatabaseSurfaceV1.php',
                        'symbol' => 'isReady',
                    ]],
                ],
            ],
        ],        'service_operational_authority_v1' => [
            'migration' => 'database/migrations/2026_08_19_000140_enable_service_operational_authority.php',
            'rules' => [
                'metadata_evidence' => [
                    'strategy' => 'information_schema_finalization_attestation',
                    'evidence' => [[
                        'file' => 'app/Modules/Provisioning/Application/ServiceOperationalAuthorityGuard.php',
                        'symbol' => 'assertFinalized',
                    ]],
                ],
                'install_upgrade_fencing' => [
                    'strategy' => 'bootstrap_check_barriers',
                    'evidence' => [[
                        'file' => 'tests/Feature/ServiceOperationalAuthorityTest.php',
                        'symbol' => 'test_operational_migration_reenters_partial_release_and_survives_historical_authority_reentry',
                    ]],
                ],
                'interrupted_reentry' => [
                    'strategy' => 'consumer_fail_closed_partial_rollback_reentry',
                    'evidence' => [[
                        'file' => 'tests/Feature/ServiceOperationalMigrationSafetyTest.php',
                        'symbol' => 'test_interrupted_down_is_consumer_fail_closed_restores_predecessor_guard_and_retries_cleanly',
                    ]],
                ],
                'rollback_preflight' => [
                    'strategy' => 'durable_row_preflight_before_destructive_ddl',
                    'evidence' => [[
                        'file' => 'tests/Feature/ServiceOperationalMigrationSafetyTest.php',
                        'symbol' => 'test_down_refuses_durable_batch_evidence_before_closing_or_dismantling_authority',
                    ]],
                ],
                'ddl_toctou' => [
                    'strategy' => 'mariadb_metadata_lock_fail_closed_reentry',
                    'evidence' => [[
                        'file' => 'tests/Feature/ServiceOperationalMigrationSafetyTest.php',
                        'symbol' => 'test_external_incoming_fk_dependency_fails_closed_and_down_retries_after_dependency_removal',
                    ]],
                ],
                'dependency_checks' => [
                    'strategy' => 'mariadb_fk_restrict_dependency_probe',
                    'evidence' => [[
                        'file' => 'tests/Feature/ServiceOperationalMigrationSafetyTest.php',
                        'symbol' => 'test_external_incoming_fk_dependency_fails_closed_and_down_retries_after_dependency_removal',
                    ]],
                ],
                'postflight_readiness' => [
                    'strategy' => 'finalized_guard_attestation',
                    'evidence' => [[
                        'file' => 'app/Modules/Provisioning/Application/ServiceOperationalAuthorityGuard.php',
                        'symbol' => 'assertFinalized',
                    ]],
                ],
            ],
        ],
    ],

    // Exact source of truth for every migration-created durable table. Values are either
    // an owning feature module or a reviewed infrastructure classification. Framework
    // tables are Laravel runtime state; Shared is owned by app/Shared; SharedAppendOnly
    // is a cross-cutting sink that may only receive append operations.
    'durable_table_owners' => [
        'administrator_permission_overrides' => 'AccessControl',
        'administrator_role_assignments' => 'AccessControl',
        'administrator_status_histories' => 'AccessControl',
        'administrators' => 'AccessControl',
        'agent_application_histories' => 'Agents',
        'agent_applications' => 'Agents',
        'agent_bulk_order_items' => 'Orders',
        'agent_bulk_orders' => 'Orders',
        'agent_pricing_profile_versions' => 'Agents',
        'agent_pricing_profiles' => 'Agents',
        'agent_pricing_resolutions' => 'Agents',
        'agent_pricing_rule_versions' => 'Agents',
        'agent_pricing_rules' => 'Agents',
        'agent_profiles' => 'Agents',
        'agent_status_histories' => 'Agents',
        'alerts' => 'Operations',
        'audit_logs' => 'SharedAppendOnly',
        'benefit_code_campaign_versions' => 'Promotions',
        'benefit_code_campaigns' => 'Promotions',
        'benefit_code_disables' => 'Promotions',
        'benefit_code_discount_grants' => 'Promotions',
        'benefit_code_discount_quote_consumptions' => 'Promotions',
        'benefit_code_free_service_entitlements' => 'Promotions',
        'benefit_code_issuances' => 'Promotions',
        'benefit_code_redemptions' => 'Promotions',
        'benefit_codes' => 'Promotions',
        'c2c_amount_reservations' => 'Payments',
        'c2c_bank_transaction_events' => 'Payments',
        'c2c_bank_transactions' => 'Payments',
        'c2c_destination_account_events' => 'Payments',
        'c2c_destination_accounts' => 'Payments',
        'c2c_manual_submissions' => 'Payments',
        'c2c_match_reviews' => 'Payments',
        'c2c_provider_cursors' => 'Payments',
        'c2c_reconciliation_findings' => 'Payments',
        'c2c_transaction_matches' => 'Payments',
        'cache' => 'Framework',
        'cache_locks' => 'Framework',
        'custom_plan_calculation_validations' => 'Catalog',
        'custom_plan_calculations' => 'Catalog',
        'custom_plan_policies' => 'Catalog',
        'custom_plan_policy_histories' => 'Catalog',
        'custom_plan_policy_reserved_words' => 'Catalog',
        'custom_plan_policy_separators' => 'Catalog',
        'custom_plan_policy_tags' => 'Catalog',
        'custom_plan_policy_tiers' => 'Catalog',
        'customer_profiles' => 'Customers',
        'customer_status_histories' => 'Customers',
        'customer_tag_assignments' => 'Customers',
        'customer_tags' => 'Customers',
        'customer_tier_histories' => 'Customers',
        'customer_tiers' => 'Customers',
        'failed_jobs' => 'Framework',
        'gift_card_provider_events' => 'Payments',
        'gift_card_reconciliation_findings' => 'Payments',
        'gift_card_redemptions' => 'Payments',
        'gift_card_reviews' => 'Payments',
        'gift_card_submissions' => 'Payments',
        'gift_card_types' => 'Payments',
        'idempotency_keys' => 'Shared',
        'identity_item_histories' => 'Identity',
        'identity_items' => 'Identity',
        'job_batches' => 'Framework',
        'jobs' => 'Framework',
        'ledger_accounts' => 'Wallet',
        'ledger_entries' => 'Wallet',
        'ledger_refundability' => 'Wallet',
        'ledger_transactions' => 'Wallet',
        'nowpayments_payment_authorities' => 'Payments',
        'nowpayments_payment_observations' => 'Payments',
        'nowpayments_reconciliation_findings' => 'Payments',
        'order_items' => 'Orders',
        'order_source_authorizations' => 'Orders',
        'order_state_histories' => 'Orders',
        'orders' => 'Orders',
        'otp_challenges' => 'Identity',
        'outbox_messages' => 'Shared',
        'owner_transfer_requests' => 'AccessControl',
        'panel_capacity_reservation_events' => 'Panels',
        'panel_capacity_reservations' => 'Panels',
        'panel_connection_histories' => 'Panels',
        'panel_connections' => 'Panels',
        'panel_mutation_receipts' => 'Panels',
        'panel_protocol_profile_histories' => 'Panels',
        'panel_protocol_profiles' => 'Panels',
        'panel_service_target_histories' => 'Panels',
        'panel_service_targets' => 'Panels',
        'panel_target_capabilities' => 'Panels',
        'panel_target_capacities' => 'Panels',
        'panel_target_capacity_histories' => 'Panels',
        'panel_target_protocol_profiles' => 'Panels',
        'payment_attempts' => 'Payments',
        'payment_intent_state_histories' => 'Payments',
        'payment_intents' => 'Payments',
        'payment_method_eligibility_decision_methods' => 'Payments',
        'payment_method_eligibility_decisions' => 'Payments',
        'payment_method_health_observations' => 'Payments',
        'payment_method_rule_version_tags' => 'Payments',
        'payment_method_rule_versions' => 'Payments',
        'payment_method_versions' => 'Payments',
        'payment_provider_events' => 'Payments',
        'payment_provider_transactions' => 'Payments',
        'permissions' => 'AccessControl',
        'phone_numbers' => 'Identity',
        'phone_verification_events' => 'Identity',
        'phone_verification_evidences' => 'Identity',
        'plan_offering_auto_renew_policies' => 'Provisioning',
        'plan_offering_auto_renew_policy_histories' => 'Provisioning',
        'plan_offering_histories' => 'Catalog',
        'plan_offering_operations' => 'Catalog',
        'plan_offering_packages' => 'Catalog',
        'plan_offering_protocol_profiles' => 'Catalog',
        'plan_offering_required_capabilities' => 'Catalog',
        'plan_offering_route_policies' => 'Catalog',
        'plan_offering_route_policy_histories' => 'Catalog',
        'plan_offering_route_selections' => 'Catalog',
        'plan_offering_routes' => 'Catalog',
        'plan_offering_tags' => 'Catalog',
        'plan_offering_tiers' => 'Catalog',
        'plan_offerings' => 'Catalog',
        'pricing_rule_resolutions' => 'Promotions',
        'pricing_rule_versions' => 'Promotions',
        'pricing_rules' => 'Promotions',
        'processed_telegram_updates' => 'Telegram',
        'product_categories' => 'Catalog',
        'product_category_histories' => 'Catalog',
        'product_histories' => 'Catalog',
        'product_variant_histories' => 'Catalog',
        'product_variants' => 'Catalog',
        'products' => 'Catalog',
        'promotion_usage_redemptions' => 'Promotions',
        'promotion_usage_releases' => 'Promotions',
        'promotion_usage_reservations' => 'Promotions',
        'provisioning_financial_invalidations' => 'Provisioning',
        'provisioning_operation_histories' => 'Provisioning',
        'provisioning_operations' => 'Provisioning',
        'provisioning_remote_effect_events' => 'Provisioning',
        'purchase_refunds' => 'Payments',
        'purchase_settlements' => 'Payments',
        'purchase_wallet_reservations' => 'Payments',
        'quotes' => 'Orders',
        'referral_attribution_events' => 'Promotions',
        'referral_identities' => 'Promotions',
        'referral_relationships' => 'Promotions',
        'referral_reward_accruals' => 'Promotions',
        'referral_reward_lifecycle_events' => 'Promotions',
        'referral_rewards' => 'Promotions',
        'refund_allocations' => 'Wallet',
        'refunds' => 'Wallet',
        'role_permissions' => 'AccessControl',
        'roles' => 'AccessControl',
        'sales_server_histories' => 'Panels',
        'sales_servers' => 'Panels',
        'scheduled_task_runs' => 'Operations',
        'sensitive_action_approvals' => 'AccessControl',
        'service_auto_renew_attempt_events' => 'Provisioning',
        'service_auto_renew_attempts' => 'Provisioning',
        'service_auto_renew_configuration_histories' => 'Provisioning',
        'service_auto_renew_configurations' => 'Provisioning',
        'service_auto_renew_notification_intents' => 'Provisioning',
        'service_batch_grant_items' => 'Provisioning',
        'service_batch_grants' => 'Provisioning',
        'service_delivery_attempts' => 'Provisioning',
        'service_delivery_effects' => 'Provisioning',
        'service_imports' => 'Provisioning',
        'service_initial_delivery_fences' => 'Provisioning',
        'service_notification_delivery_bindings' => 'Provisioning',
        'service_notification_events' => 'Provisioning',
        'service_notification_scan_cursor' => 'Provisioning',
        'service_notification_states' => 'Provisioning',
        'service_operational_authority_capability' => 'Provisioning',
        'service_ownership_transfers' => 'Provisioning',
        'service_paid_mutation_authorities' => 'Provisioning',
        'service_reconciliation_cases' => 'Provisioning',
        'service_reconciliation_changes' => 'Provisioning',
        'service_subscriptions' => 'Provisioning',
        'service_sync_anomalies' => 'Provisioning',
        'service_sync_anomaly_events' => 'Provisioning',
        'service_sync_leases' => 'Provisioning',
        'service_sync_runs' => 'Provisioning',
        'service_sync_snapshots' => 'Provisioning',
        'service_username_registry' => 'Catalog',
        'sessions' => 'Framework',
        'sms_delivery_attempts' => 'Identity',
        'telegram_accounts' => 'Identity',
        'telegram_delivery_authority_capability' => 'Telegram',
        'telegram_delivery_confidential_presentations' => 'Telegram',
        'telegram_delivery_interactive_presentations' => 'Telegram',
        'telegram_delivery_operations' => 'Telegram',
        'telegram_interaction_authority_capability' => 'Telegram',
        'telegram_interaction_callbacks' => 'Telegram',
        'telegram_interaction_sessions' => 'Telegram',
        'telegram_interaction_transitions' => 'Telegram',
        'telegram_interaction_update_bindings' => 'Telegram',
        'telegram_private_media' => 'Telegram',
        'telegram_start_attributions' => 'Telegram',
        'trial_daily_capacity_counters' => 'Catalog',
        'trial_policies' => 'Catalog',
        'trial_policy_histories' => 'Catalog',
        'trial_policy_tags' => 'Catalog',
        'trial_policy_tiers' => 'Catalog',
        'trial_reservation_events' => 'Catalog',
        'trial_reservations' => 'Catalog',
        'usdt_amount_quotes' => 'Payments',
        'usdt_chain_verification_events' => 'Payments',
        'usdt_destination_wallet_versions' => 'Payments',
        'usdt_manual_rate_versions' => 'Payments',
        'usdt_manual_reviews' => 'Payments',
        'usdt_payment_authorities' => 'Payments',
        'usdt_reconciliation_findings' => 'Payments',
        'usdt_txid_submissions' => 'Payments',
        'usdt_verified_transfers' => 'Payments',
        'users' => 'Identity',
        'wallet_balance_snapshots' => 'Wallet',
        'wallet_correction_previews' => 'Wallet',
        'wallet_corrections' => 'Wallet',
        'wallet_holds' => 'Wallet',
        'wallet_top_up_settlements' => 'Payments',
        'wallet_transfers' => 'Wallet',
        'worker_heartbeats' => 'Operations',
        'zarinpal_payment_observations' => 'Payments',
        'zarinpal_payment_requests' => 'Payments',
        'zarinpal_payment_verifications' => 'Payments',
        'zarinpal_provider_evidence_claims' => 'Payments',
        'zarinpal_reconciliation_findings' => 'Payments',
        'zarinpal_verified_unsettled_evidence' => 'Payments',
    ],

    // Exact migration-local durable rename lifecycle only. Temporary identities are not general
    // durable owners: CI requires literal from/to pairs, the owning migration path, same owner,
    // observed usage, and a reciprocal path whenever one endpoint is only a temporary identity.
    'durable_table_rename_lifecycles' => [
        [
            'file' => 'database/migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php',
            'from' => 'telegram_delivery_confidential_presentations',
            'to' => 'telegram_delivery_confidential_presentations_rollback',
            'owner' => 'Telegram',
        ],
        [
            'file' => 'database/migrations/2026_09_01_000100_enable_telegram_confidential_delivery_presentations.php',
            'from' => 'telegram_delivery_confidential_presentations_rollback',
            'to' => 'telegram_delivery_confidential_presentations',
            'owner' => 'Telegram',
        ],
        [
            'file' => 'database/migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php',
            'from' => 'telegram_delivery_interactive_presentations',
            'to' => 'telegram_delivery_interactive_presentations_rollback',
            'owner' => 'Telegram',
        ],
        [
            'file' => 'database/migrations/2026_08_31_000100_enable_telegram_interactive_delivery_presentations.php',
            'from' => 'telegram_delivery_interactive_presentations_rollback',
            'to' => 'telegram_delivery_interactive_presentations',
            'owner' => 'Telegram',
        ],
    ],

    // Exact migration-only trigger DDL helper retained by the historical migration chain. Runtime
    // raw DDL remains forbidden; #183 owns the reusable trigger/schema lifecycle contract.
    'migration_trigger_ddl_helpers' => [
        'app/Modules/Agents/Infrastructure/AgentPricingMigrationGuards.php',
    ],

    // Exact temporary legacy seams only. Each entry is owned by #188 and CI rejects
    // stale/unused entries so the list shrinks as runtime boundaries are repaired.
    'persistence_exceptions' => [],
];
