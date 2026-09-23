<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Telegram;

use App\Modules\Telegram\Application\ConfidentialTelegramPresentation;
use App\Modules\Telegram\Application\ConfidentialTelegramPresentationFactory;
use App\Modules\Telegram\Application\ConfidentialTelegramPresentationSource;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationProvenanceGuard;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationDatabaseCapability;
use App\Modules\Telegram\Application\TelegramDeliveryConfidentialPresentationService;
use App\Modules\Telegram\Application\TelegramPresentationProvenanceGuard;
use App\Shared\Application\Clock;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Connection;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Support\ConfidentialTelegramPresentationTestFactory;

final class ConfidentialTelegramPresentationProvenanceTest extends TestCase
{
    public function test_direct_decrypted_restoration_outside_companion_service_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        ConfidentialTelegramPresentation::restoreDecrypted('confidential');
    }

    public function test_direct_plaintext_reveal_outside_storage_or_provider_transport_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        ConfidentialTelegramPresentationTestFactory::plainText('confidential')->revealConfidentialText();
    }

    public function test_factory_rejects_unreviewed_runtime_source_caller(): void
    {
        $source = new readonly class implements ConfidentialTelegramPresentationSource
        {
            public function confidentialTelegramText(): string
            {
                return 'confidential';
            }
        };

        $this->expectException(LogicException::class);
        (new ConfidentialTelegramPresentationFactory)->fromSource($source);
    }

    public function test_direct_confidential_companion_store_outside_queue_gateway_is_rejected(): void
    {
        $service = new TelegramDeliveryConfidentialPresentationService(
            $this->createStub(Clock::class),
            $this->createStub(StringEncrypter::class),
            new TelegramDeliveryConfidentialPresentationDatabaseCapability,
            new TelegramConfidentialPresentationHasher([str_repeat('k', 32)]),
        );

        $this->expectException(LogicException::class);
        $service->store(
            $this->createStub(Connection::class),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            ConfidentialTelegramPresentationTestFactory::plainText('private-account-view'),
            str_repeat('a', 64),
        );
    }

    public function test_direct_confidential_companion_resolution_outside_executor_gateway_is_rejected(): void
    {
        $service = new TelegramDeliveryConfidentialPresentationService(
            $this->createStub(Clock::class),
            $this->createStub(StringEncrypter::class),
            new TelegramDeliveryConfidentialPresentationDatabaseCapability,
            new TelegramConfidentialPresentationHasher([str_repeat('k', 32)]),
        );

        $this->expectException(LogicException::class);
        $service->resolve(
            $this->createStub(Connection::class),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        );
    }

    public function test_direct_confidential_database_capability_use_outside_companion_service_is_rejected(): void
    {
        $capability = new TelegramDeliveryConfidentialPresentationDatabaseCapability;

        $this->expectException(LogicException::class);
        $capability->runStore(
            $this->createStub(Connection::class),
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            static fn (): bool => true,
        );
    }

    public function test_reviewed_production_source_set_contains_only_bounded_private_journeys(): void
    {
        self::assertSame([
            'app/Modules/Telegram/Application/TelegramAdminCustomerNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramAdministratorDirectMessageService.php',
            'app/Modules/Telegram/Application/TelegramBroadcastNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramAgentBulkPurchaseNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramAgentNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramCardToCardReceiptStatusDelivery.php',
            'app/Modules/Telegram/Application/TelegramGiftCardNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramNowPaymentsNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramSupportAttachmentNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramSupportAttachmentStatusDelivery.php',
            'app/Modules/Telegram/Application/TelegramSupportCategoryNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramSupportNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramSupportRatingNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramSupportRoutingNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramTrialNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramUsdtNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramWalletTransferNavigationHandler.php',
            'app/Modules/Telegram/Application/TelegramZarinpalNavigationHandler.php',
        ], TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_admin_customer_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $source = 'app/Modules/Telegram/Application/TelegramAdminCustomerNavigationHandler.php';

        self::assertContains($source, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($source, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_broadcast_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $broadcastSource = 'app/Modules/Telegram/Application/TelegramBroadcastNavigationHandler.php';

        self::assertContains($broadcastSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($broadcastSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_agent_bulk_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $bulkSource = 'app/Modules/Telegram/Application/TelegramAgentBulkPurchaseNavigationHandler.php';

        self::assertContains($bulkSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($bulkSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_agent_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $agentSource = 'app/Modules/Telegram/Application/TelegramAgentNavigationHandler.php';

        self::assertContains($agentSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($agentSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_receipt_status_source_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $receiptStatusSource = 'app/Modules/Telegram/Application/TelegramCardToCardReceiptStatusDelivery.php';

        self::assertContains($receiptStatusSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($receiptStatusSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_support_attachment_status_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $statusSource = 'app/Modules/Telegram/Application/TelegramSupportAttachmentStatusDelivery.php';

        self::assertContains($statusSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($statusSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_support_category_management_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $categorySource = 'app/Modules/Telegram/Application/TelegramSupportCategoryNavigationHandler.php';

        self::assertContains($categorySource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($categorySource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_support_rating_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $ratingSource = 'app/Modules/Telegram/Application/TelegramSupportRatingNavigationHandler.php';

        self::assertContains($ratingSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($ratingSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_support_routing_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $routingSource = 'app/Modules/Telegram/Application/TelegramSupportRoutingNavigationHandler.php';

        self::assertContains($routingSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($routingSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_trial_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $trialSource = 'app/Modules/Telegram/Application/TelegramTrialNavigationHandler.php';

        self::assertContains($trialSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($trialSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_zarinpal_navigation_has_confidential_but_not_generic_delivery_provenance(): void
    {
        $zarinpalSource = 'app/Modules/Telegram/Application/TelegramZarinpalNavigationHandler.php';

        self::assertContains($zarinpalSource, TelegramConfidentialPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
        self::assertNotContains($zarinpalSource, TelegramPresentationProvenanceGuard::REVIEWED_SOURCE_FILES);
    }

    public function test_confidential_object_is_redacted_and_not_serializable(): void
    {
        $presentation = ConfidentialTelegramPresentationTestFactory::plainText('private-account-view');

        $plaintext = 'private-account-view';
        self::assertSame('[CONFIDENTIAL_TELEGRAM_PRESENTATION]', (string) $presentation);
        self::assertSame(['redacted' => true, 'type' => 'confidential_text'], $presentation->__debugInfo());
        self::assertStringNotContainsString($plaintext, var_export($presentation, true));
        self::assertStringNotContainsString($plaintext, print_r($presentation, true));
        self::assertStringNotContainsString($plaintext, json_encode($presentation, JSON_THROW_ON_ERROR));

        $this->expectException(LogicException::class);
        serialize($presentation);
    }
}
