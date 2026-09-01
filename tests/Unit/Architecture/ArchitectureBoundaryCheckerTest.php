<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\ArchitectureBoundaryChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/ArchitectureBoundaryChecker.php';

final class ArchitectureBoundaryCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-architecture-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_declared_public_cross_module_dependency_is_allowed(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);

        $result = $this->checker(['Orders' => ['Payments']])->check();

        self::assertSame([], $result['violations']);
        self::assertSame(['Orders>Payments'], $result['edges']);
    }

    public function test_undeclared_cross_module_dependency_is_rejected_with_path(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('app/Modules/Orders/Application/Checkout.php:3 undeclared module dependency Orders -> Payments', implode("\n", $result['violations']));
    }

    public function test_fully_qualified_cross_module_dependency_cannot_bypass_the_checker(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class Checkout
{
    public function payment(): \App\Modules\Payments\Application\PaymentService
    {
        throw new \RuntimeException('not executed');
    }
}
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('undeclared module dependency Orders -> Payments', implode("\n", $result['violations']));
    }

    public function test_cross_module_infrastructure_import_is_rejected(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Infrastructure\GatewayClient;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('cross-module import of Payments\Infrastructure is forbidden', implode("\n", $result['violations']));
    }

    public function test_application_cannot_depend_on_its_own_infrastructure_layer(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Orders\Infrastructure\OrderRepository;
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('Application may not depend on its own Infrastructure layer', implode("\n", $result['violations']));
    }

    public function test_domain_framework_and_other_layer_imports_are_rejected(): void
    {
        $this->write('app/Modules/Orders/Domain/Order.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Domain;
use Illuminate\Support\Collection;
use App\Modules\Orders\Application\OrderService;
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('Domain may not reference framework infrastructure', $violations);
        self::assertStringContainsString('Domain may depend only on its own Domain', $violations);
    }

    public function test_shared_code_cannot_reference_feature_modules_with_or_without_imports(): void
    {
        $this->write('app/Shared/Application/SharedService.php', <<<'PHP'
<?php
namespace App\Shared\Application;
use App\Modules\Orders\Application\OrderService;
final class SharedService
{
    public function second(): \App\Modules\Payments\Application\PaymentService
    {
        throw new \RuntimeException('not executed');
    }
}
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('Shared code must not depend on a feature module', $violations);
        self::assertGreaterThanOrEqual(2, substr_count($violations, 'Shared code must not depend on a feature module'));
    }

    public function test_module_cycles_are_rejected(): void
    {
        $this->write('app/Modules/Orders/Application/Checkout.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use App\Modules\Payments\Application\PaymentService;
PHP);
        $this->write('app/Modules/Payments/Application/PaymentService.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use App\Modules\Orders\Domain\OrderState;
PHP);

        $result = $this->checker([
            'Orders' => ['Payments'],
            'Payments' => ['Orders'],
        ])->check();

        self::assertStringContainsString('Module dependency cycle detected: Orders <-> Payments', implode("\n", $result['violations']));
    }

    public function test_presentation_and_routes_cannot_mutate_tables_directly(): void
    {
        $this->write('app/Modules/Orders/Presentation/Http/OrderController.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Presentation\Http;
final class OrderController { public function __invoke($db): void { $db->table('orders')->update(['state' => 'paid']); } }
PHP);
        $this->write('routes/console.php', <<<'PHP'
<?php
DB::table('worker_heartbeats')->updateOrInsert(['worker_id' => 'scheduler'], []);
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('direct persistence mutation of orders from Presentation is forbidden', $violations);
        self::assertStringContainsString('direct persistence mutation of worker_heartbeats from routes is forbidden', $violations);
    }

    public function test_exact_durable_owner_rejects_cross_module_mutation(): void
    {
        $this->write('app/Modules/Payments/Application/UnsafeLedgerWrite.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class UnsafeLedgerWrite { public function run($db): void { $db->table('ledger_entries')->insert(['amount_irr' => 1]); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('Payments mutation of durable table ledger_entries owned by Wallet is forbidden', implode("\n", $result['violations']));
    }

    public function test_shared_persistence_is_checked_against_the_same_owner_map(): void
    {
        $this->write('app/Shared/Infrastructure/OutboxStore.php', <<<'PHP'
<?php
namespace App\Shared\Infrastructure;
final class OutboxStore { public function run($db): void { $db->table('outbox_messages')->update(['state' => 'ready']); } }
PHP);

        $result = $this->checker()->check();

        self::assertSame([], $result['violations']);
    }

    public function test_append_only_shared_sink_allows_feature_insert_but_rejects_other_mutations(): void
    {
        $this->write('app/Modules/Orders/Application/AuditAppend.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class AuditAppend { public function run($db): void { $db->table('audit_logs')->insert(['action' => 'order.created']); } }
PHP);
        $this->write('app/Modules/Orders/Application/AuditRewrite.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class AuditRewrite { public function run($db): void { $db->table('audit_logs')->update(['action' => 'rewritten']); } }
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertSame(1, substr_count($violations, 'durable table audit_logs is append-only'));
        self::assertStringContainsString('mutation update is forbidden', $violations);
    }

    public function test_dynamic_table_mutation_fails_closed_but_dynamic_read_is_not_misclassified(): void
    {
        $this->write('app/Modules/Orders/Application/DynamicWrite.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class DynamicWrite { public function run($db, string $table): void { $db->table($table)->update(['state' => 'paid']); } }
PHP);
        $this->write('app/Modules/Orders/Application/DynamicRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class DynamicRead { public function run($db, string $table): mixed { return $db->table($table)->first(); } }
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('dynamic table mutation is forbidden because durable-table ownership cannot be attributed', $violations);
        self::assertSame(1, substr_count($violations, 'dynamic table mutation is forbidden'));
    }

    public function test_eloquent_persistence_is_explicitly_prohibited_in_modules_and_shared_code(): void
    {
        $this->write('app/Modules/Orders/Infrastructure/OrderRecord.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Infrastructure;
use Illuminate\Database\Eloquent\Model;
final class OrderRecord extends Model {}
PHP);
        $this->write('app/Shared/Infrastructure/SharedRecord.php', <<<'PHP'
<?php
namespace App\Shared\Infrastructure;
use Illuminate\Database\Eloquent\Model;
final class SharedRecord extends Model {}
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('feature modules may not reference Eloquent persistence primitives', $violations);
        self::assertStringContainsString('Shared code may not reference Eloquent persistence primitives', $violations);
    }

    public function test_opaque_raw_persistence_is_rejected_from_presentation(): void
    {
        $this->write('app/Modules/Orders/Presentation/Http/UnsafeController.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Presentation\Http;
use Illuminate\Support\Facades\DB;
final class UnsafeController { public function run(): void { DB::statement('DELETE FROM orders'); } }
PHP);

        $result = $this->checker()->check();

        self::assertStringContainsString('opaque persistence API statement from Presentation is forbidden', implode("\n", $result['violations']));
    }

    public function test_restricted_owners_cannot_consume_generic_non_restricted_telegram_delivery(): void
    {
        $cases = [
            'Provisioning' => 'subscription-config-url',
            'Identity' => 'otp-value',
            'Panels' => 'provider-credential',
            'Payments' => 'raw-provider-payload',
        ];

        foreach ($cases as $module => $restrictedMarker) {
            $this->write('app/Modules/'.$module.'/Application/UnsafeGenericDelivery.php', <<<PHP
<?php
namespace App\\Modules\\{$module}\\Application;
use App\\Modules\\Telegram\\Application\\NonRestrictedTelegramPresentationFactory;
use App\\Modules\\Telegram\\Application\\NonRestrictedTelegramPresentationSource;
use App\\Modules\\Telegram\\Application\\TelegramDeliveryQueueService;
final class UnsafeGenericDelivery implements NonRestrictedTelegramPresentationSource
{
    public function __construct(private TelegramDeliveryQueueService \$queue, private NonRestrictedTelegramPresentationFactory \$factory) {}
    public function nonRestrictedTelegramText(): string { return '{$restrictedMarker}'; }
}
PHP);
        }

        $result = $this->checker(['Provisioning' => ['Telegram']])->check();
        $violations = implode("\n", $result['violations']);

        self::assertSame(
            4,
            substr_count($violations, 'generic non-restricted Telegram delivery is Telegram-owned and cannot be consumed by another module'),
        );
    }

    public function test_generic_non_restricted_telegram_source_requires_exact_reviewed_path(): void
    {
        $path = 'app/Modules/Telegram/Application/CustomerJourneyPresentation.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class CustomerJourneyPresentation
{
    public function __construct(private NonRestrictedTelegramPresentationFactory $factory) {}
}
PHP);

        $unreviewed = $this->checker()->check();
        self::assertStringContainsString(
            'generic non-restricted Telegram delivery source is not explicitly reviewed',
            implode("\n", $unreviewed['violations']),
        );

        $reviewed = $this->checker([], [$path])->check();
        self::assertSame([], $reviewed['violations']);
    }

    public function test_confidential_telegram_delivery_is_telegram_owned_and_rejects_cross_module_consumers(): void
    {
        $this->write('app/Modules/Customers/Application/UnsafeConfidentialDelivery.php', <<<'PHP'
<?php
namespace App\Modules\Customers\Application;
use App\Modules\Telegram\Application\ConfidentialTelegramPresentationFactory;
final class UnsafeConfidentialDelivery
{
    public function __construct(private ConfidentialTelegramPresentationFactory $factory) {}
}
PHP);

        $result = $this->checker(['Customers' => ['Telegram']])->check();
        self::assertStringContainsString(
            'confidential Telegram delivery is Telegram-owned and cannot be consumed by another module',
            implode("\n", $result['violations']),
        );
    }

    public function test_confidential_telegram_source_requires_exact_reviewed_path(): void
    {
        $path = 'app/Modules/Telegram/Application/PrivateAccountPresentation.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class PrivateAccountPresentation
{
    public function __construct(private ConfidentialTelegramPresentationFactory $factory) {}
}
PHP);

        $unreviewed = $this->checker()->check();
        self::assertStringContainsString(
            'confidential Telegram delivery source is not explicitly reviewed',
            implode("\n", $unreviewed['violations']),
        );

        $reviewed = $this->checker([], [], [$path])->check();
        self::assertSame([], $reviewed['violations']);
    }

    public function test_confidential_telegram_reviewed_source_cannot_consume_internal_decrypt_or_persistence_service(): void
    {
        $path = 'app/Modules/Telegram/Application/ReviewedButUnsafeConfidentialGateway.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class ReviewedButUnsafeConfidentialGateway
{
    public function __construct(
        private TelegramDeliveryConfidentialPresentationService $presentations,
        private TelegramDeliveryConfidentialPresentationDatabaseCapability $capability,
        private TelegramConfidentialPresentationHasher $hasher,
    ) {}
    public function leak(ConfidentialTelegramPresentation $presentation): string
    {
        return $presentation->revealConfidentialText();
    }
}
PHP);

        $result = $this->checker([], [], [$path])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramDeliveryConfidentialPresentationService',
            $violations,
        );
        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramDeliveryConfidentialPresentationDatabaseCapability',
            $violations,
        );
        self::assertStringContainsString(
            'may not call or dynamically reference internal confidential Telegram presentation method revealConfidentialText',
            $violations,
        );
        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramConfidentialPresentationHasher',
            $violations,
        );
    }

    public function test_confidential_telegram_reviewed_source_cannot_bypass_queue_through_transport_or_executor(): void
    {
        $path = 'app/Modules/Telegram/Application/ReviewedButUnsafeConfidentialTransport.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
final class ReviewedButUnsafeConfidentialTransport
{
    public function __construct(
        private ConfidentialTelegramPresentationFactory $factory,
        private TelegramMutationTransport $transport,
        private TelegramDeliveryOperationExecutor $executor,
    ) {}

    public function bypass(TelegramMutationRequest $request): void
    {
        $this->transport->mutate($request);
    }
}
PHP);

        $result = $this->checker([], [], [$path])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramMutationTransport',
            $violations,
        );
        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramMutationRequest',
            $violations,
        );
        self::assertStringContainsString(
            'may not reference internal confidential Telegram authority symbol TelegramDeliveryOperationExecutor',
            $violations,
        );
    }

    public function test_confidential_telegram_source_allowlist_rejects_non_telegram_and_stale_entries(): void
    {
        $result = $this->checker([], [], [
            'app/Modules/Customers/Application/Unsafe.php',
            'app/Modules/Telegram/Application/StalePrivate.php',
        ])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString(
            'telegram_confidential_presentation_sources contains an invalid or non-Telegram source path',
            $violations,
        );
        self::assertStringContainsString(
            'telegram_confidential_presentation_sources contains stale/unused entry app/Modules/Telegram/Application/StalePrivate.php',
            $violations,
        );
    }

    public function test_generic_boundary_defers_split_string_semantics_to_dedicated_provenance_checker(): void
    {
        $this->write('app/Modules/Provisioning/Application/DynamicTelegramEscape.php', <<<'PHP'
<?php
namespace App\Modules\Provisioning\Application;
final class DynamicTelegramEscape
{
    public function run(string $restrictedSecret): void
    {
        $presentationClass = 'App\\Modules\\Telegram\\Application\\NonRestricted'.'TelegramPresentation';
        $queueClass = 'App\\Modules\\Telegram\\Application\\TelegramDeliveryQueue'.'Service';
        $method = 'restore'.'Persisted';
        $callable = [$presentationClass, $method];
        $presentation = $callable($restrictedSecret);
        $container = app();
        $queue = $container->make($queueClass);
    }
}
PHP);

        $result = $this->checker(['Provisioning' => ['Telegram']])->check();
        self::assertSame([], $result['violations']);
    }

    public function test_generic_telegram_boundary_rejects_internal_presentation_methods_from_allowlisted_source(): void
    {
        $path = 'app/Modules/Telegram/Application/ReviewedButUnsafePresentation.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class ReviewedButUnsafePresentation implements NonRestrictedTelegramPresentationSource
{
    public function nonRestrictedTelegramText(): string { return 'ordinary'; }
    public function unsafe(string $restricted): NonRestrictedTelegramPresentation
    {
        NonRestrictedTelegramPresentation::fromReviewedSource($this);
        return NonRestrictedTelegramPresentation::restorePersisted($restricted);
    }
}
PHP);

        $result = $this->checker([], [$path])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString('internal Telegram presentation method fromReviewedSource', $violations);
        self::assertStringContainsString('internal Telegram presentation method restorePersisted', $violations);
    }

    public function test_unrelated_reflection_alias_and_dynamic_container_resolution_are_not_telegram_provenance_violations(): void
    {
        $this->write('app/Modules/Orders/Application/ReflectionTelegramEscape.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class ReflectionTelegramEscape
{
    public function run(string $class): void
    {
        new \ReflectionClass($class);
        class_alias($class, 'TemporaryTelegramAlias');
        resolve($class);
        app()->make($class);
    }
}
PHP);

        $result = $this->checker()->check();
        self::assertSame([], $result['violations']);
    }

    public function test_unrelated_injected_laravel_container_resolution_is_not_a_telegram_provenance_violation(): void
    {
        $this->write('app/Modules/Orders/Application/InjectedContainerTelegramEscape.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
use Illuminate\Contracts\Container\Container;
use Illuminate\Container\Container as ConcreteContainer;
use Illuminate\Support\Facades\App;
final class InjectedContainerTelegramEscape
{
    public function __construct(private Container $container) {}
    public function run(string $queueClass): void
    {
        $this->container->make($queueClass);
        ConcreteContainer::getInstance()->make($queueClass);
        App::make($queueClass);
    }
}
PHP);

        $result = $this->checker()->check();
        self::assertSame([], $result['violations']);
    }

    public function test_unrelated_dynamic_new_and_callback_indirection_are_not_telegram_provenance_violations(): void
    {
        $this->write('app/Modules/Orders/Application/CallableTelegramEscape.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class CallableTelegramEscape
{
    public function run(string $class, string $method): void
    {
        $instance = new $class();
        call_user_func([$class, $method], $instance);
    }
}
PHP);

        $result = $this->checker()->check();
        self::assertSame([], $result['violations']);
    }

    public function test_generic_non_restricted_telegram_source_allowlist_rejects_non_telegram_and_stale_entries(): void
    {
        $result = $this->checker([], [
            'app/Modules/Provisioning/Application/Unsafe.php',
            'app/Modules/Telegram/Application/Stale.php',
        ])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString(
            'telegram_non_restricted_presentation_sources contains an invalid or non-Telegram source path',
            $violations,
        );
        self::assertStringContainsString(
            'telegram_non_restricted_presentation_sources contains stale/unused entry app/Modules/Telegram/Application/Stale.php',
            $violations,
        );
    }

    /**
     * @param  array<string,list<string>>  $allowed
     * @param  list<string>  $telegramPresentationSources
     * @param  list<string>  $telegramConfidentialPresentationSources
     */
    private function checker(
        array $allowed = [],
        array $telegramPresentationSources = [],
        array $telegramConfidentialPresentationSources = [],
    ): ArchitectureBoundaryChecker {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => $allowed,
            'cycle_exceptions' => [],
            'telegram_non_restricted_presentation_sources' => $telegramPresentationSources,
            'telegram_confidential_presentation_sources' => $telegramConfidentialPresentationSources,
            'durable_table_owners' => [
                'audit_logs' => 'SharedAppendOnly',
                'ledger_entries' => 'Wallet',
                'orders' => 'Orders',
                'outbox_messages' => 'Shared',
                'worker_heartbeats' => 'Operations',
            ],
            'persistence_exceptions' => [],
        ]);
    }

    private function write(string $relativePath, string $content): void
    {
        $path = $this->root.'/'.$relativePath;
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($path, $content."\n");
    }

    private function removeTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
