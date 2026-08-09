<?php

declare(strict_types=1);

namespace App\Modules\Agents\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileCode;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleCode;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class AgentPricingService
{
    private const MANAGE_PERMISSION = 'agents.pricing.manage';
    private const FORMULA_VERSION = 'agt-price-resolution-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
    ) {}

    /** @requirement AGT-005 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function createProfile(string $key, string $code, AgentPricingProfileDefinition $definition, AccessChangeContext $context): AgentPricingProfileVersionReceipt
    {
        return $this->writeProfile('create', $key, (new AgentPricingProfileCode($code))->value, $definition, $context);
    }

    /** @requirement AGT-005 ACL-002 DAT-003 SEC-002 QUA-001 */
    public function reviseProfile(string $key, string $code, AgentPricingProfileDefinition $definition, AccessChangeContext $context): AgentPricingProfileVersionReceipt
    {
        return $this->writeProfile('revise', $key, (new AgentPricingProfileCode($code))->value, $definition, $context);
    }

    /** @requirement AGT-005 ACL-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function createRule(string $key, string $profileCode, string $ruleCode, AgentPricingRuleDefinition $definition, AccessChangeContext $context): AgentPricingRuleVersionReceipt
    {
        return $this->writeRule('create', $key, (new AgentPricingProfileCode($profileCode))->value, (new AgentPricingRuleCode($ruleCode))->value, $definition, $context);
    }

    /** @requirement AGT-005 ACL-002 DAT-003 SEC-002 QUA-001 */
    public function reviseRule(string $key, string $profileCode, string $ruleCode, AgentPricingRuleDefinition $definition, AccessChangeContext $context): AgentPricingRuleVersionReceipt
    {
        return $this->writeRule('revise', $key, (new AgentPricingProfileCode($profileCode))->value, (new AgentPricingRuleCode($ruleCode))->value, $definition, $context);
    }

    /** @requirement AGT-005 BUY-002 DAT-002 DAT-003 SEC-001 SEC-002 QUA-001 */
    public function resolve(AgentPricingResolutionRequest $request, AgentPricingResolutionContext $context): AgentPricingResolutionReceipt
    {
        if ($context->actorUserId !== $request->userId) {
            throw new AuthorizationException('Agent pricing resolution actor is not authorized for this subject.');
        }
        $requestHash = $this->hash([
            'action' => $request->action->value,
            'plan_offering_id' => $request->planOfferingId,
            'pricing_profile_code' => $request->pricingProfileCode,
            'resolution_key' => $request->resolutionKey,
            'user_id' => $request->userId,
        ]);

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($request, $requestHash): AgentPricingResolutionReceipt {
                $existing = $this->resolutionRow($db, $request->resolutionKey, true);
                if ($existing !== null) {
                    return $this->resolutionReceipt($existing, $requestHash, true);
                }
                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $db->table('users')->where('id', $request->userId)->lockForUpdate()->first(['account_type', 'account_status']);
                if ($user === null || $user->account_type !== 'agent' || $user->account_status !== 'active') { throw new DomainException('Agent pricing resolution requires an active agent account.'); }
                /** @var object{id:int|string,status:string,pricing_profile_code:string|null}|null $agent */
                $agent = $db->table('agent_profiles')->where('user_id', $request->userId)->lockForUpdate()->first(['id', 'status', 'pricing_profile_code']);
                if ($agent === null || $agent->status !== 'active') { throw new DomainException('Agent pricing resolution requires an active agent profile.'); }
                if ($agent->pricing_profile_code === null || ! hash_equals($request->pricingProfileCode, $agent->pricing_profile_code)) { throw new DomainException('Agent pricing profile code is stale or invalid.'); }
                /** @var object{id:int|string,public_id:string,profile_code:string}|null $profile */
                $profile = $db->table('agent_pricing_profiles')->where('profile_code', $request->pricingProfileCode)->lockForUpdate()->first(['id', 'public_id', 'profile_code']);
                if ($profile === null) { throw new DomainException('Agent pricing profile configuration does not exist.'); }
                $profileId = $this->positive($profile->id, 'Agent pricing profile ID');
                $profileVersion = $this->latestProfileVersion($db, $profileId);
                if ($profileVersion === null || $profileVersion->state !== AgentPricingState::Active->value) { throw new DomainException('Agent pricing profile configuration is not active.'); }
                $this->verifyProfileVersion($profileVersion, $profile->profile_code);
                /** @var object{product_id:int|string,sales_server_id:int|string}|null $offering */
                $offering = $db->table('plan_offerings')->where('id', $request->planOfferingId)->lockForUpdate()->first(['product_id', 'sales_server_id']);
                if ($offering === null) { throw new DomainException('Agent pricing resolution offering does not exist.'); }
                $productId = $this->positive($offering->product_id, 'Agent pricing product ID');
                $serverId = $this->positive($offering->sales_server_id, 'Agent pricing sales server ID');
                $rule = $this->selectRule($db, $profileId, $request, $productId, $serverId);
                $ruleId = $rule === null ? null : $this->positive($rule->agent_pricing_rule_id, 'Agent pricing rule ID');
                $ruleVersionId = $rule === null ? null : $this->positive($rule->id, 'Agent pricing rule version ID');
                $ruleVersion = $rule === null ? null : $this->positive($rule->version, 'Agent pricing rule version');
                $override = $rule === null ? null : $this->nonNegative($rule->override_price_irr, 'Agent pricing override');
                $allowDiscount = (bool) $profileVersion->discount_combination_allowed;
                $snapshot = [
                    'action' => $request->action->value,
                    'agent_profile_id' => $this->positive($agent->id, 'Agent profile ID'),
                    'discount_combination_allowed' => $allowDiscount,
                    'formula_version' => self::FORMULA_VERSION,
                    'matched' => $rule !== null,
                    'override_before_discount' => true,
                    'override_price_irr' => $override,
                    'plan_offering_id' => $request->planOfferingId,
                    'pricing_profile' => ['configuration' => $this->jsonObject($profileVersion->configuration_snapshot), 'configuration_hash' => $profileVersion->configuration_hash, 'profile_code' => $profile->profile_code, 'public_id' => $profile->public_id, 'version' => $this->positive($profileVersion->version, 'Agent pricing profile version')],
                    'product_id' => $productId,
                    'rule' => $rule === null ? null : ['configuration' => $this->jsonObject($rule->configuration_snapshot), 'configuration_hash' => $rule->configuration_hash, 'public_id' => $rule->rule_public_id, 'rule_code' => $rule->rule_code, 'version' => $ruleVersion],
                    'sales_server_id' => $serverId,
                ];
                ksort($snapshot, SORT_STRING);
                $snapshotJson = $this->json($snapshot);
                $id = (int) $db->table('agent_pricing_resolutions')->insertGetId([
                    'public_id' => (string) Str::ulid(), 'resolution_key' => $request->resolutionKey, 'request_payload_hash' => $requestHash, 'user_id' => $request->userId,
                    'agent_profile_id' => $this->positive($agent->id, 'Agent profile ID'), 'agent_pricing_profile_id' => $profileId,
                    'agent_pricing_profile_version_id' => $this->positive($profileVersion->id, 'Agent pricing profile version ID'), 'pricing_profile_public_id_snapshot' => $profile->public_id,
                    'pricing_profile_code_snapshot' => $profile->profile_code, 'pricing_profile_version' => $this->positive($profileVersion->version, 'Agent pricing profile version'),
                    'pricing_profile_configuration_hash' => $profileVersion->configuration_hash, 'plan_offering_id' => $request->planOfferingId, 'product_id_snapshot' => $productId,
                    'sales_server_id_snapshot' => $serverId, 'action' => $request->action->value, 'agent_pricing_rule_id' => $ruleId, 'agent_pricing_rule_version_id' => $ruleVersionId,
                    'rule_public_id_snapshot' => $rule?->rule_public_id, 'rule_code_snapshot' => $rule?->rule_code, 'rule_version' => $ruleVersion, 'rule_configuration_hash' => $rule?->configuration_hash,
                    'override_price_irr' => $override, 'discount_combination_allowed' => $allowDiscount, 'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => hash('sha256', $snapshotJson), 'created_at' => $this->timestamp(),
                ]);
                $created = $this->resolutionById($db, $id);
                if ($created === null) { throw new RuntimeException('Agent pricing resolution persistence failed.'); }
                return $this->resolutionReceipt($created, $requestHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->resolutionRow($this->database->connection(), $request->resolutionKey);
            if ($existing !== null) { return $this->resolutionReceipt($existing, $requestHash, true); }
            throw $exception;
        }
    }

    use AgentPricingManagementSupport;
    use AgentPricingPersistenceSupport;
}
