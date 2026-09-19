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

    public function test_exact_consumer_contract_reference_exception_is_narrow_and_does_not_create_a_module_edge(): void
    {
        $this->write('app/Modules/Promotions/Application/DiscountAuthority.php', <<<'PHP'
<?php
namespace App\Modules\Promotions\Application;
use App\Modules\Orders\Application\Contracts\QuoteDiscountAuthority;
use App\Modules\Orders\Application\QuoteService;
PHP);

        $result = $this->checker([], [], [], [
            'app/Modules/Promotions/Application/DiscountAuthority.php|App\\Modules\\Orders\\Application\\Contracts\\QuoteDiscountAuthority',
        ])->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringNotContainsString('QuoteDiscountAuthority', $violations);
        self::assertStringContainsString('undeclared module dependency Promotions -> Orders', $violations);
        self::assertSame(['Promotions>Orders'], $result['edges']);
    }

    public function test_consumer_contract_reference_exception_rejects_stale_entries(): void
    {
        $result = $this->checker([], [], [], [
            'app/Modules/Promotions/Application/DiscountAuthority.php|App\\Modules\\Orders\\Application\\Contracts\\QuoteDiscountAuthority',
        ])->check();

        self::assertStringContainsString(
            'module_dependency_reference_exceptions contains stale/unused entry',
            implode("\n", $result['violations']),
        );
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

    public function test_application_boundary_private_tables_reject_cross_module_reads_literal_aliases_and_constructed_names(): void
    {
        $this->write('app/Modules/Payments/Application/UnsafeLocalizationRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class UnsafeLocalizationRead
{
    public function direct($db): mixed
    {
        return $db->table('localization_overrides')->where('locale', 'fa')->first();
    }

    public function indirect($db): mixed
    {
        $table = 'localization_override_versions';

        return $db->table($table)->first();
    }

}
PHP);
        $this->write('app/Modules/Payments/Application/ConstructedLocalizationRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class ConstructedLocalizationRead
{
    public function run($db): mixed
    {
        $table = 'localization_' . 'overrides';

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Localization/Application/OwnedLocalizationRead.php', <<<'PHP'
<?php
namespace App\Modules\Localization\Application;
final class OwnedLocalizationRead
{
    public function run($db): mixed
    {
        return $db->table('localization_overrides')->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(2, substr_count($violations, 'cross-module direct persistence access is forbidden'));
        self::assertStringContainsString('ConstructedLocalizationRead.php:9 dynamic table read is forbidden', $violations);
        self::assertStringContainsString('localization_overrides is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('localization_override_versions is private to the Localization Application boundary', $violations);
        self::assertStringNotContainsString('OwnedLocalizationRead.php', $violations);
    }

    public function test_resolved_table_constants_preserve_private_ownership_and_owner_access(): void
    {
        $this->write('app/Modules/Localization/Application/LocalizationTableSurface.php', <<<'PHP'
<?php
namespace App\Modules\Localization\Application;
final class LocalizationTableSurface { public const TABLE = 'localization_overrides'; }
PHP);
        $this->write('app/Modules/Localization/Application/OwnedConstantRead.php', <<<'PHP'
<?php
namespace App\Modules\Localization\Application;
final class OwnedConstantRead
{
    public function run($db): mixed
    {
        return $db->table(LocalizationTableSurface::TABLE)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/CrossModuleConstantRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use App\Modules\Localization\Application\LocalizationTableSurface;
final class CrossModuleConstantRead
{
    public function run($db): mixed
    {
        return $db->table(LocalizationTableSurface::TABLE)->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString(
            'CrossModuleConstantRead.php:8 durable table localization_overrides is private to the Localization Application boundary',
            $violations,
        );
        self::assertStringNotContainsString('OwnedConstantRead.php', $violations);
    }

    public function test_console_command_table_renderer_is_not_classified_as_database_persistence(): void
    {
        $this->write('app/Modules/Payments/Presentation/Console/ReportCommand.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Presentation\Console;
use Illuminate\Console\Command;
final class ReportCommand extends Command
{
    public function handle(): void
    {
        $payload = ['count' => 1];
        $this /* output */ -> table /* render */ (array_keys($payload), [array_values($payload)]);
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringNotContainsString('ReportCommand.php', $violations);
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

    public function test_unbounded_dynamic_table_reads_and_mutations_fail_closed_while_reviewed_bounds_remain_allowed(): void
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
        $this->write('app/Modules/Orders/Application/ConditionallyAssignedRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class ConditionallyAssignedRead
{
    public function run($db, string $table, bool $forceOrders): mixed
    {
        if ($forceOrders) {
            $table = 'orders';
        }

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/NestedGuardRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class NestedGuardRead
{
    public function run($db, string $table, bool $validate): mixed
    {
        if ($validate) {
            if (! in_array($table, ['orders'], true)) {
                throw new \RuntimeException('Unsupported table.');
            }
        }

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/LoopEndedRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class LoopEndedRead
{
    public function run($db, string $table): mixed
    {
        foreach (['orders'] as $table) {
            $db->table($table)->first();
        }

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/BoundedDynamicReads.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class BoundedDynamicReads
{
    public function guarded($db, string $table): mixed
    {
        if (! in_array($table, ['orders'], true)) {
            throw new \RuntimeException('Unsupported table.');
        }

        return $db->table($table)->first();
    }

    public function looped($db): void
    {
        foreach (['orders'] as $table) {
            $db->table($table)->first();
        }
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('DynamicWrite.php:3 dynamic table mutation is forbidden', $violations);
        self::assertStringContainsString('DynamicRead.php:3 dynamic table read is forbidden', $violations);
        self::assertStringContainsString('ConditionallyAssignedRead.php:11 dynamic table read is forbidden', $violations);
        self::assertStringContainsString('NestedGuardRead.php:13 dynamic table read is forbidden', $violations);
        self::assertStringContainsString('LoopEndedRead.php:11 dynamic table read is forbidden', $violations);
        self::assertStringNotContainsString('BoundedDynamicReads.php', $violations);
    }

    public function test_bounded_dynamic_table_proof_rejects_post_bound_mutation_and_textual_throw_guards(): void
    {
        $this->write('app/Modules/Payments/Application/CompoundForeachLocalizationRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class CompoundForeachLocalizationRead
{
    public function run($db): mixed
    {
        foreach (['localization_'] as $table) {
            $table .= 'overrides';

            return $db->table($table)->first();
        }

        return null;
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/CompoundGuardLocalizationRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class CompoundGuardLocalizationRead
{
    public function run($db, string $table): mixed
    {
        if (! in_array($table, ['localization_'], true)) {
            throw new \RuntimeException('Unsupported table.');
        }

        $table .= 'overrides';

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/TextualThrowGuardRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class TextualThrowGuardRead
{
    public function run($db, string $table): mixed
    {
        if (! in_array($table, ['orders'], true)) {
            // throw new \RuntimeException('Not executable.');
            $message = 'throw only';
        }

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/CommentedGuardRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class CommentedGuardRead
{
    public function run($db, string $table): mixed
    {
        // if (! in_array($table, ['orders'], true)) { throw new \RuntimeException('Not executable.'); }

        return $db->table($table)->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertMatchesRegularExpression('/CompoundForeachLocalizationRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/CompoundGuardLocalizationRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/TextualThrowGuardRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/CommentedGuardRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
    }

    public function test_query_builder_invocation_trivia_cannot_bypass_persistence_attribution(): void
    {
        $this->write('app/Modules/Payments/Application/WhitespaceLocalizationTableRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class WhitespaceLocalizationTableRead
{
    public function run($db): mixed
    {
        $table = 'localization_' . 'overrides';

        return $db->table ($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/CommentLocalizationTableRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class CommentLocalizationTableRead
{
    public function run($db): mixed
    {
        $table = 'localization_' . 'overrides';

        return $db-> /* receiver */ table /* boundary */ ($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/StaticDbLocalizationTableRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class StaticDbLocalizationTableRead
{
    public function run(): mixed
    {
        $table = 'localization_' . 'overrides';

        return DB /* receiver */ :: table /* boundary */ ($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/CommentLocalizationFromRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class CommentLocalizationFromRead
{
    public function run($db): mixed
    {
        $table = 'localization_override_' . 'versions';

        return $db->query()->from /* boundary */ ($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/CommentedLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class CommentedLedgerMutation
{
    public function run($db): void
    {
        $db->table /* boundary */ ('ledger_entries')->update /* mutation */ (['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/DeferredCommentedLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class DeferredCommentedLedgerMutation
{
    public function run($db): void
    {
        $query = $db->table /* source */ ('ledger_entries');
        $query->update /* mutation */ (['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/CommentedUnsupportedSource.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class CommentedUnsupportedSource
{
    public function run($db): mixed
    {
        return $db->query()->fromRaw /* boundary */ ('orders')->first();
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/TextualInvocationNoise.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class TextualInvocationNoise
{
    public function run(): string
    {
        // $db->table /* not executable */ ($table)->first();
        return '$db->from ($table)';
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/BoundedTriviaRead.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class BoundedTriviaRead
{
    public function run($db, string $table): mixed
    {
        if (! in_array($table, ['orders'], true)) {
            throw new \RuntimeException('Unsupported table.');
        }

        return $db->table /* reviewed boundary */ ($table)->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertMatchesRegularExpression('/WhitespaceLocalizationTableRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/CommentLocalizationTableRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/StaticDbLocalizationTableRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertMatchesRegularExpression('/CommentLocalizationFromRead\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertStringContainsString('CommentedLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('DeferredCommentedLedgerMutation.php:7 deferred table mutation through $query is forbidden', $violations);
        self::assertStringContainsString('CommentedUnsupportedSource.php:7 query source through fromRaw is forbidden', $violations);
        self::assertStringNotContainsString('TextualInvocationNoise.php', $violations);
        self::assertStringNotContainsString('BoundedTriviaRead.php', $violations);
    }

    public function test_punctuation_bearing_php_trivia_cannot_terminate_mutation_attribution(): void
    {
        $this->write('app/Modules/Payments/Application/BlockCommentLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class BlockCommentLedgerMutation
{
    public function run($db): void
    {
        $db->table /* ; */ (/* ; , ) */ 'ledger_entries')->update(['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/DocCommentLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class DocCommentLedgerMutation
{
    public function run($db): void
    {
        $db /** ; */ -> table('ledger_entries') /** ; */ -> update /* ; */ (['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/LineCommentLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class LineCommentLedgerMutation
{
    public function run($db): void
    {
        $db->table // ;
        (
            'ledger_entries'
        )->update // ;
        (['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/BoundedFromLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class BoundedFromLedgerMutation
{
    public function run($db, string $table): void
    {
        if (! in_array($table, ['ledger_entries'], true)) {
            throw new \RuntimeException('Unsupported table.');
        }

        $db->query()->from /* ; */ (/** ; , ) */ $table)
            /** ; */ -> update /* ; */ (['amount_irr' => 1]);
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('BlockCommentLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('DocCommentLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('LineCommentLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('BoundedFromLedgerMutation.php:11 dynamic table mutation is forbidden', $violations);
    }

    public function test_parenthesized_fluent_builder_grouping_preserves_mutation_attribution(): void
    {
        $this->write('app/Modules/Payments/Application/GroupedLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class GroupedLedgerMutation
{
    public function run($db): void
    {
        ($db->table('ledger_entries'))->update(['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/NestedGroupedLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class NestedGroupedLedgerMutation
{
    public function run($db): void
    {
        ((($db->table('ledger_entries')->where('id', 1))))->delete();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/GroupedFromLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class GroupedFromLedgerMutation
{
    public function run($db): void
    {
        ($db->query()->from('ledger_entries'))->delete();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/GroupedBoundedLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class GroupedBoundedLedgerMutation
{
    public function run($db, string $table): void
    {
        if (! in_array($table, ['ledger_entries'], true)) {
            throw new \RuntimeException('Unsupported table.');
        }

        ($db->table($table) /** ; */) // ;
            ->update(['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/GroupedTriviaLedgerMutation.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class GroupedTriviaLedgerMutation
{
    public function run($db): void
    {
        (/* ( ; */ $db->table('ledger_entries') /** ) ; */)
            /* ; */ ->update(['amount_irr' => 1]);
    }
}
PHP);
        $this->write('app/Modules/Orders/Application/GroupedOwnedMutation.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class GroupedOwnedMutation
{
    public function run($db): void
    {
        ($db->table('orders'))->update(['state' => 'paid']);
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/GroupedReadOnlyLedger.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class GroupedReadOnlyLedger
{
    public function run($db): mixed
    {
        return ($db->table('ledger_entries'))->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('GroupedLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('NestedGroupedLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('GroupedFromLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringContainsString('GroupedBoundedLedgerMutation.php:11 dynamic table mutation is forbidden', $violations);
        self::assertStringContainsString('GroupedTriviaLedgerMutation.php:7 Payments mutation of durable table ledger_entries owned by Wallet is forbidden', $violations);
        self::assertStringNotContainsString('GroupedOwnedMutation.php', $violations);
        self::assertStringNotContainsString('GroupedReadOnlyLedger.php', $violations);
    }

    public function test_application_private_table_isolation_ignores_php_text_noise_but_catches_real_query_and_raw_sql_access(): void
    {
        $this->write('app/Modules/Payments/Application/PrivateTableTextNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class PrivateTableTextNoise
{
    public function run(): string
    {
        // $db->table('localization_overrides')->first();
        $example = "DB::table('localization_override_versions')->first();";
        $sqlExample = 'SELECT * FROM localization_overrides';

        return $example.$sqlExample;
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/RawPersistenceTextNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class RawPersistenceTextNoise
{
    public function run(): string
    {
        // DB::statement('DELETE FROM localization_overrides');
        $statement = "DB::unprepared('DROP TABLE localization_override_versions')";
        $connection = '$connection->selectOne(\'SELECT * FROM localization_overrides\')';

        return $statement.$connection;
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/SpoofedConnectionTypeNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class SpoofedConnectionTypeNoise
{
    public function run($fake): mixed
    {
        // Connection $fake
        $example = 'Connection $fake';

        return $fake->selectOne('SELECT * FROM localization_overrides');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateVariableMutationNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
final class PrivateVariableMutationNoise
{
    public function run($db): mixed
    {
        $table = 'localization_overrides';
        $table .= '_archive';

        return $db->table($table)->first();
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateRawRead
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT * FROM localization_overrides');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateRawWrite.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateRawWrite
{
    public function run(): void
    {
        DB::statement('UPDATE localization_override_versions SET action = action');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateConnectionRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Database\Connection;
final class PrivateConnectionRawRead
{
    public function __construct(private Connection $connection) {}

    public function run(): mixed
    {
        return $this->connection->selectOne('SELECT * FROM localization_overrides');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateManagerRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Database\DatabaseManager;
final class PrivateManagerRawRead
{
    public function __construct(private DatabaseManager $database) {}

    public function run(): mixed
    {
        return $this->database->connection()->selectOne('SELECT * FROM localization_override_versions');
    }
}
PHP);
        $this->write('app/Modules/Localization/Application/OwnedPrivateRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Localization\Application;
use Illuminate\Support\Facades\DB;
final class OwnedPrivateRawRead
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT * FROM localization_overrides');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateRawSqlNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateRawSqlNoise
{
    public function run(): mixed
    {
        return DB::selectOne("SELECT 'FROM localization_overrides' AS example");
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateRawSqlCommentNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateRawSqlCommentNoise
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT 1 /* FROM localization_overrides */ AS ready');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateRawSqlDashCommentNoise.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateRawSqlDashCommentNoise
{
    public function run(): mixed
    {
        return DB::selectOne("SELECT 1 -- FROM localization_overrides\nAS ready");
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateExecutableMysqlCommentRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateExecutableMysqlCommentRead
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT 1 /*!50000 FROM localization_overrides */');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateExecutableMariaDbCommentRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateExecutableMariaDbCommentRead
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT 1 /*M!100100 FROM localization_override_versions */');
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateDashArithmeticRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateDashArithmeticRead
{
    public function run(): mixed
    {
        return DB::selectOne('SELECT 1--1 FROM localization_overrides');
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringNotContainsString('PrivateTableTextNoise.php', $violations);
        self::assertStringNotContainsString('RawPersistenceTextNoise.php', $violations);
        self::assertStringNotContainsString('SpoofedConnectionTypeNoise.php', $violations);
        self::assertMatchesRegularExpression('/PrivateVariableMutationNoise\\.php:\\d+ dynamic table read is forbidden/', $violations);
        self::assertDoesNotMatchRegularExpression('/PrivateVariableMutationNoise\\.php:\\d+ durable table .* private to/', $violations);
        self::assertStringContainsString('PrivateRawRead.php:8 durable table localization_overrides is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('PrivateRawWrite.php:8 durable table localization_override_versions is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('PrivateConnectionRawRead.php:10 durable table localization_overrides is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('PrivateManagerRawRead.php:10 durable table localization_override_versions is private to the Localization Application boundary', $violations);
        self::assertStringNotContainsString('OwnedPrivateRawRead.php', $violations);
        self::assertStringNotContainsString('PrivateRawSqlNoise.php', $violations);
        self::assertStringNotContainsString('PrivateRawSqlCommentNoise.php', $violations);
        self::assertStringNotContainsString('PrivateRawSqlDashCommentNoise.php', $violations);
        self::assertStringContainsString('PrivateExecutableMysqlCommentRead.php:8 durable table localization_overrides is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('PrivateExecutableMariaDbCommentRead.php:8 durable table localization_override_versions is private to the Localization Application boundary', $violations);
        self::assertStringContainsString('PrivateDashArithmeticRead.php:8 durable table localization_overrides is private to the Localization Application boundary', $violations);
    }

    public function test_interpolated_raw_sql_is_dynamic_even_when_runtime_value_could_name_private_table(): void
    {
        $this->write('app/Modules/Payments/Application/PrivateInterpolatedFacadeRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateInterpolatedFacadeRawRead
{
    public function run(): mixed
    {
        $table = 'localization_overrides';

        return DB::selectOne("SELECT * FROM {$table}");
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateInterpolatedSimpleFacadeRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Support\Facades\DB;
final class PrivateInterpolatedSimpleFacadeRawRead
{
    public function run(): mixed
    {
        $table = 'localization_override_versions';

        return DB::selectOne("SELECT * FROM $table");
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateInterpolatedConnectionRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Database\Connection;
final class PrivateInterpolatedConnectionRawRead
{
    public function __construct(private Connection $connection) {}

    public function run(): mixed
    {
        $table = 'localization_overrides';

        return $this->connection->selectOne("SELECT * FROM {$table}");
    }
}
PHP);
        $this->write('app/Modules/Payments/Application/PrivateInterpolatedManagerRawRead.php', <<<'PHP'
<?php
namespace App\Modules\Payments\Application;
use Illuminate\Database\DatabaseManager;
final class PrivateInterpolatedManagerRawRead
{
    public function __construct(private DatabaseManager $database) {}

    public function run(): mixed
    {
        $table = 'localization_override_versions';

        return $this->database->connection()->selectOne("SELECT * FROM $table");
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertSame(4, substr_count($violations, 'raw Connection read API selectOne must receive one literal read-only SELECT statement'));
        self::assertMatchesRegularExpression('/PrivateInterpolatedFacadeRawRead\.php:\d+ raw Connection read API selectOne/', $violations);
        self::assertMatchesRegularExpression('/PrivateInterpolatedSimpleFacadeRawRead\.php:\d+ raw Connection read API selectOne/', $violations);
        self::assertMatchesRegularExpression('/PrivateInterpolatedConnectionRawRead\.php:\d+ raw Connection read API selectOne/', $violations);
        self::assertMatchesRegularExpression('/PrivateInterpolatedManagerRawRead\.php:\d+ raw Connection read API selectOne/', $violations);
    }

    public function test_raw_and_subquery_from_sources_fail_closed_for_reads_as_well_as_mutations(): void
    {
        $this->write('app/Modules/Orders/Application/UnsupportedReadSources.php', <<<'PHP'
<?php
namespace App\Modules\Orders\Application;
final class UnsupportedReadSources
{
    public function raw($db): mixed
    {
        return $db->query()->fromRaw('orders')->first();
    }

    public function sub($db, $query): mixed
    {
        return $db->query()->fromSub($query, 'source')->first();
    }
}
PHP);

        $violations = implode("\n", $this->checker()->check()['violations']);

        self::assertStringContainsString('query source through fromRaw is forbidden', $violations);
        self::assertStringContainsString('query source through fromSub is forbidden', $violations);
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

    public function test_private_media_delivery_provenance_guard_is_an_exact_internal_executor_seam(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramPrivateMediaDeliveryProvenanceGuard.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class TelegramPrivateMediaDeliveryProvenanceGuard
{
    public function executorClass(): string
    {
        return TelegramDeliveryOperationExecutor::class;
    }
}
PHP);

        $result = $this->checker()->check();

        self::assertSame([], $result['violations']);
    }

    public function test_private_media_delivery_trampolines_are_internal_not_generic_reviewed_sources(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramPrivateMediaDeliveryQueue.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final readonly class TelegramPrivateMediaDeliveryQueue
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}
}
PHP);
        $this->write('app/Modules/Telegram/Application/TelegramPrivateMediaReferenceDeliveryOutboxHandler.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class TelegramPrivateMediaReferenceDeliveryOutboxHandler
{
    public function contractVersion(): int
    {
        return TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_PRIVATE_MEDIA_REFERENCE;
    }
}
PHP);

        $result = $this->checker()->check();

        self::assertSame([], $result['violations']);
    }

    public function test_administrator_direct_source_and_media_services_are_not_general_application_authorities(): void
    {
        $path = 'app/Modules/Telegram/Application/UnsafeAdministratorDirectDeliveryBypass.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final readonly class UnsafeAdministratorDirectDeliveryBypass
{
    public function __construct(
        private TelegramAdministratorDirectSourceMessageService $sourceMessages,
        private TelegramAdministratorDirectMediaMessageService $mediaMessages,
    ) {}
}
PHP);

        $result = $this->checker()->check();
        $violations = implode("\n", $result['violations']);

        self::assertStringContainsString(
            'may not reference internal administrator direct-delivery authority TelegramAdministratorDirectSourceMessageService',
            $violations,
        );
        self::assertStringContainsString(
            'may not reference internal administrator direct-delivery authority TelegramAdministratorDirectMediaMessageService',
            $violations,
        );
    }

    public function test_source_message_delivery_trampolines_are_internal_not_generic_reviewed_sources(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramAdministratorDirectSourceMessageService.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final readonly class TelegramAdministratorDirectSourceMessageService
{
    public function __construct(private TelegramDeliveryQueueService $delivery) {}
}
PHP);
        $this->write('app/Modules/Telegram/Application/TelegramSourceMessageDeliveryProvenanceGuard.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class TelegramSourceMessageDeliveryProvenanceGuard
{
    public function executorClass(): string
    {
        return TelegramDeliveryOperationExecutor::class;
    }
}
PHP);
        $this->write('app/Modules/Telegram/Application/TelegramSourceMessageReferenceDeliveryOutboxHandler.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
final class TelegramSourceMessageReferenceDeliveryOutboxHandler
{
    public function contractVersion(): int
    {
        return TelegramDeliveryQueueService::OUTBOX_CONTRACT_VERSION_SOURCE_MESSAGE_REFERENCE;
    }
}
PHP);

        $result = $this->checker()->check();

        self::assertSame([], $result['violations']);
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
        array $moduleDependencyReferenceExceptions = [],
    ): ArchitectureBoundaryChecker {
        return new ArchitectureBoundaryChecker($this->root, [
            'allowed_module_dependencies' => $allowed,
            'module_dependency_reference_exceptions' => $moduleDependencyReferenceExceptions,
            'cycle_exceptions' => [],
            'telegram_non_restricted_presentation_sources' => $telegramPresentationSources,
            'telegram_confidential_presentation_sources' => $telegramConfidentialPresentationSources,
            'durable_table_owners' => [
                'audit_logs' => 'SharedAppendOnly',
                'ledger_entries' => 'Wallet',
                'localization_override_versions' => 'Localization',
                'localization_overrides' => 'Localization',
                'orders' => 'Orders',
                'outbox_messages' => 'Shared',
                'worker_heartbeats' => 'Operations',
            ],
            'application_private_tables' => [
                'localization_override_versions',
                'localization_overrides',
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
