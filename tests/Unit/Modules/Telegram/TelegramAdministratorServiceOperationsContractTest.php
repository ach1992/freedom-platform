<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Provisioning\Application\ServiceBatchGrantService;
use App\Modules\Provisioning\Application\TelegramAdministratorServiceOperationsService;
use App\Modules\Telegram\Application\Contracts\TelegramAdministratorServiceOperations;
use App\Modules\Telegram\Application\TelegramAdministratorServiceOperationsNavigationHandler;
use App\Modules\Telegram\Application\TelegramNavigationCompositeHandler;
use App\Modules\Telegram\Application\TelegramNavigationHandler;
use App\Modules\Telegram\Infrastructure\TelegramServiceProvider;
use App\Providers\AppServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class TelegramAdministratorServiceOperationsContractTest extends TestCase
{
    public function test_telegram_contract_keeps_provisioning_implementation_behind_a_module_boundary(): void
    {
        $contract = $this->classSource(TelegramAdministratorServiceOperations::class);
        $implementation = $this->classSource(TelegramAdministratorServiceOperationsService::class);
        $provider = $this->classSource(AppServiceProvider::class);

        self::assertStringNotContainsString('Modules\\Provisioning', $contract);
        self::assertStringContainsString(
            'implements TelegramAdministratorServiceOperations',
            $implementation,
        );
        self::assertStringContainsString(
            'TelegramAdministratorServiceOperations::class, TelegramAdministratorServiceOperationsService::class',
            $provider,
        );
    }

    public function test_admin_control_entry_is_permission_derived_and_composite_routed(): void
    {
        $navigation = $this->classSource(TelegramNavigationHandler::class);
        $composite = $this->classSource(TelegramNavigationCompositeHandler::class);
        $serviceProvider = $this->classSource(TelegramServiceProvider::class);

        self::assertStringContainsString(
            '$this->administratorServiceOperations->availableFor($action->userId)',
            $navigation,
        );
        self::assertStringContainsString(
            'TelegramAdministratorServiceOperationsNavigationHandler::ACTION_ENTRY',
            $navigation,
        );
        self::assertStringContainsString(
            '$this->adminServiceOperations->supports($action)',
            $composite,
        );
        self::assertStringContainsString(
            'singleton(TelegramAdministratorServiceOperationsNavigationHandler::class)',
            $serviceProvider,
        );
    }

    public function test_mutations_execute_only_after_explicit_confirmation(): void
    {
        $handler = $this->classSource(TelegramAdministratorServiceOperationsNavigationHandler::class);

        self::assertStringContainsString(
            "private const ACTION_CONFIRM = 'navigation.admin.service.operations.confirm';",
            $handler,
        );
        self::assertStringContainsString(
            '$this->operations->prepareForUser(',
            $handler,
        );
        self::assertStringContainsString(
            '$this->operations->executeForUser(',
            $handler,
        );

        $prepare = strpos($handler, '$this->operations->prepareForUser(');
        $confirmMethod = strpos($handler, 'private function handleConfirm(');
        $execute = strpos($handler, '$this->operations->executeForUser(');
        self::assertIsInt($prepare);
        self::assertIsInt($confirmMethod);
        self::assertIsInt($execute);
        self::assertLessThan($confirmMethod, $prepare);
        self::assertGreaterThan($confirmMethod, $execute);
    }

    public function test_import_and_repair_confirmation_payloads_do_not_store_raw_provider_input(): void
    {
        $implementation = $this->classSource(TelegramAdministratorServiceOperationsService::class);

        self::assertStringContainsString('$this->imports->preview(', $implementation);
        self::assertStringContainsString("'kind' => 'import_attach'", $implementation);
        self::assertStringContainsString("'import' => \$receipt->importPublicId", $implementation);
        self::assertStringContainsString('$this->repairs->previewRemoteIdentity(', $implementation);
        self::assertStringContainsString("'kind' => 'repair_apply'", $implementation);
        self::assertStringContainsString("'case' => \$receipt->casePublicId", $implementation);

        $importPayload = $this->section(
            $implementation,
            'private function prepareImport(',
            'private function prepareServiceGrant(',
        );
        self::assertStringNotContainsString("'subscription_link' =>", $importPayload);
        self::assertStringNotContainsString("'subscriptionLink' =>", $importPayload);
    }

    public function test_facade_reuses_existing_canonical_service_authorities(): void
    {
        $implementation = $this->classSource(TelegramAdministratorServiceOperationsService::class);

        foreach ([
            'ServiceLifecycleCommandService $lifecycle',
            'ServiceImportService $imports',
            'ServiceOwnershipTransferService $transfers',
            'ServiceRepairService $repairs',
            'ServiceBatchGrantService $serviceGrants',
            'ServiceEntitlementGrantBatchService $entitlementGrants',
        ] as $authority) {
            self::assertStringContainsString($authority, $implementation);
        }

        self::assertStringNotContainsString('ProvisioningPanelAdapterResolver', $implementation);
        self::assertStringNotContainsString('->addData(', $implementation);
        self::assertStringNotContainsString('->updateExpiry(', $implementation);
    }

    public function test_svc011_creation_replay_remains_strict_while_later_controls_use_actor_reason_context(): void
    {
        $source = $this->classSource(ServiceBatchGrantService::class);
        $replay = $this->section(
            $source,
            'private function assertBatchReplay(',
            'private function assertBatchContext(',
        );
        $controlContext = $this->section(
            $source,
            'private function assertBatchContext(',
            'private function batchExecutionIdentity(',
        );

        self::assertStringContainsString(
            'hash_equals($batch->request_key_hash, $context->requestHash())',
            $replay,
        );
        self::assertStringContainsString(
            'hash_equals($batch->correlation_id, $context->correlationId)',
            $replay,
        );
        self::assertStringContainsString(
            '$batch->actor_administrator_id !== $context->actorAdministratorId',
            $controlContext,
        );
        self::assertStringContainsString(
            'hash_equals($batch->reason_code, $context->reasonCode)',
            $controlContext,
        );
        self::assertStringNotContainsString('request_key_hash', $controlContext);
        self::assertStringNotContainsString('correlation_id', $controlContext);
    }

    public function test_operator_grammar_uses_stable_public_identifiers_and_bounded_batch_payloads(): void
    {
        $implementation = $this->classSource(TelegramAdministratorServiceOperationsService::class);

        foreach ([
            "'lifecycle'",
            "'transfer'",
            "'repair'",
            "'import'",
            "'grant-services'",
            "'service-batch'",
            "'grant-entitlement'",
            "'grant-entitlement-server'",
            "'entitlement-batch'",
        ] as $command) {
            self::assertStringContainsString($command, $implementation);
        }

        self::assertStringContainsString("->where('public_id', \$publicId)", $implementation);
        self::assertStringContainsString("->where('code', \$code)", $implementation);
        self::assertStringContainsString('count($targets) > 20', $implementation);
    }

    /** @param class-string $class */
    private function classSource(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        if (! is_string($file)) {
            throw new RuntimeException('Reflected class source file is unavailable.');
        }

        $source = file_get_contents($file);
        if (! is_string($source)) {
            throw new RuntimeException('Reflected class source cannot be read.');
        }

        return $source;
    }

    private function section(string $source, string $from, string $to): string
    {
        $start = strpos($source, $from);
        $end = strpos($source, $to, $start === false ? 0 : $start);
        if (! is_int($start) || ! is_int($end) || $end <= $start) {
            throw new RuntimeException('Expected source section is unavailable.');
        }

        return substr($source, $start, $end - $start);
    }
}
