<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\NonRestrictedTelegramPresentation;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationFactory;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationSource;
use App\Modules\Telegram\Application\TelegramMutationRequest;
use App\Modules\Telegram\Domain\TelegramDeliveryAction;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
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

    public function test_forged_presentation_identity_is_rejected_by_request_boundary(): void
    {
        $reflection = new ReflectionClass(NonRestrictedTelegramPresentation::class);
        $forged = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($forged, 'restricted', new stdClass);

        $this->expectException(LogicException::class);
        new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            $forged,
        );
    }

    public function test_trusted_test_fixture_is_accepted_by_request_boundary(): void
    {
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('ordinary');
        $request = new TelegramMutationRequest(
            TelegramDeliveryAction::Send,
            900001,
            null,
            $presentation,
        );

        self::assertSame('ordinary', $request->presentation?->text());
    }

    public function test_runtime_and_architecture_source_policies_remain_identical(): void
    {
        $factoryReflection = new ReflectionClass(NonRestrictedTelegramPresentationFactory::class);
        $constant = $factoryReflection->getReflectionConstant('REVIEWED_SOURCE_FILES');
        self::assertNotFalse($constant);
        $runtimeSources = $constant->getValue();
        self::assertIsArray($runtimeSources);

        $root = dirname(__DIR__, 4);
        $architecture = require $root.'/scripts/ci/architecture-boundaries.php';
        self::assertIsArray($architecture);
        self::assertSame($runtimeSources, $architecture['telegram_non_restricted_presentation_sources'] ?? null);

        $policy = $factoryReflection->getMethod('isReviewedSourceFile');
        $factory = new NonRestrictedTelegramPresentationFactory;
        foreach ($runtimeSources as $runtimeSource) {
            self::assertIsString($runtimeSource);
            self::assertTrue($policy->invoke($factory, $runtimeSource));
        }
        self::assertFalse($policy->invoke($factory, 'app/Modules/Telegram/Application/UnreviewedSource.php'));
    }

    public function test_presentation_object_cannot_be_serialized_for_reconstruction(): void
    {
        $presentation = NonRestrictedTelegramPresentationTestFactory::plainText('ordinary');

        $this->expectException(LogicException::class);
        serialize($presentation);
    }
}
