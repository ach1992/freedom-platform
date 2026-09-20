<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Support\Application\SupportTicketAttachmentService;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\Contracts\TelegramBroadcastLifecycleTransport;
use App\Modules\Telegram\Application\Contracts\TelegramBroadcastNavigationResolver;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCardToCardPayment;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryEffectGuard;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramSourceMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramSupportCustomerRateLimiter;
use App\Modules\Telegram\Application\NonRestrictedTelegramPresentationFactory;
use App\Modules\Telegram\Application\TelegramAdminCustomerNavigationHandler;
use App\Modules\Telegram\Application\TelegramAdministratorDirectMessageService;
use App\Modules\Telegram\Application\TelegramAdministratorDirectSourceMessageService;
use App\Modules\Telegram\Application\TelegramAgentBulkPurchaseNavigationHandler;
use App\Modules\Telegram\Application\TelegramAgentNavigationHandler;
use App\Modules\Telegram\Application\TelegramBotEntryMembershipGateHandler;
use App\Modules\Telegram\Application\TelegramBroadcastDeliveryEffectGuard;
use App\Modules\Telegram\Application\TelegramBroadcastNavigationHandler;
use App\Modules\Telegram\Application\TelegramChannelMembershipEvaluator;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfidentialDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramConfigurationMutationAudit;
use App\Modules\Telegram\Application\TelegramConfigurationMutationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramDeliveryQueueService;
use App\Modules\Telegram\Application\TelegramGiftCardNavigationHandler;
use App\Modules\Telegram\Application\TelegramInteractionCallbackService;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionPolicy;
use App\Modules\Telegram\Application\TelegramInteractionSessionService;
use App\Modules\Telegram\Application\TelegramInteractionUpdateBindingService;
use App\Modules\Telegram\Application\TelegramInteractiveDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramMembershipConfigurationFence;
use App\Modules\Telegram\Application\TelegramMembershipJoinPresentationResolver;
use App\Modules\Telegram\Application\TelegramNavigationCompositeHandler;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramNavigationHandler;
use App\Modules\Telegram\Application\TelegramPrivateMediaDeliveryResolver;
use App\Modules\Telegram\Application\TelegramPrivateMediaReferenceDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use App\Modules\Telegram\Application\TelegramProtectedReferenceDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramReferralDeepLink;
use App\Modules\Telegram\Application\TelegramRequiredChannelService;
use App\Modules\Telegram\Application\TelegramSourceMessageInteractionGateway;
use App\Modules\Telegram\Application\TelegramSourceMessageReferenceDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramSupportMembershipFreshnessGuard;
use App\Modules\Telegram\Application\TelegramSupportNavigationHandler;
use App\Modules\Telegram\Application\TelegramTrialNavigationHandler;
use App\Modules\Telegram\Application\TelegramUsdtNavigationHandler;
use App\Shared\Application\Clock;
use App\Shared\Application\OutboxEventHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class TelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            TelegramConfidentialPresentationHasher::class,
            static function (Application $application): TelegramConfidentialPresentationHasher {
                $encrypter = $application->make('encrypter');
                if (! $encrypter instanceof Encrypter) {
                    throw new RuntimeException('Telegram confidential presentation keyring is unavailable.');
                }

                return new TelegramConfidentialPresentationHasher($encrypter->getAllKeys());
            },
        );
        $this->app->singleton(
            TelegramRuntimeConfiguration::class,
            function (Application $application): TelegramRuntimeConfiguration {
                $repository = $application->make(Repository::class);
                $configuration = $repository->get('telegram');
                $applicationUrl = $repository->get('app.url');

                return TelegramRuntimeConfiguration::fromArray(
                    is_array($configuration) ? $configuration : [],
                    is_string($applicationUrl) ? $applicationUrl : '',
                );
            },
        );
        $this->app->singleton(
            TelegramReferralDeepLink::class,
            function (Application $application): TelegramReferralDeepLink {
                $repository = $application->make(Repository::class);

                return TelegramReferralDeepLink::fromConfiguration($repository->get('telegram.bot_username'));
            },
        );
        $this->app->singleton(
            TelegramRuntime::class,
            fn (Application $application): TelegramRuntime => $application->make(TelegramRuntimeConfiguration::class),
        );
        $this->app->singleton(
            TelegramInteractionPolicy::class,
            function (Application $application): TelegramInteractionPolicy {
                $repository = $application->make(Repository::class);

                return new TelegramInteractionPolicy(
                    (int) $repository->get('telegram.interaction_session_ttl_seconds', 1800),
                    (int) $repository->get('telegram.interaction_callback_ttl_seconds', 900),
                );
            },
        );
        $this->app->singleton(
            TelegramNavigationEntryGateway::class,
            fn (Application $application): TelegramNavigationEntryGateway => new TelegramNavigationEntryGateway(
                $application->make(TelegramInteractionSessionService::class),
                $application->make(DatabaseManager::class),
                $application->make(TelegramInteractionUpdateBindingService::class),
                fn (): TelegramChannelMembershipEvaluator => $application->make(TelegramChannelMembershipEvaluator::class),
                fn (): NonRestrictedTelegramPresentationFactory => $application->make(NonRestrictedTelegramPresentationFactory::class),
                fn (): TelegramDeliveryQueueService => $application->make(TelegramDeliveryQueueService::class),
                fn (): TelegramInteractionCallbackService => $application->make(TelegramInteractionCallbackService::class),
                fn (): TelegramNavigationHandler => $application->make(TelegramNavigationHandler::class),
                fn (): LocalizationResolver => $application->make(LocalizationResolver::class),
            ),
        );
        $this->app->singleton(
            TelegramSupportCustomerRateLimiter::class,
            RedisTelegramSupportCustomerRateLimiter::class,
        );
        $this->app->singleton(
            TelegramSupportMembershipFreshnessGuard::class,
            fn (Application $application): TelegramSupportMembershipFreshnessGuard => new TelegramSupportMembershipFreshnessGuard(
                fn (): TelegramChannelMembershipEvaluator => $application->make(TelegramChannelMembershipEvaluator::class),
                $application->make(TelegramInteractionSessionService::class),
                $application->make(TelegramNavigationEntryGateway::class),
            ),
        );
        $this->app->singleton(TelegramNavigationHandler::class);
        $this->app->singleton(TelegramAdminCustomerNavigationHandler::class);
        $this->app->singleton(TelegramBroadcastNavigationHandler::class);
        $this->app->singleton(TelegramAgentBulkPurchaseNavigationHandler::class);
        $this->app->singleton(TelegramAgentNavigationHandler::class);
        $this->app->singleton(TelegramTrialNavigationHandler::class);
        $this->app->singleton(TelegramSupportNavigationHandler::class);
        $this->app->singleton(TelegramGiftCardNavigationHandler::class);
        $this->app->singleton(TelegramUsdtNavigationHandler::class);
        $this->app->singleton(
            TelegramBroadcastNavigationResolver::class,
            ContainerTelegramBroadcastNavigationResolver::class,
        );
        $this->app->singleton(TelegramNavigationCompositeHandler::class);
        $this->app->singleton(TelegramBotEntryMembershipGateHandler::class);
        $this->app->tag([
            TelegramNavigationCompositeHandler::class,
            TelegramBotEntryMembershipGateHandler::class,
        ], TelegramInteractionHandler::class);
        $this->app->singleton(
            TelegramInteractionHandlerRegistry::class,
            fn (Application $application): TelegramInteractionHandlerRegistry => new TelegramInteractionHandlerRegistry(
                $application->tagged(TelegramInteractionHandler::class),
            ),
        );
        $this->app->singleton(
            ProtectedTelegramDeliveryRuntime::class,
            fn (Application $application): ProtectedTelegramDeliveryRuntime => $application->make(TelegramRuntime::class),
        );
        $this->app->singleton(
            TelegramDeliveryRuntime::class,
            fn (Application $application): TelegramDeliveryRuntime => $application->make(TelegramRuntimeConfiguration::class),
        );
        $this->app->singleton(
            TelegramBotApi::class,
            fn (Application $application): TelegramBotApi => new HttpTelegramBotApi(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramMembershipLookup::class,
            fn (Application $application): TelegramMembershipLookup => new HttpTelegramMembershipLookup(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramConfigurationMutationAudit::class,
            fn (Application $application): TelegramConfigurationMutationAudit => new TelegramConfigurationMutationAudit(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );
        $this->app->singleton(
            TelegramConfigurationMutationExecutor::class,
            fn (Application $application): TelegramConfigurationMutationExecutor => new TelegramConfigurationMutationExecutor(
                $application->make(DatabaseManager::class),
                $application->make(AdministratorPermissionAuthorizer::class),
                $application->make(TelegramConfigurationMutationAudit::class),
                $application->make(TelegramMembershipConfigurationFence::class),
            ),
        );
        $this->app->singleton(
            TelegramRequiredChannelService::class,
            fn (Application $application): TelegramRequiredChannelService => new TelegramRequiredChannelService(
                $application->make(DatabaseManager::class),
                $application->make(StringEncrypter::class),
                $application->make(TelegramMembershipLookup::class),
                $application->make(TelegramRuntime::class),
                $application->make(TelegramConfigurationMutationExecutor::class),
                $application->make(TelegramConfigurationMutationAudit::class),
                $application->make(Clock::class),
            ),
        );
        $this->app->singleton(
            TelegramChannelMembershipRuleService::class,
            fn (Application $application): TelegramChannelMembershipRuleService => new TelegramChannelMembershipRuleService(
                $application->make(DatabaseManager::class),
                $application->make(TelegramConfigurationMutationExecutor::class),
                $application->make(TelegramConfigurationMutationAudit::class),
                $application->make(Clock::class),
            ),
        );
        $this->app->singleton(
            TelegramChannelMembershipRuleResolver::class,
            fn (Application $application): TelegramChannelMembershipRuleResolver => new TelegramChannelMembershipRuleResolver(
                $application->make(DatabaseManager::class),
                $application->make(Clock::class),
            ),
        );
        $this->app->singleton(
            TelegramChannelMembershipEvaluator::class,
            fn (Application $application): TelegramChannelMembershipEvaluator => new TelegramChannelMembershipEvaluator(
                $application->make(DatabaseManager::class),
                $application->make(TelegramChannelMembershipRuleResolver::class),
                $application->make(TelegramMembershipLookup::class),
                $application->make(ProtectedTelegramDeliveryRuntime::class),
            ),
        );
        $this->app->singleton(
            TelegramPrivateMediaFetcher::class,
            fn (Application $application): TelegramPrivateMediaFetcher => new HttpTelegramPrivateMediaFetcher(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramPrivateMediaMessageSender::class,
            fn (Application $application): TelegramPrivateMediaMessageSender => new HttpTelegramPrivateMediaMessageSender(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramSourceMessageSender::class,
            fn (Application $application): TelegramSourceMessageSender => new HttpTelegramSourceMessageSender(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramBroadcastLifecycleTransport::class,
            fn (Application $application): TelegramBroadcastLifecycleTransport => new HttpTelegramBroadcastLifecycleTransport(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramDeliveryEffectGuard::class,
            TelegramBroadcastDeliveryEffectGuard::class,
        );
        $this->app->bind(
            TelegramAdministratorDirectMessageService::class,
            TelegramAdministratorDirectMessageService::class,
        );
        $this->app->bind(
            TelegramAdministratorDirectSourceMessageService::class,
            TelegramAdministratorDirectSourceMessageService::class,
        );
        $this->app->singleton(TelegramSourceMessageInteractionGateway::class);
        $this->app->singleton(
            ProtectedTelegramMessageSender::class,
            fn (Application $application): ProtectedTelegramMessageSender => new HttpProtectedTelegramMessageSender(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(TelegramMembershipJoinPresentationResolver::class);
        $this->app->singleton(
            TelegramProtectedPresentationResolver::class,
            fn (Application $application): TelegramProtectedPresentationResolver => new TelegramProtectedPresentationResolver(
                $application->make(TelegramCustomerPurchaseCardToCardPayment::class),
                $application->make(LocalizationResolver::class),
                $application->make(TelegramMembershipJoinPresentationResolver::class),
                $application->make(SupportTicketAttachmentService::class),
                $application->make(TelegramSupportMembershipFreshnessGuard::class),
                $application->make(TelegramPrivateMediaDeliveryResolver::class),
            ),
        );
        $this->app->singleton(
            TelegramMutationTransport::class,
            fn (Application $application): TelegramMutationTransport => new HttpTelegramMutationTransport(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            TelegramDeliveryOutboxHandler::class,
            fn (Application $application): TelegramDeliveryOutboxHandler => new TelegramDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->singleton(
            TelegramInteractiveDeliveryOutboxHandler::class,
            fn (Application $application): TelegramInteractiveDeliveryOutboxHandler => new TelegramInteractiveDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->singleton(
            TelegramConfidentialDeliveryOutboxHandler::class,
            fn (Application $application): TelegramConfidentialDeliveryOutboxHandler => new TelegramConfidentialDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->singleton(
            TelegramProtectedReferenceDeliveryOutboxHandler::class,
            fn (Application $application): TelegramProtectedReferenceDeliveryOutboxHandler => new TelegramProtectedReferenceDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->singleton(
            TelegramPrivateMediaReferenceDeliveryOutboxHandler::class,
            fn (Application $application): TelegramPrivateMediaReferenceDeliveryOutboxHandler => new TelegramPrivateMediaReferenceDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->singleton(
            TelegramSourceMessageReferenceDeliveryOutboxHandler::class,
            fn (Application $application): TelegramSourceMessageReferenceDeliveryOutboxHandler => new TelegramSourceMessageReferenceDeliveryOutboxHandler(
                fn (): TelegramDeliveryOperationExecutor => $application->make(TelegramDeliveryOperationExecutor::class),
            ),
        );
        $this->app->tag([
            TelegramDeliveryOutboxHandler::class,
            TelegramInteractiveDeliveryOutboxHandler::class,
            TelegramConfidentialDeliveryOutboxHandler::class,
            TelegramProtectedReferenceDeliveryOutboxHandler::class,
            TelegramPrivateMediaReferenceDeliveryOutboxHandler::class,
            TelegramSourceMessageReferenceDeliveryOutboxHandler::class,
        ], OutboxEventHandler::class);
    }
}
