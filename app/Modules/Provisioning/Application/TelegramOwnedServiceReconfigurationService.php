<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Catalog\Application\RouteOperationalVerifier;
use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Orders\Application\QuoteAgentPricingContext;
use App\Modules\Orders\Application\QuotePricingInput;
use App\Modules\Orders\Application\QuoteService;
use App\Modules\Orders\Application\ServiceReconfigurationQuoteContext;
use App\Modules\Orders\Domain\QuoteOverrideSource;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseCatalog;
use App\Modules\Telegram\Application\Contracts\TelegramCustomerPurchaseQuote;
use App\Modules\Telegram\Application\Contracts\TelegramOwnedServiceReconfigurationManager;
use App\Modules\Telegram\Application\TelegramCustomerPurchaseQuotePreview;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationExecution;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationOptions;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationPreview;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationProtocolOption;
use App\Modules\Telegram\Application\TelegramServiceReconfigurationRouteOption;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/**
 * Customer-facing selection and commercial handoff for SVC-005.
 *
 * This class does not create a second reconfiguration authority. It only resolves safe Telegram
 * selection tokens and delegates accepted preview/Quote persistence to the canonical Service and
 * Orders authorities.
 */
final readonly class TelegramOwnedServiceReconfigurationService implements TelegramOwnedServiceReconfigurationManager
{
    private const QUOTE_TTL_MINUTES = 10;

    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerPurchaseCatalog $catalog,
        private RouteOperationalVerifier $routeVerifier,
        private TargetCapacityAllocator $capacity,
        private ServiceReconfigurationPreviewService $previews,
        private ServiceReconfigurationNoChargeQueueService $noChargeQueue,
        private QuoteService $quotes,
        private TelegramCustomerPurchaseQuote $purchaseQuotes,
    ) {}

    /** @requirement SVC-005 CAT-004 BUY-002 DAT-003 SEC-002 QUA-001 */
    public function optionsForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $servicePublicId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
    ): TelegramServiceReconfigurationOptions {
        $context = $this->context($actorUserId, $subjectUserId, $servicePublicId, $offeringSelectionToken);
        $customerRoutes = $this->eligibleRoutes($context, true);
        $selectedRoute = $this->selectedRoute($context, $customerRoutes, $routeSelectionToken, false);
        $protocolRoutes = $selectedRoute === null
            ? $this->eligibleRoutes($context, false)
            : [$selectedRoute];
        $protocols = $this->protocolOptions($context, $selectedRoute, $protocolRoutes);

        if ($context['server_selection_mode'] === 'customer_selects' && $customerRoutes === []) {
            throw new AuthorizationException('Service reconfiguration has no available customer-selectable server.');
        }
        if ($context['protocol_selection_mode'] === 'customer_selects' && $protocols === []) {
            throw new AuthorizationException('Service reconfiguration has no available customer-selectable protocol.');
        }

        return new TelegramServiceReconfigurationOptions(
            $offeringSelectionToken,
            $context['server_selection_mode'],
            $context['protocol_selection_mode'],
            array_map(
                fn (object $route): TelegramServiceReconfigurationRouteOption => new TelegramServiceReconfigurationRouteOption(
                    $this->routeToken($subjectUserId, $servicePublicId, $context['target_offering_code'], $route->id),
                    $route->server_code,
                    $route->server_name_fa,
                    $route->server_name_en,
                ),
                $customerRoutes,
            ),
            $protocols,
        );
    }

    /** @requirement SVC-005 CAT-004 BUY-002 DAT-003 SEC-002 QUA-001 */
    public function previewForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $servicePublicId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
        ?string $protocolSelectionToken,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceReconfigurationPreview {
        $context = $this->context($actorUserId, $subjectUserId, $servicePublicId, $offeringSelectionToken);
        $customerRoutes = $this->eligibleRoutes($context, true);
        $selectedRoute = $this->selectedRoute($context, $customerRoutes, $routeSelectionToken, true);
        $protocolRoutes = $selectedRoute === null
            ? $this->eligibleRoutes($context, false)
            : [$selectedRoute];
        $protocolOptions = $this->protocolOptions($context, $selectedRoute, $protocolRoutes);
        $selectedProtocol = $this->selectedProtocol($context, $protocolOptions, $protocolSelectionToken);

        $receipt = $this->previews->previewForSelf(
            $subjectUserId,
            $servicePublicId,
            $context['target_offering_code'],
            $selectedRoute?->server_code,
            $selectedProtocol?->profileCode,
            $requestKey,
            $correlationId,
        );

        return new TelegramServiceReconfigurationPreview(
            $receipt->previewPublicId,
            $receipt->servicePublicId,
            $offeringSelectionToken,
            $receipt->sourceOfferingCode,
            $receipt->targetOfferingCode,
            $receipt->targetSalesServerCode,
            $receipt->targetProtocolProfileCode,
            $receipt->changesPlan,
            $receipt->changesTarget,
            $receipt->changesProtocol,
            $receipt->priceDifferenceIrr,
            $receipt->operationFeeIrr,
            $receipt->totalPriceIrr,
            $receipt->expiresAt,
            $receipt->replayed,
        );
    }

    /** @requirement SVC-005 PRV-002 PRV-003 DAT-003 SEC-002 QUA-004 */
    public function executeNoChargeForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $previewPublicId,
        string $requestKey,
        string $correlationId,
    ): TelegramServiceReconfigurationExecution {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Zero-cost Service reconfiguration is restricted to self-service.');
        }

        $receipt = $this->noChargeQueue->queueForSelf(
            $actorUserId,
            $previewPublicId,
            $requestKey,
            $correlationId,
        );

        return new TelegramServiceReconfigurationExecution(
            $receipt->servicePublicId,
            $receipt->operationPublicId,
            $receipt->state->value,
            $receipt->replayed,
        );
    }

    /** @requirement SVC-005 BUY-002 PAY-001 DAT-003 SEC-002 QUA-001 */
    public function quoteForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        string $previewPublicId,
        DateTimeImmutable $acceptedAt,
        string $quoteKey,
        string $correlationId,
    ): TelegramCustomerPurchaseQuotePreview {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Service reconfiguration Quote is restricted to self-service.');
        }

        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        /** @var object{preview_id:int|string,service_user_id:int|string,target_plan_offering_id:int|string,target_offering_code:string,total_price_irr:int|string,expires_at:string,account_type:string}|null $row */
        $row = $this->database->connection()->table('service_reconfiguration_previews as preview')
            ->join('service_subscriptions as service', 'service.id', '=', 'preview.service_subscription_id')
            ->join('users as user_row', 'user_row.id', '=', 'service.user_id')
            ->join('plan_offerings as target_offering', 'target_offering.id', '=', 'preview.target_plan_offering_id')
            ->where('preview.public_id', $previewPublicId)
            ->first([
                'preview.id as preview_id', 'service.user_id as service_user_id', 'preview.target_plan_offering_id',
                'target_offering.code as target_offering_code', 'preview.total_price_irr', 'preview.expires_at',
                'user_row.account_type',
            ]);
        if ($row === null
            || (int) $row->service_user_id !== $subjectUserId
            || ! hash_equals($row->target_offering_code, $offering->offeringCode)) {
            throw new AuthorizationException('Service reconfiguration Quote preview is unavailable.');
        }
        $price = $this->nonNegativeInt($row->total_price_irr, 'Service reconfiguration total price');
        if ($price === 0) {
            throw new DomainException('Zero-cost Service reconfiguration requires the non-financial audited authority.');
        }

        $utc = new DateTimeZone('UTC');
        $accepted = $acceptedAt->setTimezone($utc);
        $previewExpiresAt = $this->databaseDateTime($row->expires_at, 'Service reconfiguration preview expiry');
        $quoteExpiresAt = $accepted->add(new DateInterval('PT'.self::QUOTE_TTL_MINUTES.'M'));
        if ($previewExpiresAt < $quoteExpiresAt) {
            $quoteExpiresAt = $previewExpiresAt;
        }
        if ($quoteExpiresAt <= $accepted) {
            throw new AuthorizationException('Service reconfiguration preview expired before Quote creation.');
        }

        $agentPricing = $row->account_type === 'agent'
            ? new QuoteAgentPricingContext($subjectUserId, AgentPricingAction::Reconfigure)
            : null;
        if (! in_array($row->account_type, ['customer', 'agent'], true)) {
            throw new AuthorizationException('Service reconfiguration Quote account is unavailable.');
        }

        $quote = $this->quotes->create(
            $quoteKey,
            $subjectUserId,
            (int) $row->target_plan_offering_id,
            new QuotePricingInput(
                QuoteOverrideSource::None,
                null,
                null,
                null,
                0,
                $quoteExpiresAt,
            ),
            $correlationId,
            $agentPricing,
            null,
            new ServiceReconfigurationQuoteContext($previewPublicId),
        );
        $telegram = $this->purchaseQuotes->previewForSelf(
            $actorUserId,
            $subjectUserId,
            $offeringSelectionToken,
            $quote->quotePublicId,
            $quote->configurationSnapshotHash,
        );
        if ($telegram->quoteAction !== 'reconfigure' || $telegram->finalPriceIrr < 1) {
            throw new RuntimeException('Service reconfiguration Quote did not preserve its paid commercial action.');
        }

        return $telegram;
    }

    /**
     * @return array{
     *   source_service_target_id:int,source_panel_connection_id:int,source_offering_id:int,
     *   target_offering_id:int,target_offering_code:string,server_selection_mode:string,protocol_selection_mode:string,
     *   route_policy_id:int,subject_user_id:int,service_public_id:string
     * }
     */
    private function context(int $actorUserId, int $subjectUserId, string $servicePublicId, string $offeringSelectionToken): array
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId
            || preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/i', $servicePublicId) !== 1) {
            throw new AuthorizationException('Service reconfiguration selection is restricted to an owned Service.');
        }
        $offering = $this->catalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        $connection = $this->database->connection();

        /** @var object{source_service_target_id:int|string,source_panel_connection_id:int|string,source_offering_id:int|string,lifecycle_state:string,provisioned_at:?string,remote_service_id:?string,remote_deleted_at:?string}|null $service */
        $service = $connection->table('service_subscriptions as service')
            ->join('order_items as original_item', 'original_item.id', '=', 'service.order_item_id')
            ->join('panel_service_targets as source_target', 'source_target.id', '=', 'service.service_target_id')
            ->leftJoin('plan_offering_route_selections as current_selection', 'current_selection.id', '=', 'service.route_selection_id')
            ->where('service.public_id', $servicePublicId)
            ->where('service.user_id', $subjectUserId)
            ->first([
                'service.service_target_id as source_service_target_id',
                'source_target.panel_connection_id as source_panel_connection_id',
                $connection->raw('COALESCE(current_selection.plan_offering_id, original_item.plan_offering_id) as source_offering_id'),
                'service.lifecycle_state', 'service.provisioned_at', 'service.remote_service_id', 'service.remote_deleted_at',
            ]);
        if ($service === null
            || ! in_array($service->lifecycle_state, ['active', 'suspended'], true)
            || $service->provisioned_at === null || $service->remote_deleted_at !== null
            || ! is_string($service->remote_service_id) || $service->remote_service_id === '') {
            throw new AuthorizationException('Service is not eligible for reconfiguration.');
        }

        /** @var object{id:int|string,code:string,server_selection_mode:string,protocol_selection_mode:string,route_policy_id:int|string}|null $target */
        $target = $connection->table('plan_offerings as offering')
            ->join('plan_offering_route_policies as policy', 'policy.plan_offering_id', '=', 'offering.id')
            ->where('offering.code', $offering->offeringCode)
            ->where('offering.state', 'active')
            ->where('offering.visibility', 'visible')
            ->first([
                'offering.id', 'offering.code', 'offering.server_selection_mode', 'offering.protocol_selection_mode',
                'policy.id as route_policy_id',
            ]);
        if ($target === null) {
            throw new AuthorizationException('Target Plan Offering is unavailable for Service reconfiguration.');
        }

        if (! $this->policyCapabilityAllows((int) $service->source_offering_id, (int) $service->source_service_target_id, 'reconfigure_service', false)) {
            throw new AuthorizationException('Current Service target does not expose reconfiguration capability.');
        }
        if ((int) $service->source_offering_id !== (int) $target->id
            && ! $this->policyCapabilityAllows((int) $service->source_offering_id, (int) $service->source_service_target_id, 'change_plan', true)) {
            throw new AuthorizationException('Current Plan Offering does not allow plan changes.');
        }

        return [
            'source_service_target_id' => (int) $service->source_service_target_id,
            'source_panel_connection_id' => (int) $service->source_panel_connection_id,
            'source_offering_id' => (int) $service->source_offering_id,
            'target_offering_id' => (int) $target->id,
            'target_offering_code' => $target->code,
            'server_selection_mode' => $this->selectionMode($target->server_selection_mode, ['system_selects', 'customer_selects', 'hybrid'], 'server'),
            'protocol_selection_mode' => $this->selectionMode($target->protocol_selection_mode, ['fixed', 'system_selects', 'customer_selects'], 'protocol'),
            'route_policy_id' => (int) $target->route_policy_id,
            'subject_user_id' => $subjectUserId,
            'service_public_id' => $servicePublicId,
        ];
    }

    /**
     * @param  array<string,int|string>  $context
     * @return list<object{id:int,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int,service_target_id:int}>
     */
    private function eligibleRoutes(array $context, bool $customerSelectableOnly): array
    {
        $query = $this->database->connection()->table('plan_offering_routes as route')
            ->join('sales_servers as server', 'server.id', '=', 'route.sales_server_id')
            ->join('panel_service_targets as target', 'target.id', '=', 'route.panel_service_target_id')
            ->where('route.plan_offering_route_policy_id', (int) $context['route_policy_id'])
            ->where('server.state', 'active')
            ->where('server.visibility', 'visible')
            ->where('target.state', 'active')
            ->where('target.capability_status', 'verified')
            ->orderBy('route.priority');
        if ($customerSelectableOnly) {
            $query->where('route.customer_selectable', true);
        }
        /** @var list<object{id:int|string,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int|string,service_target_id:int|string,panel_connection_id:int|string,customer_selectable:bool|int}> $rows */
        $rows = $query->get([
            'route.id', 'route.customer_selectable', 'route.sales_server_id',
            'route.panel_service_target_id as service_target_id', 'target.panel_connection_id',
            'server.code as server_code', 'server.name_fa as server_name_fa', 'server.name_en as server_name_en',
        ])->all();

        $eligible = [];
        foreach ($rows as $row) {
            if ((int) $row->panel_connection_id !== (int) $context['source_panel_connection_id']) {
                continue;
            }
            try {
                $availability = $this->capacity->availability((int) $row->service_target_id);
            } catch (Throwable) {
                continue;
            }
            if (! $availability->acceptingReservations || $availability->availableUnits < 1
                || ! $this->routeHasOperationalProfile($context, (int) $row->sales_server_id, (int) $row->service_target_id)) {
                continue;
            }
            $eligible[] = (object) [
                'id' => (int) $row->id,
                'server_code' => $row->server_code,
                'server_name_fa' => $row->server_name_fa,
                'server_name_en' => $row->server_name_en,
                'sales_server_id' => (int) $row->sales_server_id,
                'service_target_id' => (int) $row->service_target_id,
            ];
        }

        return $eligible;
    }

    /**
     * @param  array<string,int|string>  $context
     * @param  list<object{id:int,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int,service_target_id:int}>  $routes
     * @return object{id:int,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int,service_target_id:int}|null
     */
    private function selectedRoute(array $context, array $routes, ?string $token, bool $required): ?object
    {
        if ($context['server_selection_mode'] === 'system_selects') {
            if ($token !== null) {
                throw new AuthorizationException('Service reconfiguration server selection is not allowed.');
            }

            return null;
        }
        if ($token === null) {
            if ($required && $context['server_selection_mode'] === 'customer_selects') {
                throw new AuthorizationException('Service reconfiguration server selection is required.');
            }

            return null;
        }
        foreach ($routes as $route) {
            if (hash_equals(
                $this->routeToken((int) $context['subject_user_id'], (string) $context['service_public_id'], (string) $context['target_offering_code'], $route->id),
                $token,
            )) {
                return $route;
            }
        }
        throw new AuthorizationException('Service reconfiguration server selection is unavailable.');
    }

    /**
     * @param  array<string,int|string>  $context
     * @param  object{id:int,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int,service_target_id:int}|null  $selectedRoute
     * @param  list<object{id:int,server_code:string,server_name_fa:string,server_name_en:?string,sales_server_id:int,service_target_id:int}>  $routes
     * @return list<TelegramServiceReconfigurationProtocolOption>
     */
    private function protocolOptions(array $context, ?object $selectedRoute, array $routes): array
    {
        if ($context['protocol_selection_mode'] !== 'customer_selects') {
            return [];
        }
        /** @var list<object{id:int|string,code:string,name_fa:string,name_en:?string}> $profiles */
        $profiles = $this->database->connection()->table('plan_offering_protocol_profiles as assignment')
            ->join('panel_protocol_profiles as profile', 'profile.id', '=', 'assignment.panel_protocol_profile_id')
            ->where('assignment.plan_offering_id', (int) $context['target_offering_id'])
            ->where('assignment.customer_selectable', true)
            ->where('profile.state', 'active')
            ->orderBy('profile.id')
            ->get(['profile.id', 'profile.code', 'profile.name_fa', 'profile.name_en'])
            ->all();
        $candidateRoutes = $selectedRoute === null ? $routes : [$selectedRoute];
        $options = [];
        foreach ($profiles as $profile) {
            $operational = false;
            foreach ($candidateRoutes as $route) {
                try {
                    $this->routeVerifier->assertOperational(
                        $this->database->connection(),
                        (int) $context['target_offering_id'],
                        $route->sales_server_id,
                        $route->service_target_id,
                        (int) $profile->id,
                    );
                    $operational = true;
                    break;
                } catch (RouteCandidateUnavailable|DomainException) {
                    // Try another eligible route/profile pairing.
                }
            }
            if (! $operational) {
                continue;
            }
            $options[] = new TelegramServiceReconfigurationProtocolOption(
                $this->profileToken((int) $context['subject_user_id'], (string) $context['service_public_id'], (string) $context['target_offering_code'], (int) $profile->id),
                $profile->code,
                $profile->name_fa,
                $profile->name_en,
            );
        }

        return $options;
    }

    /**
     * @param  array<string,int|string>  $context
     * @param  list<TelegramServiceReconfigurationProtocolOption>  $options
     */
    private function selectedProtocol(array $context, array $options, ?string $token): ?TelegramServiceReconfigurationProtocolOption
    {
        if ($context['protocol_selection_mode'] !== 'customer_selects') {
            if ($token !== null) {
                throw new AuthorizationException('Service reconfiguration protocol selection is not allowed.');
            }

            return null;
        }
        if ($token === null) {
            throw new AuthorizationException('Service reconfiguration protocol selection is required.');
        }
        foreach ($options as $option) {
            if (hash_equals($option->selectionToken, $token)) {
                return $option;
            }
        }
        throw new AuthorizationException('Service reconfiguration protocol selection is unavailable.');
    }

    /** @param array<string,int|string> $context */
    private function routeHasOperationalProfile(array $context, int $salesServerId, int $serviceTargetId): bool
    {
        /** @var list<int|string> $profileIds */
        $profileIds = $this->database->connection()->table('plan_offering_protocol_profiles as assignment')
            ->join('panel_protocol_profiles as profile', 'profile.id', '=', 'assignment.panel_protocol_profile_id')
            ->where('assignment.plan_offering_id', (int) $context['target_offering_id'])
            ->where('profile.state', 'active')
            ->pluck('profile.id')->all();
        foreach ($profileIds as $profileId) {
            try {
                $this->routeVerifier->assertOperational(
                    $this->database->connection(),
                    (int) $context['target_offering_id'],
                    $salesServerId,
                    $serviceTargetId,
                    (int) $profileId,
                );

                return true;
            } catch (RouteCandidateUnavailable|DomainException) {
                // Continue checking the next compatible profile.
            }
        }

        return false;
    }

    private function policyCapabilityAllows(int $offeringId, int $serviceTargetId, string $operationCode, bool $requirePolicy): bool
    {
        if ($operationCode === 'reconfigure_service') {
            return $this->database->connection()->table('panel_target_capabilities')
                ->where('panel_service_target_id', $serviceTargetId)
                ->where('capability_code', 'reconfigure_service')
                ->where('verification_status', 'verified')
                ->exists();
        }
        /** @var object{customer_enabled:bool|int,required_capability_code:?string}|null $policy */
        $policy = $this->database->connection()->table('plan_offering_operations')
            ->where('plan_offering_id', $offeringId)
            ->where('operation_code', $operationCode)
            ->first(['customer_enabled', 'required_capability_code']);
        if ($policy === null) {
            return ! $requirePolicy;
        }
        if (! (bool) $policy->customer_enabled) {
            return false;
        }
        if ($policy->required_capability_code === null) {
            return true;
        }

        return $this->database->connection()->table('panel_target_capabilities')
            ->where('panel_service_target_id', $serviceTargetId)
            ->where('capability_code', $policy->required_capability_code)
            ->where('verification_status', 'verified')
            ->exists();
    }

    private function routeToken(int $userId, string $servicePublicId, string $offeringCode, int $routeId): string
    {
        return substr(hash('sha256', "telegram-service-reconfigure-route-v1:{$userId}:{$servicePublicId}:{$offeringCode}:{$routeId}"), 0, 40);
    }

    private function profileToken(int $userId, string $servicePublicId, string $offeringCode, int $profileId): string
    {
        return substr(hash('sha256', "telegram-service-reconfigure-profile-v1:{$userId}:{$servicePublicId}:{$offeringCode}:{$profileId}"), 0, 40);
    }

    /** @param list<string> $allowed */
    private function selectionMode(string $value, array $allowed, string $label): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException('Stored Service reconfiguration '.$label.' selection mode is invalid.');
        }

        return $value;
    }

    private function databaseDateTime(string $value, string $label): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if ($parsed === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $parsed;
    }

    private function nonNegativeInt(int|string $value, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }
}
