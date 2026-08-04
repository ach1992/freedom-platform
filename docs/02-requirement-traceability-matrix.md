# Requirement Traceability Matrix

Baseline: Master Execution Prompt `1.0.0`  
Matrix status: `active`  
Implementation/test/evidence status: `in-progress` for the explicitly marked foundation rows; all evidence remains unverified until a recorded CI run exists.

## Traceability contract

Every requirement has one row and a stable verification prefix. Future design records, source declarations, test cases, commands/results, and evidence manifests must cite the requirement ID. Code/tests may map many-to-many, but no row may be marked complete until reverse references are discoverable by repository search.

Placeholder notation:

- `D:<area>` is the planned design owner/anchor, to be replaced or supplemented by exact document section/ADR.
- `C:<module>` is the planned code boundary, not an implementation claim.
- `T:<ID>` is the required automated-test prefix; exact test paths/names are added when written.
- `E:<phase>/<ID>` is the future evidence directory/manifest prefix.
- A row marked `in-progress` has exact partial references below. It is not complete and cannot pass a phase gate without retained runtime evidence.

## Foundation implementation cross-references

| Requirements | Exact partial references | Remaining gap |
|---|---|---|
| `PAY-002`, `ARCH-003` | `docs/13-state-machines.md`; `app/Modules/Orders/Domain/OrderState.php`; `app/Modules/Payments/Domain/PaymentIntentState.php`; `tests/Unit/Modules/WorkflowStateTest.php` | Aggregate transition service, actor/reason/history and capture orchestration |
| `PAY-003`, `ARCH-004`, `OPS-003` | ADR `0002`; `app/Shared/Domain/IdempotencyKey.php`; `app/Shared/Application/SafeOutboxPayload.php`; `app/Shared/Infrastructure/DatabaseOutboxPublisher.php`; reliability migration; idempotency/outbox tests | End-to-end duplicate capture/provisioning and concurrent consumer tests |
| `C2C-003`, `GFT-003`, `INT-002` | `docs/15-integration-contracts.md`; typed contracts under `app/Modules/Payments/Application/Contracts/` | Fake/Generic REST implementations and contract/reconciliation tests |
| `PRV-001`, `PRV-002`, `PRV-003` | `docs/15-integration-contracts.md`; `app/Modules/Panels/Application/Contracts/`; `ProvisioningState.php`; workflow tests | Marzban/PasarGuard/Fake adapters and orchestration tests |
| `CNT-001`, `LOC-001`, `LOC-002` | `resources/lang/fa/installer.php`; `resources/lang/en/installer.php`; installer Blade views | Full product localization schema and placeholder tests |
| `OPS-001`, `SEC-001`, `SEC-008` | correlation middleware; logging redaction processor/tap; operations migration; redaction tests | Audit/alert services, encrypted restricted payloads and full security suite |
| `INS-001`, `SEC-007`, `QUA-011` | installer config/token store/controller/middleware/routes/views; installer unit/feature tests | Full CLI/LSPHP, DB/Redis/outbound/integration bootstrap journal and final lock |
| `ARCH-001`, `ARCH-002`, `QUA-002`, `QUA-012`, `QUA-013` | `composer.json`; `phpstan.neon`; `.github/workflows/ci.yml`; `scripts/ci/`; `docs/19-ci-quality-gates.md` | Green locked CI run, stronger module rules and retained evidence manifest |
| `DAT-001`, `DAT-002`, `DAT-003`, `DAT-004` | `Money.php`; `Clock.php`; state enums; foundation migrations; unit/migration tests | Complete domain schema, retention services and production migration evidence |
| `RUN-001`, `RUN-003`, `RUN-004`, `QUA-003`, `QUA-004` | health probe/command/endpoints; Scheduler heartbeat; `deploy/cron`; `deploy/supervisor`; CI Compose; tests | aaPanel rehearsal, worker heartbeat service and staging evidence |

## Canonical §36 requirements

| Requirement | Design / owner | Planned code boundary | Required test prefix | Evidence prefix | Status |
|---|---|---|---|---|---|
| `ONB-001` | D:Onboarding / Identity | C:Identity,Telegram | T:ONB-001 | E:0.3.0/ONB-001 | `not-started` |
| `ONB-002` | D:Onboarding / Promotions | C:Identity,Referrals | T:ONB-002 | E:0.3.0/ONB-002 | `not-started` |
| `ONB-003` | D:Membership / Identity | C:Identity,Telegram | T:ONB-003 | E:0.7.0/ONB-003 | `not-started` |
| `ONB-004` | D:Identity / Identity | C:Identity,Notifications | T:ONB-004 | E:0.3.0/ONB-004 | `not-started` |
| `ONB-005` | D:AccountPolicy / Identity | C:Customers,Telegram | T:ONB-005 | E:0.3.0/ONB-005 | `not-started` |
| `USR-001` | D:CustomerAccount / Identity | C:Customers,Wallet | T:USR-001 | E:0.3.0/USR-001 | `not-started` |
| `USR-002` | D:TierPolicy / Product | C:Customers | T:USR-002 | E:0.3.0/USR-002 | `not-started` |
| `USR-003` | D:CustomerAdmin / Identity | C:Customers,AccessControl | T:USR-003 | E:0.3.0/USR-003 | `not-started` |
| `AGT-001` | D:AgentLifecycle / Product | C:Agents | T:AGT-001 | E:0.3.0/AGT-001 | `not-started` |
| `AGT-002` | D:AgentLifecycle / Identity | C:Agents,AccessControl | T:AGT-002 | E:0.3.0/AGT-002 | `not-started` |
| `AGT-003` | D:AgentPurchase / Orders | C:Agents,Orders,Payments | T:AGT-003 | E:0.6.0/AGT-003 | `not-started` |
| `AGT-004` | D:BulkOrder / Orders | C:Agents,Orders,Provisioning | T:AGT-004 | E:0.6.0/AGT-004 | `not-started` |
| `AGT-005` | D:AgentPricing / Finance | C:Agents,Catalog,Promotions | T:AGT-005 | E:0.5.0/AGT-005 | `not-started` |
| `AGT-006` | D:AgentReporting / Reporting | C:Agents,Reporting | T:AGT-006 | E:0.7.0/AGT-006 | `not-started` |
| `ACL-001` | D:PermissionModel / Access | C:AccessControl | T:ACL-001 | E:0.3.0/ACL-001 | `not-started` |
| `ACL-002` | D:CommandAuthorization / Access | C:AccessControl,Telegram | T:ACL-002 | E:0.3.0/ACL-002 | `not-started` |
| `ACL-003` | D:SensitiveApproval / Access | C:AccessControl | T:ACL-003 | E:0.3.0/ACL-003 | `not-started` |
| `CAT-001` | D:Catalog / Catalog | C:Catalog | T:CAT-001 | E:0.4.0/CAT-001 | `not-started` |
| `CAT-002` | D:OfferingModel / Catalog | C:Catalog,Panels | T:CAT-002 | E:0.4.0/CAT-002 | `not-started` |
| `CAT-003` | D:ServiceMode / Catalog | C:Catalog | T:CAT-003 | E:0.4.0/CAT-003 | `not-started` |
| `CAT-004` | D:ProtocolTarget / Provisioning | C:Catalog,Panels | T:CAT-004 | E:0.4.0/CAT-004 | `not-started` |
| `CAT-005` | D:CustomPlan / Catalog | C:Catalog,Orders | T:CAT-005 | E:0.4.0/CAT-005 | `not-started` |
| `CAT-006` | D:TrialPolicy / Product | C:Catalog,Orders,Provisioning | T:CAT-006 | E:0.4.0/CAT-006 | `not-started` |
| `CAT-007` | D:ClientResources / Content | C:Catalog,Content | T:CAT-007 | E:0.7.0/CAT-007 | `not-started` |
| `CAT-008` | D:CapacityFallback / Provisioning | C:Catalog,Panels,Provisioning | T:CAT-008 | E:0.4.0/CAT-008 | `not-started` |
| `BUY-001` | D:PurchaseJourney / Orders | C:Orders,Payments,Provisioning | T:BUY-001 | E:0.6.0/BUY-001 | `not-started` |
| `BUY-002` | D:QuotePricing / Finance | C:Orders,Catalog,Promotions | T:BUY-002 | E:0.5.0/BUY-002 | `not-started` |
| `BUY-003` | D:ConversationState / Telegram | C:Telegram,Orders | T:BUY-003 | E:0.7.0/BUY-003 | `not-started` |
| `PAY-001` | D:GatewayRules / Payments | C:Payments,Identity,Catalog | T:PAY-001 | E:0.5.0/PAY-001 | `not-started` |
| `PAY-002` | D:PaymentState / Payments | C:Orders,Payments | T:PAY-002 | E:0.5.0/PAY-002 | `in-progress` |
| `PAY-003` | D:PaymentIdempotency / Payments | C:Payments,Orders,Provisioning | T:PAY-003 | E:0.5.0/PAY-003 | `in-progress` |
| `C2C-001` | D:ExactAmount / Payments | C:Payments | T:C2C-001 | E:0.5.0/C2C-001 | `not-started` |
| `C2C-002` | D:CardManualReview / Payments | C:Payments,AccessControl | T:C2C-002 | E:0.5.0/C2C-002 | `not-started` |
| `C2C-003` | D:BankProvider / Payments | C:Payments,Operations | T:C2C-003 | E:0.5.0/C2C-003 | `in-progress` |
| `C2C-004` | D:BankMatching / Payments | C:Payments | T:C2C-004 | E:0.5.0/C2C-004 | `not-started` |
| `C2C-005` | D:BankReconciliation / Payments | C:Payments,Operations | T:C2C-005 | E:0.5.0/C2C-005 | `not-started` |
| `GFT-001` | D:GiftSubmission / Payments | C:Payments | T:GFT-001 | E:0.5.0/GFT-001 | `not-started` |
| `GFT-002` | D:GiftManualReview / Payments | C:Payments,AccessControl | T:GFT-002 | E:0.5.0/GFT-002 | `not-started` |
| `GFT-003` | D:GiftProvider / Payments | C:Payments,Operations | T:GFT-003 | E:0.5.0/GFT-003 | `in-progress` |
| `GFT-004` | D:GiftIntegrity / Payments | C:Payments | T:GFT-004 | E:0.5.0/GFT-004 | `not-started` |
| `USDT-001` | D:USDTQuote / Payments | C:Payments | T:USDT-001 | E:0.5.0/USDT-001 | `not-started` |
| `USDT-002` | D:RateProviders / Payments | C:Payments | T:USDT-002 | E:0.5.0/USDT-002 | `not-started` |
| `USDT-003` | D:BlockchainReview / Payments | C:Payments | T:USDT-003 | E:0.5.0/USDT-003 | `not-started` |
| `IPG-001` | D:Zarinpal / Payments | C:Payments | T:IPG-001 | E:0.5.0/IPG-001 | `not-started` |
| `IPG-002` | D:NOWPayments / Payments | C:Payments | T:IPG-002 | E:0.5.0/IPG-002 | `not-started` |
| `WAL-001` | D:WalletTopUp / Finance | C:Wallet,Payments | T:WAL-001 | E:0.5.0/WAL-001 | `not-started` |
| `WAL-002` | D:Ledger / Finance | C:Wallet | T:WAL-002 | E:0.5.0/WAL-002 | `not-started` |
| `WAL-003` | D:WalletTransfer / Finance | C:Wallet | T:WAL-003 | E:0.5.0/WAL-003 | `not-started` |
| `WAL-004` | D:Refunds / Finance | C:Wallet,Payments,Orders | T:WAL-004 | E:0.5.0/WAL-004 | `not-started` |
| `WAL-005` | D:Corrections / Finance | C:Wallet,AccessControl | T:WAL-005 | E:0.5.0/WAL-005 | `not-started` |
| `PRV-001` | D:PanelContracts / Provisioning | C:Panels | T:PRV-001 | E:0.4.0/PRV-001 | `in-progress` |
| `PRV-002` | D:ProvisioningIntegrity / Provisioning | C:Provisioning,Panels | T:PRV-002 | E:0.6.0/PRV-002 | `in-progress` |
| `PRV-003` | D:UncertainRemote / Provisioning | C:Provisioning,Panels | T:PRV-003 | E:0.6.0/PRV-003 | `in-progress` |
| `SVC-001` | D:ServiceDiscovery / Services | C:Services,Panels | T:SVC-001 | E:0.6.0/SVC-001 | `not-started` |
| `SVC-002` | D:SecureDelivery / Services | C:Services,Telegram | T:SVC-002 | E:0.6.0/SVC-002 | `not-started` |
| `SVC-003` | D:PaidOperations / Services | C:Services,Orders,Payments | T:SVC-003 | E:0.6.0/SVC-003 | `not-started` |
| `SVC-004` | D:ServiceActions / Services | C:Services,Panels | T:SVC-004 | E:0.6.0/SVC-004 | `not-started` |
| `SVC-005` | D:ServiceChanges / Services | C:Services,Catalog,Panels | T:SVC-005 | E:0.6.0/SVC-005 | `not-started` |
| `SVC-006` | D:ServiceRetirement / Services | C:Services,Panels | T:SVC-006 | E:0.6.0/SVC-006 | `not-started` |
| `SVC-007` | D:AutoRenew / Services | C:Services,Wallet | T:SVC-007 | E:0.6.0/SVC-007 | `not-started` |
| `SVC-008` | D:ServiceImport / Services | C:Services,Panels | T:SVC-008 | E:0.6.0/SVC-008 | `not-started` |
| `SVC-009` | D:OwnershipTransfer / Services | C:Services,AccessControl | T:SVC-009 | E:0.6.0/SVC-009 | `not-started` |
| `SVC-010` | D:ServiceRepair / Services | C:Services,Panels | T:SVC-010 | E:0.6.0/SVC-010 | `not-started` |
| `SVC-011` | D:ManualGrants / Services | C:Services,Provisioning | T:SVC-011 | E:0.6.0/SVC-011 | `not-started` |
| `SVC-012` | D:BatchGrants / Services | C:Services,Operations | T:SVC-012 | E:0.6.0/SVC-012 | `not-started` |
| `SVC-013` | D:ServiceNotifications / Services | C:Services,Notifications | T:SVC-013 | E:0.6.0/SVC-013 | `not-started` |
| `SVC-014` | D:ServiceResend / Services | C:Services,Telegram | T:SVC-014 | E:0.6.0/SVC-014 | `not-started` |
| `PRO-001` | D:Discounts / Promotions | C:Promotions,Orders | T:PRO-001 | E:0.5.0/PRO-001 | `not-started` |
| `PRO-002` | D:GiftCodes / Promotions | C:Promotions,Wallet,Services | T:PRO-002 | E:0.5.0/PRO-002 | `not-started` |
| `REF-001` | D:Referrals / Promotions | C:Referrals,Wallet | T:REF-001 | E:0.5.0/REF-001 | `not-started` |
| `SUP-001` | D:TicketCustomer / Support | C:Support,Telegram | T:SUP-001 | E:0.7.0/SUP-001 | `not-started` |
| `SUP-002` | D:TicketOperations / Support | C:Support,AccessControl | T:SUP-002 | E:0.7.0/SUP-002 | `not-started` |
| `COM-001` | D:DirectMessaging / Telegram | C:Broadcast,Telegram | T:COM-001 | E:0.7.0/COM-001 | `not-started` |
| `COM-002` | D:Broadcast / Telegram | C:Broadcast,Telegram | T:COM-002 | E:0.7.0/COM-002 | `not-started` |
| `COM-003` | D:MessageLifecycle / Telegram | C:Broadcast,Telegram | T:COM-003 | E:0.7.0/COM-003 | `not-started` |
| `CNT-001` | D:Localization / Content | C:Content,Telegram | T:CNT-001 | E:0.7.0/CNT-001 | `in-progress` |
| `CNT-002` | D:MenuSafety / Content | C:Content,Telegram | T:CNT-002 | E:0.7.0/CNT-002 | `not-started` |
| `CNT-003` | D:TelegramPresentation / Telegram | C:Content,Telegram | T:CNT-003 | E:0.7.0/CNT-003 | `not-started` |
| `CHN-001` | D:MembershipRules / Identity | C:Identity,Telegram | T:CHN-001 | E:0.7.0/CHN-001 | `not-started` |
| `ADM-001` | D:AdminSearch / Access | C:AccessControl,Reporting | T:ADM-001 | E:0.7.0/ADM-001 | `not-started` |
| `ADM-002` | D:AdminManagement / Access | C:AccessControl,Telegram | T:ADM-002 | E:0.7.0/ADM-002 | `not-started` |
| `REP-001` | D:MetricDefinitions / Reporting | C:Reporting | T:REP-001 | E:0.8.0/REP-001 | `not-started` |
| `REP-002` | D:ReportTime / Reporting | C:Reporting | T:REP-002 | E:0.8.0/REP-002 | `not-started` |
| `REP-003` | D:ReportDelivery / Reporting | C:Reporting,Telegram | T:REP-003 | E:0.8.0/REP-003 | `not-started` |
| `OPS-001` | D:ObservabilityAudit / Operations | C:Operations | T:OPS-001 | E:0.8.0/OPS-001 | `in-progress` |
| `OPS-002` | D:OperationsCenter / Operations | C:Operations,Telegram | T:OPS-002 | E:0.8.0/OPS-002 | `not-started` |
| `OPS-003` | D:AsyncRuntime / Operations | C:Operations,Shared | T:OPS-003 | E:0.8.0/OPS-003 | `in-progress` |
| `BAK-001` | D:Backup / SRE | C:Operations | T:BAK-001 | E:0.8.0/BAK-001 | `not-started` |
| `BAK-002` | D:Restore / SRE | C:Operations | T:BAK-002 | E:0.8.0/BAK-002 | `not-started` |
| `INS-001` | D:Installer / SRE | C:Installer,Operations | T:INS-001 | E:0.2.0/INS-001 | `in-progress` |
| `UPD-001` | D:Updater / SRE | C:Updater,Operations | T:UPD-001 | E:0.8.0/UPD-001 | `not-started` |
| `SEC-001` | D:ThreatModel / Security | C:AllBoundaries | T:SEC-001 | E:0.9.0/SEC-001 | `in-progress` |
| `QUA-001` | D:QualitySystem / QA | C:CI,Docs | T:QUA-001 | E:1.0.0/QUA-001 | `in-progress` |

## Additional non-catalogued requirements

| Requirement | Design / owner | Planned code boundary | Required test prefix | Evidence prefix | Status |
|---|---|---|---|---|---|
| `ARCH-001` | D:RuntimeStandards / Architect | C:Repository,CI | T:ARCH-001 | E:0.2.0/ARCH-001 | `in-progress` |
| `ARCH-002` | D:ModuleBoundaries / Architect | C:Modules,ArchitectureTests | T:ARCH-002 | E:0.2.0/ARCH-002 | `in-progress` |
| `ARCH-003` | D:StatesErrors / Architect | C:Shared,Modules | T:ARCH-003 | E:0.2.0/ARCH-003 | `in-progress` |
| `ARCH-004` | D:OutboxDependencies / Architect | C:Shared,Operations | T:ARCH-004 | E:0.2.0/ARCH-004 | `in-progress` |
| `DAT-001` | D:TimeModel / Data | C:Shared,Presentation | T:DAT-001 | E:0.2.0/DAT-001 | `in-progress` |
| `DAT-002` | D:MoneyModel / Finance | C:Shared,Wallet,Payments | T:DAT-002 | E:0.2.0/DAT-002 | `in-progress` |
| `DAT-003` | D:SchemaIntegrity / Data | C:Database,Modules | T:DAT-003 | E:0.2.0/DAT-003 | `in-progress` |
| `DAT-004` | D:RecordRetention / Data | C:Database,Wallet,Operations | T:DAT-004 | E:0.2.0/DAT-004 | `in-progress` |
| `RUN-001` | D:TargetRuntime / SRE | C:Installer,Deploy | T:RUN-001 | E:0.2.0/RUN-001 | `in-progress` |
| `RUN-002` | D:ReleaseLayout / SRE | C:Deploy,Updater | T:RUN-002 | E:0.2.0/RUN-002 | `not-started` |
| `RUN-003` | D:SchedulerWorkers / SRE | C:Deploy,Operations | T:RUN-003 | E:0.2.0/RUN-003 | `in-progress` |
| `RUN-004` | D:ScheduledTaskPolicy / SRE | C:Operations | T:RUN-004 | E:0.8.0/RUN-004 | `in-progress` |
| `RUN-005` | D:BackupProcessSafety / SRE | C:Operations | T:RUN-005 | E:0.8.0/RUN-005 | `not-started` |
| `RUN-006` | D:PackageManifest / Release | C:ReleaseTooling | T:RUN-006 | E:0.9.0/RUN-006 | `not-started` |
| `SEC-002` | D:AuthorizationThreats / Security | C:AccessControl | T:SEC-002 | E:0.3.0/SEC-002 | `not-started` |
| `SEC-003` | D:DataProtection / Security | C:Shared,Identity,Payments | T:SEC-003 | E:0.3.0/SEC-003 | `not-started` |
| `SEC-004` | D:SSRF / Security | C:SharedHttp,Integrations | T:SEC-004 | E:0.5.0/SEC-004 | `not-started` |
| `SEC-005` | D:TLS / Security | C:SharedHttp,Integrations | T:SEC-005 | E:0.4.0/SEC-005 | `not-started` |
| `SEC-006` | D:FileHandling / Security | C:SharedStorage,Telegram | T:SEC-006 | E:0.7.0/SEC-006 | `not-started` |
| `SEC-007` | D:BrowserSecurity / Security | C:Installer,Updater | T:SEC-007 | E:0.8.0/SEC-007 | `in-progress` |
| `SEC-008` | D:SecretLifecycle / Security | C:Config,Operations | T:SEC-008 | E:0.9.0/SEC-008 | `in-progress` |
| `SEC-009` | D:WebhookBoundary / Security | C:Telegram,Payments | T:SEC-009 | E:0.5.0/SEC-009 | `not-started` |
| `SEC-010` | D:ArtifactIntegrity / Security | C:Operations,Updater | T:SEC-010 | E:0.8.0/SEC-010 | `not-started` |
| `LOC-001` | D:LocalizationSchema / Product | C:Content,Translations | T:LOC-001 | E:0.7.0/LOC-001 | `in-progress` |
| `LOC-002` | D:PersianTerminology / Product | C:Translations,Presentation | T:LOC-002 | E:0.7.0/LOC-002 | `in-progress` |
| `INT-001` | D:ContractEvidence / Integration | C:Docs,ContractTests | T:INT-001 | E:0.9.0/INT-001 | `not-started` |
| `INT-002` | D:ProviderPlatform / Integration | C:Integrations | T:INT-002 | E:0.5.0/INT-002 | `in-progress` |
| `QUA-002` | D:StaticQuality / QA | C:CI,Tooling | T:QUA-002 | E:0.2.0/QUA-002 | `in-progress` |
| `QUA-003` | D:UnitStrategy / QA | C:TestsUnit | T:QUA-003 | E:0.9.0/QUA-003 | `in-progress` |
| `QUA-004` | D:IntegrationStrategy / QA | C:TestsIntegration | T:QUA-004 | E:0.9.0/QUA-004 | `in-progress` |
| `QUA-005` | D:PaymentMatrix / QA | C:TestsPayments | T:QUA-005 | E:0.5.0/QUA-005 | `not-started` |
| `QUA-006` | D:CardGiftMatrix / QA | C:TestsPayments | T:QUA-006 | E:0.5.0/QUA-006 | `not-started` |
| `QUA-007` | D:Regression / QA | C:TestsE2E,Concurrency | T:QUA-007 | E:0.9.0/QUA-007 | `not-started` |
| `QUA-008` | D:SecurityTests / Security | C:TestsSecurity | T:QUA-008 | E:0.9.0/QUA-008 | `not-started` |
| `QUA-009` | D:Performance / QA | C:TestsPerformance | T:QUA-009 | E:0.9.0/QUA-009 | `not-started` |
| `QUA-010` | D:Chaos / QA | C:TestsChaos | T:QUA-010 | E:0.9.0/QUA-010 | `not-started` |
| `QUA-011` | D:LifecycleRehearsal / SRE | C:TestsSystem | T:QUA-011 | E:0.9.0/QUA-011 | `in-progress` |
| `QUA-012` | D:ReleaseGates / QA | C:CI,ReleaseTooling | T:QUA-012 | E:1.0.0/QUA-012 | `in-progress` |
| `QUA-013` | D:EvidenceFormat / QA | C:CI,Evidence | T:QUA-013 | E:1.0.0/QUA-013 | `in-progress` |

## Evidence row schema

When a row advances, its evidence manifest must contain:

```yaml
requirement_id: PAY-003
design_refs: []
code_refs: []
test_refs: []
commands: []
environment: null
result: not-started
artifacts: []
reviewers: []
known_risks: []
```

This schema provides the reverse path from evidence to requirement. Empty arrays or `not-started` may not pass a phase gate.
