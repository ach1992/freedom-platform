<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use FreedomPlatform\CI\TelegramPresentationProvenanceChecker;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname(__DIR__, 3).'/scripts/ci/TelegramPresentationProvenanceChecker.php';

final class TelegramPresentationProvenanceCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/freedom-telegram-provenance-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function test_split_restore_through_closure_from_callable_is_rejected(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Closure;
final class TelegramDeliveryOperationExecutor
{
    public function run(string $restrictedSecret): void
    {
        $presentationClass = 'App\\Modules\\Telegram\\Application\\NonRestrictedTelegram'.'Presentation';
        $restoreMethod = 'restore'.'Persisted';
        Closure::fromCallable([$presentationClass, $restoreMethod])($restrictedSecret);
    }
}
PHP);

        $violations = $this->checker()->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('Closure::fromCallable dynamic callable construction', $violations[0]);
    }

    public function test_split_queue_resolution_through_container_array_access_is_rejected(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryQueueService.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Container\Container;
final class TelegramDeliveryQueueService
{
    public function run(): void
    {
        $queueClass = 'App\\Modules\\Telegram\\Application\\TelegramDeliveryQueue'.'Service';
        $queue = Container::getInstance()[$queueClass];
    }
}
PHP);

        $violations = $this->checker()->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('dynamic container ArrayAccess', $violations[0]);
    }

    public function test_dynamic_make_with_resolution_is_rejected(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryQueueService.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Container\Container;
final class TelegramDeliveryQueueService
{
    public function __construct(private Container $container) {}
    public function run(string $class): void
    {
        $this->container->makeWith($class, []);
    }
}
PHP);

        $violations = $this->checker()->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('dynamic container ->makeWith', $violations[0]);
    }

    public function test_injected_psr_container_dynamic_get_is_rejected(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryQueueService.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Psr\Container\ContainerInterface;
final class TelegramDeliveryQueueService
{
    public function __construct(private ContainerInterface $container) {}
    public function run(string $class): void
    {
        $this->container->get($class);
    }
}
PHP);

        $violations = $this->checker()->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('dynamic container ->get', $violations[0]);
    }

    public function test_combined_dynamic_restore_and_queue_resolution_cannot_cross_provenance_boundary(): void
    {
        $path = 'app/Modules/Telegram/Application/ReviewedDynamicEscape.php';
        $this->write($path, <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Closure;
use Illuminate\Container\Container;
final class ReviewedDynamicEscape
{
    public function run(string $restrictedSecret): void
    {
        $presentationClass = 'App\\Modules\\Telegram\\Application\\NonRestrictedTelegram'.'Presentation';
        $restoreMethod = 'restore'.'Persisted';
        $presentation = Closure::fromCallable([$presentationClass, $restoreMethod])($restrictedSecret);
        $queueClass = 'App\\Modules\\Telegram\\Application\\TelegramDeliveryQueue'.'Service';
        $queue = Container::getInstance()[$queueClass];
        $queue->queue(null, 1, null, $presentation, 'request-key', 'correlation-id');
    }
}
PHP);

        $violations = $this->checker([$path])->violations();
        $joined = implode("\n", $violations);

        self::assertCount(3, $violations);
        self::assertStringContainsString('constant-folded internal Telegram presentation method restorePersisted', $joined);
        self::assertStringContainsString('Closure::fromCallable dynamic callable construction', $joined);
        self::assertStringContainsString('dynamic container ArrayAccess', $joined);
    }

    public function test_closure_scope_rebinding_is_rejected(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryOperationExecutor.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Closure;
final class TelegramDeliveryOperationExecutor
{
    public function run(): void
    {
        Closure::bind(static function (): void {}, null, NonRestrictedTelegramPresentation::class);
    }
}
PHP);

        $violations = $this->checker()->violations();

        self::assertCount(1, $violations);
        self::assertStringContainsString('Closure::bind scope mutation', $violations[0]);
    }

    public function test_static_reviewed_class_resolution_remains_allowed(): void
    {
        $this->write('app/Modules/Telegram/Application/TelegramDeliveryQueueService.php', <<<'PHP'
<?php
namespace App\Modules\Telegram\Application;
use Illuminate\Container\Container;
final class TelegramDeliveryQueueService
{
    public function run(): object
    {
        return Container::getInstance()->make(NonRestrictedTelegramPresentationFactory::class);
    }
}
PHP);

        self::assertSame([], $this->checker()->violations());
    }

    /** @param list<string> $reviewedSources */
    private function checker(array $reviewedSources = []): TelegramPresentationProvenanceChecker
    {
        return new TelegramPresentationProvenanceChecker($this->root, [
            'telegram_non_restricted_presentation_sources' => $reviewedSources,
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
