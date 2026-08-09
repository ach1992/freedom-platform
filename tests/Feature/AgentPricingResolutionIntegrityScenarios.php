<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Agents\Application\AgentPricingResolutionContext;
use App\Modules\Agents\Application\AgentPricingResolutionRequest;
use App\Modules\Agents\Application\AgentPricingService;
use App\Modules\Agents\Domain\AgentPricingAction;
use App\Modules\Agents\Domain\AgentPricingProfileDefinition;
use App\Modules\Agents\Domain\AgentPricingRuleDefinition;
use App\Modules\Agents\Domain\AgentPricingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait AgentPricingResolutionIntegrityScenarios
{
    public function test_discount_combination_policy_is_snapshotted_and_override_precedes_discount_without_applying_promotions(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        foreach ([true, false] as $index => $allowed) {
            $profileCode = $allowed ? 'agt-combine-yes' : 'agt-combine-no';
            $this->createProfile($service, $owner, $profileCode, $allowed);
            $service->createRule('agt.combine.rule.'.($index + 1), $profileCode, 'default', new AgentPricingRuleDefinition(AgentPricingState::Active, 810_000), $this->context($owner, 'combine-rule-'.($index + 1)));
            $agent = $this->agent($profileCode);
            $receipt = $service->resolve($this->request('agt.resolve.combine.'.($index + 1), $agent, $profileCode, $offering['id']), new AgentPricingResolutionContext($agent));
            self::assertSame($allowed, $receipt->discountCombinationAllowed);
            $stored = DB::table('agent_pricing_resolutions')->where('id', $receipt->resolutionId)->value('configuration_snapshot');
            self::assertIsString($stored);
            $snapshot = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($snapshot);
            self::assertTrue($snapshot['override_before_discount']);
            self::assertSame($allowed, $snapshot['discount_combination_allowed']);
            self::assertSame(810_000, $snapshot['override_price_irr']);
        }
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('ledger_transactions')->count());
        self::assertSame(0, DB::table('payment_intents')->count());
    }

    public function test_exact_replay_and_snapshot_remain_stable_after_rule_profile_and_agent_profile_changes(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        $profile = $this->createProfile($service, $owner, 'agt-stable', true);
        $rule = $service->createRule('agt.stable.rule.1', 'agt-stable', 'stable', new AgentPricingRuleDefinition(AgentPricingState::Active, 777_000, action: AgentPricingAction::Purchase), $this->context($owner, 'stable-rule-1'));
        $agent = $this->agent('agt-stable');
        $request = $this->request('agt.resolve.stable', $agent, 'agt-stable', $offering['id']);
        $accepted = $service->resolve($request, new AgentPricingResolutionContext($agent));
        self::assertSame($profile->version, $accepted->pricingProfileVersion);
        self::assertSame($profile->configurationHash, $accepted->pricingProfileConfigurationHash);
        self::assertSame($rule->version, $accepted->ruleVersion);
        self::assertSame($rule->configurationHash, $accepted->ruleConfigurationHash);
        self::assertSame(777_000, $accepted->overridePriceIrr);
        self::assertTrue($accepted->discountCombinationAllowed);
        $service->reviseRule('agt.stable.rule.2', 'agt-stable', 'stable', new AgentPricingRuleDefinition(AgentPricingState::Disabled, 650_000, action: AgentPricingAction::Purchase), $this->context($owner, 'stable-rule-2'));
        $service->reviseProfile('agt.stable.profile.2', 'agt-stable', new AgentPricingProfileDefinition(AgentPricingState::Disabled, false), $this->context($owner, 'stable-profile-2'));
        $replay = $service->resolve($request, new AgentPricingResolutionContext($agent));
        self::assertTrue($replay->replayed);
        self::assertSame($accepted->resolutionId, $replay->resolutionId);
        self::assertSame(777_000, $replay->overridePriceIrr);
        self::assertTrue($replay->discountCombinationAllowed);
        self::assertSame($accepted->configurationSnapshotHash, $replay->configurationSnapshotHash);
        self::assertSame(1, $replay->pricingProfileVersion);
        self::assertSame(1, $replay->ruleVersion);
        $this->assertRuntimeMessage('Agent pricing resolution key conflict.', fn (): mixed => $service->resolve(new AgentPricingResolutionRequest('agt.resolve.stable', $agent, 'agt-stable', $offering['id'], AgentPricingAction::Renew), new AgentPricingResolutionContext($agent)));
        $this->assertDomainMessage('Agent pricing profile configuration is not active.', fn (): mixed => $service->resolve($this->request('agt.resolve.stable.fresh', $agent, 'agt-stable', $offering['id']), new AgentPricingResolutionContext($agent)));
        DB::table('agent_profiles')->where('user_id', $agent)->update(['pricing_profile_code' => 'different-profile']);
        $after = $service->resolve($request, new AgentPricingResolutionContext($agent));
        self::assertTrue($after->replayed);
        self::assertSame($accepted->resolutionId, $after->resolutionId);
        self::assertSame(777_000, $after->overridePriceIrr);
    }

    public function test_management_is_permissioned_and_integer_irr_validation_fails_closed(): void
    {
        $sales = $this->administrator(false, 'sales_content');
        $support = $this->administrator(false, 'support');
        $service = $this->app->make(AgentPricingService::class);
        $created = $service->createProfile('agt.manage.profile.1', 'agt-managed', new AgentPricingProfileDefinition(AgentPricingState::Active, true), $this->context($sales, 'manage-profile-1'));
        self::assertSame(1, $created->version);
        $revised = $service->reviseProfile('agt.manage.profile.2', 'agt-managed', new AgentPricingProfileDefinition(AgentPricingState::Active, false), $this->context($sales, 'manage-profile-2'));
        self::assertSame(2, $revised->version);
        $this->assertAuthorizationDenied(fn (): mixed => $service->createProfile('agt.manage.denied.1', 'agt-denied', new AgentPricingProfileDefinition(AgentPricingState::Active, true), $this->context($support, 'manage-denied')));
        $this->assertInvalidArgument(fn (): AgentPricingRuleDefinition => new AgentPricingRuleDefinition(AgentPricingState::Active, -1));
        self::assertSame(1, DB::table('permissions')->where('code', 'agents.pricing.manage')->count());
        self::assertSame(0, DB::table('permissions')->where('code', 'agents.pricing.resolve')->count());
    }

    public function test_mariadb_enforces_foreign_keys_uniqueness_checks_hashes_and_immutability(): void
    {
        $owner = $this->administrator(true);
        $offering = $this->offering(1_000_000);
        $service = $this->app->make(AgentPricingService::class);
        $profile = $this->createProfile($service, $owner, 'agt-db', true);
        $rule = $service->createRule('agt.db.rule.1', 'agt-db', 'db-rule', new AgentPricingRuleDefinition(AgentPricingState::Active, 800_000, planOfferingId: $offering['id']), $this->context($owner, 'db-rule'));
        $agent = $this->agent('agt-db');
        $resolution = $service->resolve($this->request('agt.resolve.db', $agent, 'agt-db', $offering['id']), new AgentPricingResolutionContext($agent));
        self::assertFalse(Schema::hasColumn('agent_pricing_resolutions', 'resolved_by_administrator_id'));
        $this->assertQueryRejected(static fn (): int => DB::table('agent_pricing_profiles')->where('id', $profile->profileId)->update(['profile_code' => 'forged']));
        $this->assertQueryRejected(static fn (): int => DB::table('agent_pricing_rule_versions')->where('id', $rule->versionId)->delete());
        $this->assertQueryRejected(static fn (): int => DB::table('agent_pricing_resolutions')->where('id', $resolution->resolutionId)->update(['override_price_irr' => 1]));
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_profiles')->insert(['public_id' => (string) Str::ulid(), 'profile_code' => 'agt-db', 'created_at' => now('UTC')]));
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_rules')->insert(['public_id' => (string) Str::ulid(), 'agent_pricing_profile_id' => 999999999, 'rule_code' => 'invalid-fk', 'created_at' => now('UTC')]));
        $stored = DB::table('agent_pricing_profile_versions')->where('id', $profile->versionId)->first(); self::assertNotNull($stored);
        $invalid = (array) $stored; unset($invalid['id']); $invalid['mutation_key'] = 'agt.db.invalid.check'; $invalid['mutation_payload_hash'] = hash('sha256', 'invalid-check'); $invalid['version'] = 2; $invalid['state'] = 'invalid';
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_profile_versions')->insert($invalid));
        $invalid['mutation_key'] = 'agt.db.invalid.hash'; $invalid['state'] = 'active'; $invalid['configuration_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_profile_versions')->insert($invalid));
        $storedRule = DB::table('agent_pricing_rule_versions')->where('id', $rule->versionId)->first(); self::assertNotNull($storedRule);
        $invalidRule = (array) $storedRule; unset($invalidRule['id']); $invalidRule['mutation_key'] = 'agt.db.invalid.amount'; $invalidRule['mutation_payload_hash'] = hash('sha256', 'invalid-amount'); $invalidRule['version'] = 2; $invalidRule['override_price_irr'] = -1;
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_rule_versions')->insert($invalidRule));
        $storedResolution = DB::table('agent_pricing_resolutions')->where('id', $resolution->resolutionId)->first(); self::assertNotNull($storedResolution);
        $invalidResolution = (array) $storedResolution; unset($invalidResolution['id']); $invalidResolution['public_id'] = (string) Str::ulid(); $invalidResolution['resolution_key'] = 'agt.resolve.db.invalid-hash'; $invalidResolution['request_payload_hash'] = hash('sha256', 'invalid-resolution-hash'); $invalidResolution['configuration_snapshot_hash'] = str_repeat('0', 64);
        $this->assertQueryRejected(static fn (): bool => DB::table('agent_pricing_resolutions')->insert($invalidResolution));
        self::assertSame(1, DB::table('agent_pricing_profiles')->where('profile_code', 'agt-db')->count());
        self::assertSame(1, DB::table('agent_pricing_profile_versions')->where('agent_pricing_profile_id', $profile->profileId)->count());
        self::assertSame(1, DB::table('agent_pricing_rule_versions')->where('agent_pricing_rule_id', $rule->ruleId)->count());
        self::assertSame(1, DB::table('agent_pricing_resolutions')->where('resolution_key', 'agt.resolve.db')->count());
    }
}
