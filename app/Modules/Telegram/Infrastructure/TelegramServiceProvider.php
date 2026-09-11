<?php

declare(strict_types=1);

namespace App\Modules\Telegram\Infrastructure;

use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\ProtectedTelegramMessageSender;
use App\Modules\Telegram\Application\Contracts\TelegramBotApi;
use App\Modules\Telegram\Application\Contracts\TelegramDeliveryRuntime;
use App\Modules\Telegram\Application\Contracts\TelegramInteractionHandler;
use App\Modules\Telegram\Application\Contracts\TelegramMembershipLookup;
use App\Modules\Telegram\Application\Contracts\TelegramMutationTransport;
use App\Modules\Telegram\Application\Contracts\TelegramPrivateMediaFetcher;
use App\Modules\Telegram\Application\Contracts\TelegramRuntime;
use App\Modules\Telegram\Application\TelegramAgentNavigationHandler;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleResolver;
use App\Modules\Telegram\Application\TelegramChannelMembershipRuleService;
use App\Modules\Telegram\Application\TelegramConfidentialDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramConfidentialPresentationHasher;
use App\Modules\Telegram\Application\TelegramConfigurationMutationAudit;
use App\Modules\Telegram\Application\TelegramConfigurationMutationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOperationExecutor;
use App\Modules\Telegram\Application\TelegramDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramGiftCardNavigationHandler;
use App\Modules\Telegram\Application\TelegramInteractionHandlerRegistry;
use App\Modules\Telegram\Application\TelegramInteractionPolicy;
use App\Modules\Telegram\Application\TelegramInteractiveDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramNavigationCompositeHandler;
use App\Modules\Telegram\Application\TelegramNavigationEntryGateway;
use App\Modules\Telegram\Application\TelegramNavigationHandler;
use App\Modules\Telegram\Application\TelegramProtectedPresentationResolver;
use App\Modules\Telegram\Application\TelegramProtectedReferenceDeliveryOutboxHandler;
use App\Modules\Telegram\Application\TelegramRequiredChannelService;
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
        $this->app->singleton(TelegramNavigationEntryGateway::class);
        $this->app->singleton(TelegramNavigationHandler::class);
        $this->app->singleton(TelegramAgentNavigationHandler::class);
        $this->app->singleton(TelegramGiftCardNavigationHandler::class);
        $this->app->singleton(TelegramUsdtNavigationHandler::class);
        $this->app->singleton(TelegramNavigationCompositeHandler::class);
        $this->app->tag([TelegramNavigationCompositeHandler::class], TelegramInteractionHandler::class);
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
            TelegramPrivateMediaFetcher::class,
            fn (Application $application): TelegramPrivateMediaFetcher => new HttpTelegramPrivateMediaFetcher(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(
            ProtectedTelegramMessageSender::class,
            fn (Application $application): ProtectedTelegramMessageSender => new HttpProtectedTelegramMessageSender(
                $application->make(Factory::class),
                $application->make(TelegramRuntimeConfiguration::class),
            ),
        );
        $this->app->singleton(TelegramProtectedPresentationResolver::class);
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
        $this->app->tag([
            TelegramDeliveryOutboxHandler::class,
            TelegramInteractiveDeliveryOutboxHandler::class,
            TelegramConfidentialDeliveryOutboxHandler::class,
            TelegramProtectedReferenceDeliveryOutboxHandler::class,
        ], OutboxEventHandler::class);
    }
}
