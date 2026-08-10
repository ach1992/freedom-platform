<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeIssueRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeAudience;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Shared\Application\Clock;
use App\Shared\Application\RandomGenerator;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

final class MutableBenefitCodeClock implements Clock
{
    public function __construct(public DateTimeImmutable $value) {}

    public function now(): DateTimeImmutable
    {
        return $this->value;
    }
}

final class SequenceBenefitRandomGenerator implements RandomGenerator
{
    /** @param list<string> $sequences */
    public function __construct(private array $sequences, public int $calls = 0) {}

    public function bytes(int $length): string
    {
        $value = $this->sequences[$this->calls] ?? throw new RuntimeException('Benefit-code test random sequence exhausted.');
        $this->calls++;
        if (strlen($value) !== $length) {
            throw new RuntimeException('Benefit-code test random sequence has unexpected length.');
        }

        return $value;
    }

    public function integer(int $minimum, int $maximum): int
    {
        return $minimum;
    }
}

/** @requirement PRO-002 WAL-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
final class BenefitCodeLifecycleTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    private MutableBenefitCodeClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->clock = new MutableBenefitCodeClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_random_single_and_batch_issue_expose_plaintext_once_and_store_only_keyed_lookup_material(): void
    {
        $this->benefitCampaign('benefit.random', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'random');
        $observedBindings = [];
        DB::listen(static function (QueryExecuted $query) use (&$observedBindings): void {
            foreach ($query->bindings as $binding) {
                if (is_string($binding)) {
                    $observedBindings[] = $binding;
                }
            }
        });

        $service = $this->app->make(BenefitCodeService::class);
        $single = $this->benefitIssue('benefit.random', 'single');
        self::assertCount(1, $single->items);
        self::assertTrue($single->oneTimeCodesAvailable());
        self::assertNotNull($single->items[0]->fullCode);
        self::assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){5}\z/', $single->items[0]->fullCode);

        $batch = $this->benefitIssue('benefit.random', 'batch', 4);
        self::assertCount(4, $batch->items);
        $fullCodes = array_map(static fn ($item): string => (string) $item->fullCode, $batch->items);
        self::assertCount(4, array_unique($fullCodes));
        foreach (array_merge([(string) $single->items[0]->fullCode], $fullCodes) as $fullCode) {
            self::assertNotContains($fullCode, $observedBindings);
            self::assertNotContains(str_replace('-', '', $fullCode), $observedBindings);
        }

        $columns = Schema::getColumnListing('benefit_codes');
        foreach (['code', 'plaintext_code', 'encrypted_code', 'recoverable_code'] as $forbidden) {
            self::assertNotContains($forbidden, $columns);
        }
        $stored = DB::table('benefit_codes')->orderBy('id')->get(['lookup_hash', 'display_mask', 'key_version']);
        self::assertCount(5, $stored);
        foreach ($stored as $row) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', (string) $row->lookup_hash);
            self::assertMatchesRegularExpression('/\A[A-HJ-NP-Z2-9]{4}-\*{4}-[A-HJ-NP-Z2-9]{4}\z/', (string) $row->display_mask);
            self::assertSame(1, (int) $row->key_version);
        }

        $replay = $service->issue(
            new BenefitCodeIssueRequest('benefit-issue-'.substr(hash('sha256', 'single'), 0, 40), new BenefitCodeCampaignCode('benefit.random'), 1),
            $this->benefitContext($this->benefitOwner(), 'issue-single-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($single->issuanceId, $replay->issuanceId);
        self::assertFalse($replay->oneTimeCodesAvailable());
        self::assertNull($replay->items[0]->fullCode);
    }

    public function test_random_generation_retries_a_lookup_hash_collision_without_reusing_the_colliding_code(): void
    {
        $random = new SequenceBenefitRandomGenerator([
            str_repeat(chr(0), 24),
            str_repeat(chr(0), 24),
            str_repeat(chr(1), 24),
        ]);
        $this->app->instance(RandomGenerator::class, $random);
        $this->benefitCampaign('benefit.random.collision', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'random-collision');

        $first = $this->benefitIssue('benefit.random.collision', 'random-collision-first');
        $second = $this->benefitIssue('benefit.random.collision', 'random-collision-second');

        self::assertNotNull($first->items[0]->fullCode);
        self::assertNotNull($second->items[0]->fullCode);
        self::assertNotSame($first->items[0]->fullCode, $second->items[0]->fullCode);
        self::assertSame(3, $random->calls);
        self::assertSame(2, DB::table('benefit_codes')->count());
        self::assertSame(2, DB::table('benefit_codes')->distinct()->count('lookup_hash'));
    }

    public function test_owner_chosen_normalization_strength_collision_and_disable_are_fail_closed(): void
    {
        $this->benefitCampaign('benefit.owner', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'owner');
        $service = $this->app->make(BenefitCodeService::class);
        $chosen = implode('', ['ABCD', '2345', 'EFGH', '6789', 'JKLM', '2345']);
        $display = strtolower(substr($chosen, 0, 4).'-'.substr($chosen, 4, 4).' '.substr($chosen, 8, 4).'-'.substr($chosen, 12, 4).'-'.substr($chosen, 16, 4).'-'.substr($chosen, 20, 4));
        $issued = $service->issue(
            new BenefitCodeIssueRequest('owner-chosen-issuance-0001', new BenefitCodeCampaignCode('benefit.owner'), 1, [$display]),
            $this->benefitContext($this->benefitOwner(), 'owner-chosen'),
        );
        self::assertSame(substr($chosen, 0, 4).'-'.substr($chosen, 4, 4).'-'.substr($chosen, 8, 4).'-'.substr($chosen, 12, 4).'-'.substr($chosen, 16, 4).'-'.substr($chosen, 20, 4), $issued->items[0]->fullCode);

        $this->assertException(
            fn () => $service->issue(
                new BenefitCodeIssueRequest('owner-chosen-issuance-0002', new BenefitCodeCampaignCode('benefit.owner'), 1, [$display]),
                $this->benefitContext($this->benefitOwner(), 'owner-collision'),
            ),
            DomainException::class,
        );
        self::assertSame(1, DB::table('benefit_codes')->count());
    }

    public function test_management_authorization_and_owner_chosen_strength_are_enforced_before_persistence(): void
    {
        $service = $this->app->make(BenefitCodeService::class);
        $denied = $this->nonOwnerAdministrator();
        $this->assertException(
            fn () => $service->create(
                'denied-campaign-mutation-0001',
                new BenefitCodeCampaignCode('benefit.denied'),
                BenefitCodeType::WalletCredit,
                $this->walletDefinition(),
                $this->benefitContext($denied, 'denied'),
            ),
            AuthorizationException::class,
        );
        self::assertFalse(DB::table('benefit_code_campaigns')->where('campaign_code', 'benefit.denied')->exists());

        $owner = $this->benefitOwner();
        $definition = $this->walletDefinition(amountIrr: 130_000);
        $created = $service->create(
            'management-replay-mutation-0001',
            new BenefitCodeCampaignCode('benefit.management'),
            BenefitCodeType::WalletCredit,
            $definition,
            $this->benefitContext($owner, 'management-create'),
        );
        $replay = $service->create(
            'management-replay-mutation-0001',
            new BenefitCodeCampaignCode('benefit.management'),
            BenefitCodeType::WalletCredit,
            $definition,
            $this->benefitContext($owner, 'management-replay'),
        );
        self::assertTrue($replay->replayed);
        self::assertSame($created->campaignId, $replay->campaignId);
        $this->assertException(
            fn () => $service->create(
                'management-replay-mutation-0001',
                new BenefitCodeCampaignCode('benefit.management'),
                BenefitCodeType::WalletCredit,
                $this->walletDefinition(amountIrr: 130_001),
                $this->benefitContext($owner, 'management-conflict'),
            ),
            RuntimeException::class,
        );

        $this->benefitCampaign('benefit.strength', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'strength');
        $this->assertException(
            fn () => $service->issue(
                new BenefitCodeIssueRequest('weak-owner-issuance-0001', new BenefitCodeCampaignCode('benefit.strength'), 1, [str_repeat('A', 24)]),
                $this->benefitContext($this->benefitOwner(), 'weak'),
            ),
            InvalidArgumentException::class,
        );
        self::assertSame(0, DB::table('benefit_codes')->count());
        self::assertTrue(DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('r.code', 'sales_content')->where('p.code', 'promotions.benefit_codes.manage')->exists());
    }

    public function test_wallet_credit_is_exactly_once_balanced_promotional_only_and_replay_is_stable_after_disable_revision(): void
    {
        $this->benefitCampaign('benefit.wallet', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 175_000), 'wallet');
        $issued = $this->benefitIssue('benefit.wallet', 'wallet', 2);
        $code = (string) $issued->items[0]->fullCode;
        $blockedAfterDisableCode = (string) $issued->items[1]->fullCode;
        $userId = $this->benefitUser();
        $promotionalAccount = $this->benefitPromotionalWallet($userId);
        $cashAccount = $this->benefitCashWallet($userId);
        $service = $this->app->make(BenefitCodeService::class);
        $request = new BenefitCodeRedemptionRequest('wallet-redemption-00000001', $code, $userId, null, $promotionalAccount, 'wallet-correlation-0001');

        $created = $service->redeem($request, new BenefitCodeRedemptionContext($userId));
        self::assertFalse($created->replayed);
        self::assertSame(BenefitCodeType::WalletCredit, $created->type);
        self::assertNotNull($created->ledgerTransactionId);
        self::assertNull($created->entitlementPublicId);
        self::assertNull($created->discountGrantPublicId);
        $transaction = DB::table('ledger_transactions')->where('id', $created->ledgerTransactionId)->first();
        self::assertNotNull($transaction);
        self::assertSame('benefit_code_promotional_credit', $transaction->transaction_type);
        self::assertSame(175_000, (int) $transaction->expected_total_irr);
        self::assertSame(175_000, (int) $transaction->posted_debit_irr);
        self::assertSame(175_000, (int) $transaction->posted_credit_irr);
        self::assertSame(2, (int) $transaction->entry_count);
        self::assertNotNull($transaction->finalized_at);
        self::assertSame(1, DB::table('ledger_entries')->where('ledger_transaction_id', $created->ledgerTransactionId)->where('ledger_account_id', $promotionalAccount)->where('direction', 'credit')->where('amount_irr', 175_000)->count());
        self::assertSame(0, DB::table('ledger_entries')->where('ledger_transaction_id', $created->ledgerTransactionId)->where('ledger_account_id', $cashAccount)->count());

        $replay = $service->redeem($request, new BenefitCodeRedemptionContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($created->redemptionId, $replay->redemptionId);
        self::assertSame($created->ledgerTransactionId, $replay->ledgerTransactionId);
        self::assertSame(1, DB::table('benefit_code_redemptions')->count());
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());

        $service->revise(
            'wallet-campaign-disable-0001',
            new BenefitCodeCampaignCode('benefit.wallet'),
            $this->walletDefinition(amountIrr: 999_000, state: BenefitCodeState::Disabled),
            $this->benefitContext($this->benefitOwner(), 'wallet-disable'),
        );
        $historicalReplay = $service->redeem($request, new BenefitCodeRedemptionContext($userId));
        self::assertTrue($historicalReplay->replayed);
        self::assertSame(175_000, (int) DB::table('ledger_transactions')->where('id', $historicalReplay->ledgerTransactionId)->value('expected_total_irr'));
        $this->assertException(
            fn () => $service->redeem(
                new BenefitCodeRedemptionRequest('wallet-after-disable-0001', $blockedAfterDisableCode, $userId, null, $promotionalAccount, 'wallet-correlation-0002'),
                new BenefitCodeRedemptionContext($userId),
            ),
            DomainException::class,
        );
        self::assertSame(1, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());
    }

    public function test_redemption_actor_audience_window_scope_and_usage_limits_fail_closed(): void
    {
        $offering = $this->benefitOffering('policy');
        $definition = $this->walletDefinition(
            audience: BenefitCodeAudience::Customers,
            singleUse: false,
            totalUseLimit: 2,
            perUserUseLimit: 1,
            effectiveFrom: $this->clock->value->modify('-5 minutes'),
            effectiveUntil: $this->clock->value->modify('+30 minutes'),
            offeringId: $offering['id'],
            productId: $offering['product_id'],
            serverId: $offering['server_id'],
        );
        $this->benefitCampaign('benefit.policy', BenefitCodeType::WalletCredit, $definition, 'policy');
        $issued = $this->benefitIssue('benefit.policy', 'policy');
        $code = (string) $issued->items[0]->fullCode;
        $service = $this->app->make(BenefitCodeService::class);
        $customer = $this->benefitUser('customer');
        $agent = $this->benefitUser('agent');
        $other = $this->benefitUser('customer');
        $customerWallet = $this->benefitPromotionalWallet($customer);
        $agentWallet = $this->benefitPromotionalWallet($agent);
        $otherWallet = $this->benefitPromotionalWallet($other);

        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('policy-cross-user-0001', $code, $customer, $offering['id'], $customerWallet, 'policy-correlation-001'), new BenefitCodeRedemptionContext($other)),
            AuthorizationException::class,
        );
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('policy-agent-denied-0001', $code, $agent, $offering['id'], $agentWallet, 'policy-correlation-002'), new BenefitCodeRedemptionContext($agent)),
            AuthorizationException::class,
        );
        $wrongOffering = $this->benefitOffering('policy-wrong');
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('policy-scope-denied-0001', $code, $customer, $wrongOffering['id'], $customerWallet, 'policy-correlation-003'), new BenefitCodeRedemptionContext($customer)),
            DomainException::class,
        );

        $first = $service->redeem(new BenefitCodeRedemptionRequest('policy-first-use-000001', $code, $customer, $offering['id'], $customerWallet, 'policy-correlation-004'), new BenefitCodeRedemptionContext($customer));
        self::assertFalse($first->replayed);
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('policy-user-limit-00001', $code, $customer, $offering['id'], $customerWallet, 'policy-correlation-005'), new BenefitCodeRedemptionContext($customer)),
            DomainException::class,
        );
        $second = $service->redeem(new BenefitCodeRedemptionRequest('policy-second-use-00001', $code, $other, $offering['id'], $otherWallet, 'policy-correlation-006'), new BenefitCodeRedemptionContext($other));
        self::assertFalse($second->replayed);
        $thirdUser = $this->benefitUser();
        $thirdWallet = $this->benefitPromotionalWallet($thirdUser);
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('policy-total-limit-00001', $code, $thirdUser, $offering['id'], $thirdWallet, 'policy-correlation-007'), new BenefitCodeRedemptionContext($thirdUser)),
            DomainException::class,
        );
        self::assertSame(2, DB::table('benefit_code_redemptions')->count());
    }

    public function test_not_yet_effective_expired_disabled_and_conflicting_replay_fail_closed_without_effect(): void
    {
        $future = $this->walletDefinition(effectiveFrom: $this->clock->value->modify('+1 hour'), effectiveUntil: $this->clock->value->modify('+2 hours'));
        $this->benefitCampaign('benefit.future', BenefitCodeType::WalletCredit, $future, 'future');
        $futureIssue = $this->benefitIssue('benefit.future', 'future');
        $user = $this->benefitUser();
        $wallet = $this->benefitPromotionalWallet($user);
        $service = $this->app->make(BenefitCodeService::class);
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('future-redemption-00001', (string) $futureIssue->items[0]->fullCode, $user, null, $wallet, 'future-correlation-001'), new BenefitCodeRedemptionContext($user)),
            DomainException::class,
        );

        $this->clock->value = $this->clock->value->modify('+3 hours');
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('expired-redemption-0001', (string) $futureIssue->items[0]->fullCode, $user, null, $wallet, 'future-correlation-002'), new BenefitCodeRedemptionContext($user)),
            DomainException::class,
        );

        $this->clock->value = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->benefitCampaign('benefit.disabled', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'disabled');
        $disabledIssue = $this->benefitIssue('benefit.disabled', 'disabled');
        $disable = $service->disableCode('disable-code-mutation-0001', $disabledIssue->items[0]->codePublicId, $this->benefitContext($this->benefitOwner(), 'disable-code'));
        self::assertFalse($disable->replayed);
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('disabled-redemption-0001', (string) $disabledIssue->items[0]->fullCode, $user, null, $wallet, 'disable-correlation-001'), new BenefitCodeRedemptionContext($user)),
            DomainException::class,
        );

        $this->benefitCampaign('benefit.conflict', BenefitCodeType::WalletCredit, $this->walletDefinition(singleUse: false, totalUseLimit: 2, perUserUseLimit: 2), 'conflict');
        $conflictIssue = $this->benefitIssue('benefit.conflict', 'conflict');
        $created = $service->redeem(new BenefitCodeRedemptionRequest('conflict-redemption-0001', (string) $conflictIssue->items[0]->fullCode, $user, null, $wallet, 'conflict-correlation-01'), new BenefitCodeRedemptionContext($user));
        self::assertFalse($created->replayed);
        $otherWallet = $this->benefitPromotionalWallet($this->benefitUser());
        $this->assertException(
            fn () => $service->redeem(new BenefitCodeRedemptionRequest('conflict-redemption-0001', (string) $conflictIssue->items[0]->fullCode, $user, null, $otherWallet, 'conflict-correlation-02'), new BenefitCodeRedemptionContext($user)),
            RuntimeException::class,
        );
        self::assertSame(1, DB::table('benefit_code_redemptions')->where('redemption_key', 'conflict-redemption-0001')->count());
    }

    public function test_free_service_and_discount_grant_create_only_immutable_future_consumption_records(): void
    {
        $offering = $this->benefitOffering('bounded');
        $service = $this->app->make(BenefitCodeService::class);
        $user = $this->benefitUser();

        $this->benefitCampaign('benefit.free', BenefitCodeType::FreeService, $this->freeServiceDefinition($offering['id'], $offering['product_id'], $offering['server_id']), 'free');
        $freeIssue = $this->benefitIssue('benefit.free', 'free');
        $free = $service->redeem(new BenefitCodeRedemptionRequest('free-redemption-00000001', (string) $freeIssue->items[0]->fullCode, $user, $offering['id'], null, 'free-correlation-0001'), new BenefitCodeRedemptionContext($user));
        self::assertNotNull($free->entitlementPublicId);
        self::assertNull($free->ledgerTransactionId);
        self::assertSame(1, DB::table('benefit_code_free_service_entitlements')->count());
        self::assertSame(0, DB::table('ledger_transactions')->where('transaction_type', 'benefit_code_promotional_credit')->count());

        $rule = $this->usageRule($offering['id'], 'benefit.discount.rule', 90_000);
        $this->benefitCampaign('benefit.discount', BenefitCodeType::DiscountGrant, $this->discountDefinition($rule->ruleCode, $offering['id'], $offering['product_id'], $offering['server_id']), 'discount');
        $discountIssue = $this->benefitIssue('benefit.discount', 'discount');
        $discount = $service->redeem(new BenefitCodeRedemptionRequest('discount-redemption-00001', (string) $discountIssue->items[0]->fullCode, $user, $offering['id'], null, 'discount-correlation-001'), new BenefitCodeRedemptionContext($user));
        self::assertNotNull($discount->discountGrantPublicId);
        self::assertNull($discount->ledgerTransactionId);
        self::assertSame(1, DB::table('benefit_code_discount_grants')->count());
        self::assertSame($rule->ruleId, (int) DB::table('benefit_code_discount_grants')->value('pricing_rule_id'));
        self::assertSame($rule->versionId, (int) DB::table('benefit_code_discount_grants')->value('pricing_rule_version_id'));
        self::assertSame(0, DB::table('pricing_rule_resolutions')->count());
        self::assertSame(0, DB::table('quotes')->count());

        foreach (['benefit_code_free_service_entitlements', 'benefit_code_discount_grants'] as $table) {
            $id = (int) DB::table($table)->value('id');
            $this->assertException(fn () => DB::table($table)->where('id', $id)->update(['created_at' => now('UTC')]), QueryException::class);
            $this->assertException(fn () => DB::table($table)->where('id', $id)->delete(), QueryException::class);
        }
    }

    public function test_database_guards_reject_forged_hash_identity_update_and_delete(): void
    {
        $this->benefitCampaign('benefit.dbguard', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'dbguard');
        $issued = $this->benefitIssue('benefit.dbguard', 'dbguard');
        $campaignVersionId = (int) DB::table('benefit_code_campaign_versions')->value('id');
        $codeId = (int) DB::table('benefit_codes')->value('id');

        $this->assertException(fn () => DB::table('benefit_code_campaign_versions')->where('id', $campaignVersionId)->update(['configuration_hash' => str_repeat('0', 64)]), QueryException::class);
        $this->assertException(fn () => DB::table('benefit_codes')->where('id', $codeId)->update(['display_mask' => 'ABCD-****-2345']), QueryException::class);
        $this->assertException(fn () => DB::table('benefit_codes')->where('id', $codeId)->delete(), QueryException::class);

        $user = $this->benefitUser();
        $wallet = $this->benefitPromotionalWallet($user);
        $this->app->make(BenefitCodeService::class)->redeem(new BenefitCodeRedemptionRequest('dbguard-redemption-0001', (string) $issued->items[0]->fullCode, $user, null, $wallet, 'dbguard-correlation-01'), new BenefitCodeRedemptionContext($user));
        $redemptionId = (int) DB::table('benefit_code_redemptions')->value('id');
        $this->assertException(fn () => DB::table('benefit_code_redemptions')->where('id', $redemptionId)->update(['configuration_snapshot_hash' => str_repeat('0', 64)]), QueryException::class);
        $this->assertException(fn () => DB::table('benefit_code_redemptions')->where('id', $redemptionId)->delete(), QueryException::class);
    }

    /** @param class-string<\Throwable> $exceptionClass */
    private function assertException(callable $callback, string $exceptionClass): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            self::assertInstanceOf($exceptionClass, $exception);

            return;
        }

        self::fail('Expected exception of type '.$exceptionClass.' was not thrown.');
    }
}
