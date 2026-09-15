<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\RouteCandidateUnavailable;
use App\Modules\Panels\Application\TargetCapacityAllocator;
use App\Modules\Telegram\Application\TelegramCustomerTrialClaimOptions;
use App\Modules\Telegram\Application\TelegramCustomerTrialFallbackDisclosure;
use App\Modules\Telegram\Application\TelegramCustomerTrialProtocolOption;
use App\Modules\Telegram\Application\TelegramCustomerTrialRouteOption;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class TelegramTrialClaimSelectionService
{
    public function __construct(
        private DatabaseManager $database,
        private TelegramCustomerTrialCatalogService $trialCatalog,
        private RouteOperationalVerifier $routeVerifier,
        private TargetCapacityAllocator $capacity,
    ) {}

    public function optionsForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
    ): TelegramCustomerTrialClaimOptions {
        $context = $this->context($actorUserId, $subjectUserId, $offeringSelectionToken);
        $routeOptions = $this->routeOptions($context);
        if ($context['server_selection_mode'] === 'customer_selects' && $routeOptions === []) {
            throw new AuthorizationException('Telegram Trial has no currently available customer-selectable route.');
        }
        $selectedRoute = $this->selectedRoute($context, $routeOptions, $routeSelectionToken, false);
        $protocolOptions = $this->protocolOptions($context, $selectedRoute);
        if ($context['protocol_selection_mode'] === 'customer_selects' && $protocolOptions === []) {
            throw new AuthorizationException('Telegram Trial has no currently available customer-selectable protocol.');
        }

        return new TelegramCustomerTrialClaimOptions(
            $offeringSelectionToken,
            $context['server_selection_mode'],
            $context['protocol_selection_mode'],
            $routeOptions,
            $protocolOptions,
            $context['fallback_allowed'],
            $this->fallbackDisclosures($context),
        );
    }

    public function resolveForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        ?string $routeSelectionToken,
        ?string $protocolSelectionToken,
    ): TelegramTrialClaimResolvedSelection {
        $context = $this->context($actorUserId, $subjectUserId, $offeringSelectionToken);
        $routeOptions = $this->routeOptions($context);
        if ($context['server_selection_mode'] === 'customer_selects' && $routeOptions === []) {
            throw new AuthorizationException('Telegram Trial has no currently available customer-selectable route.');
        }
        $selectedRoute = $this->selectedRoute($context, $routeOptions, $routeSelectionToken, true);
        $protocolOptions = $this->protocolOptions($context, $selectedRoute);
        if ($context['protocol_selection_mode'] === 'customer_selects' && $protocolOptions === []) {
            throw new AuthorizationException('Telegram Trial has no currently available customer-selectable protocol.');
        }
        $selectedProfile = $this->selectedProfile(
            $context,
            $protocolOptions,
            $protocolSelectionToken,
        );

        return new TelegramTrialClaimResolvedSelection(
            $context['offering_id'],
            $selectedRoute === null ? null : $selectedRoute->id,
            $selectedProfile === null ? null : $selectedProfile->id,
        );
    }

    public function assertCommittedReplayOfferingTokenForSelf(
        int $actorUserId,
        int $subjectUserId,
        string $offeringSelectionToken,
        int $offeringId,
    ): void {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId || $offeringId < 1) {
            throw new AuthorizationException('Telegram Trial replay selection is restricted to self-service.');
        }
        $code = $this->database->connection()->table('plan_offerings')
            ->where('id', $offeringId)
            ->value('code');
        if (! is_string($code) || $code === '') {
            throw new AuthorizationException('Telegram Trial replay Offering is unavailable.');
        }
        $expected = substr(hash('sha256', "telegram-trial-offering-v1:{$subjectUserId}:{$code}"), 0, 40);
        if (! hash_equals($expected, $offeringSelectionToken)) {
            throw new AuthorizationException('Telegram Trial replay Offering selection does not match.');
        }
    }

    public function presentationForCommittedSelection(
        int $offeringId,
        int $routeId,
        int $salesServerId,
        int $protocolProfileId,
        bool $fallbackUsed,
        ?string $fallbackDisclosureFa,
    ): TelegramTrialClaimPresentation {
        $connection = $this->database->connection();
        /** @var object{name_fa:string,name_en:?string}|null $server */
        $server = $connection->table('sales_servers')->where('id', $salesServerId)->first(['name_fa', 'name_en']);
        /** @var object{name_fa:string,name_en:?string}|null $profile */
        $profile = $connection->table('panel_protocol_profiles')->where('id', $protocolProfileId)->first(['name_fa', 'name_en']);
        /** @var object{disclosure_en:?string}|null $route */
        $route = $connection->table('plan_offering_routes as route')
            ->join('plan_offering_route_policies as policy', 'policy.id', '=', 'route.plan_offering_route_policy_id')
            ->where('route.id', $routeId)
            ->where('policy.plan_offering_id', $offeringId)
            ->first(['route.disclosure_en']);
        if ($server === null || $profile === null || $route === null) {
            throw new RuntimeException('Committed Trial presentation authority is unavailable.');
        }

        return new TelegramTrialClaimPresentation(
            $this->databaseString($server->name_fa, 'Trial sales server Persian label'),
            $this->optionalDatabaseString($server->name_en),
            $this->databaseString($profile->name_fa, 'Trial protocol Persian label'),
            $this->optionalDatabaseString($profile->name_en),
            $fallbackUsed ? $this->optionalDatabaseString($fallbackDisclosureFa) : null,
            $fallbackUsed ? $this->optionalDatabaseString($route->disclosure_en) : null,
        );
    }

    /**
     * @return array{
     *   user_id:int,offering_id:int,offering_code:string,server_selection_mode:string,protocol_selection_mode:string,
     *   fallback_allowed:bool,policy_id:int,
     *   routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>,
     *   profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>
     * }
     */
    private function context(int $actorUserId, int $subjectUserId, string $offeringSelectionToken): array
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Telegram Trial claim selection is restricted to self-service.');
        }
        $offering = $this->trialCatalog->offeringForSelf($actorUserId, $subjectUserId, $offeringSelectionToken);
        $connection = $this->database->connection();
        /** @var object{id:int|string,code:string,server_selection_mode:string,protocol_selection_mode:string}|null $row */
        $row = $connection->table('plan_offerings')
            ->where('code', $offering->offeringCode)
            ->where('state', 'active')
            ->where('visibility', 'visible')
            ->first(['id', 'code', 'server_selection_mode', 'protocol_selection_mode']);
        if ($row === null) {
            throw new AuthorizationException('Telegram Trial Offering is no longer available.');
        }
        $offeringId = $this->positiveDatabaseInt($row->id, 'Trial Offering ID');
        /** @var object{id:int|string,fallback_allowed:bool|int}|null $policy */
        $policy = $connection->table('trial_policies')
            ->where('plan_offering_id', $offeringId)
            ->where('enabled', true)
            ->first(['id', 'fallback_allowed']);
        if ($policy === null) {
            throw new AuthorizationException('Telegram Trial policy is no longer available.');
        }
        $routePolicyId = $connection->table('plan_offering_route_policies')
            ->where('plan_offering_id', $offeringId)
            ->value('id');
        if (! is_int($routePolicyId) && ! is_string($routePolicyId)) {
            throw new AuthorizationException('Telegram Trial route policy is unavailable.');
        }

        /** @var list<object{id:int|string,sales_server_id:int|string,panel_service_target_id:int|string,route_type:string,customer_selectable:bool|int,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}> $routeRows */
        $routeRows = $connection->table('plan_offering_routes as route')
            ->join('sales_servers as server', 'server.id', '=', 'route.sales_server_id')
            ->where('route.plan_offering_route_policy_id', (int) $routePolicyId)
            ->orderBy('route.priority')
            ->get([
                'route.id', 'route.sales_server_id', 'route.panel_service_target_id', 'route.route_type',
                'route.customer_selectable', 'route.disclosure_fa', 'route.disclosure_en',
                'server.name_fa as server_name_fa', 'server.name_en as server_name_en',
            ])->all();
        /** @var list<object{id:int|string,customer_selectable:bool|int,is_default:bool|int,name_fa:string,name_en:?string}> $profileRows */
        $profileRows = $connection->table('plan_offering_protocol_profiles as assignment')
            ->join('panel_protocol_profiles as profile', 'profile.id', '=', 'assignment.panel_protocol_profile_id')
            ->where('assignment.plan_offering_id', $offeringId)
            ->where('profile.state', 'active')
            ->orderBy('profile.id')
            ->get([
                'profile.id', 'assignment.customer_selectable', 'assignment.is_default',
                'profile.name_fa', 'profile.name_en',
            ])->all();

        return [
            'user_id' => $subjectUserId,
            'offering_id' => $offeringId,
            'offering_code' => $this->databaseString($row->code, 'Trial Offering code'),
            'server_selection_mode' => $this->selectionMode(
                $row->server_selection_mode,
                ['system_selects', 'customer_selects', 'hybrid'],
                'server',
            ),
            'protocol_selection_mode' => $this->selectionMode(
                $row->protocol_selection_mode,
                ['fixed', 'system_selects', 'customer_selects'],
                'protocol',
            ),
            'fallback_allowed' => (bool) $policy->fallback_allowed,
            'policy_id' => $this->positiveDatabaseInt($policy->id, 'Trial policy ID'),
            'routes' => array_map(fn (object $route): object => (object) [
                'id' => $this->positiveDatabaseInt($route->id, 'Trial route ID'),
                'sales_server_id' => $this->positiveDatabaseInt($route->sales_server_id, 'Trial sales server ID'),
                'panel_service_target_id' => $this->positiveDatabaseInt($route->panel_service_target_id, 'Trial target ID'),
                'route_type' => $this->databaseString($route->route_type, 'Trial route type'),
                'customer_selectable' => (bool) $route->customer_selectable,
                'disclosure_fa' => $this->optionalDatabaseString($route->disclosure_fa),
                'disclosure_en' => $this->optionalDatabaseString($route->disclosure_en),
                'server_name_fa' => $this->databaseString($route->server_name_fa, 'Trial server Persian label'),
                'server_name_en' => $this->optionalDatabaseString($route->server_name_en),
            ], $routeRows),
            'profiles' => array_map(fn (object $profile): object => (object) [
                'id' => $this->positiveDatabaseInt($profile->id, 'Trial protocol profile ID'),
                'customer_selectable' => (bool) $profile->customer_selectable,
                'is_default' => (bool) $profile->is_default,
                'name_fa' => $this->databaseString($profile->name_fa, 'Trial protocol Persian label'),
                'name_en' => $this->optionalDatabaseString($profile->name_en),
            ], $profileRows),
        ];
    }

    /**
     * @param  array{user_id:int,offering_id:int,offering_code:string,server_selection_mode:string,protocol_selection_mode:string,fallback_allowed:bool,policy_id:int,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>,profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>}  $context
     * @return list<TelegramCustomerTrialRouteOption>
     */
    private function routeOptions(array $context): array
    {
        if ($context['server_selection_mode'] === 'system_selects') {
            return [];
        }
        $profiles = $this->candidateProfiles($context);
        $options = [];
        foreach ($context['routes'] as $route) {
            if (! $route->customer_selectable || ! $this->routeOperationalWithAnyProfile($context, $route, $profiles)) {
                continue;
            }
            $options[] = new TelegramCustomerTrialRouteOption(
                $this->routeToken($context['user_id'], $context['offering_code'], $route->id),
                $route->server_name_fa,
                $route->server_name_en,
            );
        }

        return $options;
    }

    /**
     * @param  array{user_id:int,offering_id:int,offering_code:string,server_selection_mode:string,protocol_selection_mode:string,fallback_allowed:bool,policy_id:int,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>,profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>}  $context
     * @param  list<TelegramCustomerTrialRouteOption>  $routeOptions
     * @return object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}|null
     */
    private function selectedRoute(array $context, array $routeOptions, ?string $selectionToken, bool $required): ?object
    {
        if ($context['server_selection_mode'] === 'system_selects') {
            if ($selectionToken !== null) {
                throw new AuthorizationException('Telegram Trial route selection is not allowed.');
            }

            return null;
        }
        if ($selectionToken === null) {
            if ($context['server_selection_mode'] === 'customer_selects' && $required) {
                throw new AuthorizationException('Telegram Trial route selection is required.');
            }

            return null;
        }
        if (! array_filter($routeOptions, static fn (TelegramCustomerTrialRouteOption $option): bool => hash_equals($option->selectionToken, $selectionToken))) {
            throw new AuthorizationException('Telegram Trial route selection is stale or unavailable.');
        }
        foreach ($context['routes'] as $route) {
            if (hash_equals($this->routeToken($context['user_id'], $context['offering_code'], $route->id), $selectionToken)) {
                return $route;
            }
        }

        throw new AuthorizationException('Telegram Trial route selection is unavailable.');
    }

    /**
     * @param  array{user_id:int,offering_id:int,offering_code:string,server_selection_mode:string,protocol_selection_mode:string,fallback_allowed:bool,policy_id:int,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>,profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>}  $context
     * @param  object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}|null  $selectedRoute
     * @return list<TelegramCustomerTrialProtocolOption>
     */
    private function protocolOptions(array $context, ?object $selectedRoute): array
    {
        if ($context['protocol_selection_mode'] !== 'customer_selects') {
            return [];
        }
        $routes = $this->protocolCandidateRoutes($context, $selectedRoute);
        $options = [];
        foreach ($context['profiles'] as $profile) {
            if (! $profile->customer_selectable) {
                continue;
            }
            foreach ($routes as $route) {
                if ($this->routeProfileOperational($context['offering_id'], $route, $profile->id)) {
                    $options[] = new TelegramCustomerTrialProtocolOption(
                        $this->protocolToken($context['user_id'], $context['offering_code'], $profile->id),
                        $profile->name_fa,
                        $profile->name_en,
                    );
                    break;
                }
            }
        }

        return $options;
    }

    /**
     * @param  array{user_id:int,offering_id:int,offering_code:string,server_selection_mode:string,protocol_selection_mode:string,fallback_allowed:bool,policy_id:int,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>,profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>}  $context
     * @param  list<TelegramCustomerTrialProtocolOption>  $protocolOptions
     * @return object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}|null
     */
    private function selectedProfile(array $context, array $protocolOptions, ?string $selectionToken): ?object
    {
        if ($context['protocol_selection_mode'] !== 'customer_selects') {
            if ($selectionToken !== null) {
                throw new AuthorizationException('Telegram Trial protocol selection is not allowed.');
            }

            return null;
        }
        if ($selectionToken === null) {
            throw new AuthorizationException('Telegram Trial protocol selection is required.');
        }
        if (! array_filter($protocolOptions, static fn (TelegramCustomerTrialProtocolOption $option): bool => hash_equals($option->selectionToken, $selectionToken))) {
            throw new AuthorizationException('Telegram Trial protocol selection is stale or unavailable.');
        }
        foreach ($context['profiles'] as $profile) {
            if (hash_equals($this->protocolToken($context['user_id'], $context['offering_code'], $profile->id), $selectionToken)) {
                return $profile;
            }
        }

        throw new AuthorizationException('Telegram Trial protocol selection is unavailable.');
    }

    /**
     * Mirror TrialRouteSelector::orderedCandidates() for read-only protocol projection.
     *
     * @param  array{server_selection_mode:string,fallback_allowed:bool,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>}  $context
     * @param  object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}|null  $selectedRoute
     * @return list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>
     */
    private function protocolCandidateRoutes(array $context, ?object $selectedRoute): array
    {
        if ($selectedRoute === null) {
            if ($context['server_selection_mode'] === 'customer_selects') {
                return array_values(array_filter(
                    $context['routes'],
                    static fn (object $route): bool => $route->customer_selectable,
                ));
            }

            return $context['fallback_allowed'] ? $context['routes'] : array_slice($context['routes'], 0, 1);
        }
        if (! $context['fallback_allowed']) {
            return [$selectedRoute];
        }

        $fallbacks = array_values(array_filter(
            $context['routes'],
            static fn (object $route): bool => $route->id !== $selectedRoute->id && $route->route_type === 'fallback',
        ));

        return [$selectedRoute, ...$fallbacks];
    }

    /**
     * @param  array{protocol_selection_mode:string,profiles:list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>}  $context
     * @return list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>
     */
    private function candidateProfiles(array $context): array
    {
        return array_values(array_filter(
            $context['profiles'],
            static fn (object $profile): bool => $context['protocol_selection_mode'] === 'customer_selects'
                ? $profile->customer_selectable
                : $profile->is_default,
        ));
    }

    /**
     * @param  array{offering_id:int}  $context
     * @param  object{sales_server_id:int,panel_service_target_id:int}  $route
     * @param  list<object{id:int,customer_selectable:bool,is_default:bool,name_fa:string,name_en:?string}>  $profiles
     */
    private function routeOperationalWithAnyProfile(array $context, object $route, array $profiles): bool
    {
        foreach ($profiles as $profile) {
            if ($this->routeProfileOperational($context['offering_id'], $route, $profile->id)) {
                return true;
            }
        }

        return false;
    }

    /** @param object{sales_server_id:int,panel_service_target_id:int} $route */
    private function routeProfileOperational(int $offeringId, object $route, int $profileId): bool
    {
        try {
            $this->routeVerifier->assertOperational(
                $this->database->connection(),
                $offeringId,
                $route->sales_server_id,
                $route->panel_service_target_id,
                $profileId,
            );
        } catch (RouteCandidateUnavailable) {
            return false;
        }
        $availability = $this->capacity->availability($route->panel_service_target_id);

        return $availability->acceptingReservations && $availability->availableUnits > 0;
    }

    /**
     * @param  array{fallback_allowed:bool,routes:list<object{id:int,sales_server_id:int,panel_service_target_id:int,route_type:string,customer_selectable:bool,disclosure_fa:?string,disclosure_en:?string,server_name_fa:string,server_name_en:?string}>}  $context
     * @return list<TelegramCustomerTrialFallbackDisclosure>
     */
    private function fallbackDisclosures(array $context): array
    {
        if (! $context['fallback_allowed']) {
            return [];
        }
        $items = [];
        foreach ($context['routes'] as $route) {
            if ($route->route_type !== 'fallback' || $route->disclosure_fa === null) {
                continue;
            }
            $items[] = new TelegramCustomerTrialFallbackDisclosure(
                $route->server_name_fa,
                $route->server_name_en,
                $route->disclosure_fa,
                $route->disclosure_en,
            );
        }

        return $items;
    }

    private function routeToken(int $userId, string $offeringCode, int $routeId): string
    {
        return substr(hash('sha256', "telegram-trial-route-v1:{$userId}:{$offeringCode}:{$routeId}"), 0, 40);
    }

    private function protocolToken(int $userId, string $offeringCode, int $profileId): string
    {
        return substr(hash('sha256', "telegram-trial-protocol-v1:{$userId}:{$offeringCode}:{$profileId}"), 0, 40);
    }

    /** @param list<string> $allowed */
    private function selectionMode(string $value, array $allowed, string $label): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new RuntimeException("Stored Trial {$label} selection mode is invalid.");
        }

        return $value;
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        $normalized = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($normalized === false) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $normalized;
    }

    private function databaseString(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $value;
    }

    private function optionalDatabaseString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Stored optional Trial label is invalid.');
        }

        return $value;
    }
}
