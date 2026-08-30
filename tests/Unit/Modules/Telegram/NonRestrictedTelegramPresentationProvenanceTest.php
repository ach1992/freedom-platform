<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationFactory;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationSource;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramPresentationProvenanceGuard;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use Tests\Fixtures\Telegram\UnreviewedDynamicTelegramSource;
use Tests\Support\NonRestrictedTelegramPresentationTestFactory;

final class NonRestrictedTelegramPresentationProvenanceTest extends TestCase
{
    public function test_direct_persisted_restoration_outside_executor_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        NonRestrictedTelegramPresentation::restorePersisted('restricted');
    }

    public function test_factory_rejects_unreviewed_runtime_source_caller(): void
    {
        $source = new readonly class implements NonRestrictedTelegramPresentationSource
        {
            public function nonRestrictedTelegramText(): string
            {
                return 'ordinary';
            }
        };

        $this->expectException(LogicException::class);
        (new NonRestrictedTelegramPresentationFactory)->fromSource($source);
    }

    public function test_multistage_scope_rebinding_and_dynamic_container_resolution_cannot_enter_queue_authority(): void
    {
        $connection = new Connection(null, 'production_like_database');
        $database = new class($connection) extends DatabaseManager
        {
            public function __construct(private readonly Connection $testConnection) {}

            public function connection($name = null): Connection
            {
                return $this->testConnection;
            }
        };

        $queueReflection = new ReflectionClass(TelegramDeliveryQueueService::class);
        /** @var TelegramDeliveryQueueService $queue */
        $queue = $queueReflection->newInstanceWithoutConstructor();
        $queueReflection->getProperty('database')->setValue($queue, $database);

        $container = new readonly class($queue) implements ContainerInterface
        {
            public function __construct(private object $queue) {}

            public function get(string $id): mixed
            {
                return $this->queue;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $source = new UnreviewedDynamicTelegramSource($container);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires an exact reviewed production source gateway');
        $source->queueRestricted('restricted-secret-value');
    }

    public function test_test_fixture_is_a_value_fixture_not_a_security_capability(): void
    {
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('ordinary');
        $reflection = new ReflectionClass(NonRestrictedTelegramPresentation::class);

        self::assertSame('ordinary', $presentation->text());
        self::assertFalse($reflection->hasProperty('sourceCapability'));
        self::assertFalse($reflection->hasProperty('restoreCapability'));
    }

    public function test_runtime_and_architecture_source_lists_remain_identical(): void
    {
        $runtimeSources = TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES;

        $root = dirname(__DIR__, 4);
        $architecture = require $root.'/scripts/ci/architecture-boundaries.php';
        self::assertIsArray($architecture);
        self::assertSame($runtimeSources, $architecture['telegram_non_restricted_presentation_sources'] ?? null);
    }

    public function test_presentation_object_cannot_be_serialized_for_reconstruction(): void
    {
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('ordinary');

        $this->expectException(LogicException::class);
        serialize($presentation);
    }
}
