<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeIssueRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeRedemptionRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement PRO-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
final class BenefitCodeLookupKeyRotationTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->useLookupV1();
    }

    public function test_v1_code_redeems_and_exact_replays_after_rotation_while_new_issuance_uses_v2(): void
    {
        $this->benefitCampaign('benefit.rotate.lifecycle', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 145_000), 'rotate-lifecycle');
        $issuedV1 = $this->benefitIssue('benefit.rotate.lifecycle', 'rotate-v1');
        $codeV1 = (string) $issuedV1->items[0]->fullCode;
        self::assertSame(1, (int) DB::table('benefit_codes')->where('public_id', $issuedV1->items[0]->codePublicId)->value('key_version'));

        $userId = $this->benefitUser();
        $walletId = $this->benefitPromotionalWallet($userId);
        $this->rotateToV2KeepingV1();

        $request = new BenefitCodeRedemptionRequest(
            'rotation-redemption-key-0001',
            $codeV1,
            $userId,
            null,
            $walletId,
            'rotation-redemption-correlation-01',
        );
        $service = $this->app->make(BenefitCodeService::class);
        $created = $service->redeem($request, new BenefitCodeRedemptionContext($userId));
        self::assertFalse($created->replayed);
        self::assertNotNull($created->ledgerTransactionId);

        $replay = $service->redeem($request, new BenefitCodeRedemptionContext($userId));
        self::assertTrue($replay->replayed);
        self::assertSame($created->redemptionId, $replay->redemptionId);
        self::assertSame($created->ledgerTransactionId, $replay->ledgerTransactionId);

        $issuedV2 = $this->benefitIssue('benefit.rotate.lifecycle', 'rotate-v2');
        self::assertSame(2, (int) DB::table('benefit_codes')->where('public_id', $issuedV2->items[0]->codePublicId)->value('key_version'));
    }

    public function test_owner_chosen_replay_is_rotation_stable_and_same_plaintext_cannot_be_reissued_across_supported_versions(): void
    {
        $this->benefitCampaign('benefit.rotate.owner', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'rotate-owner');
        $chosen = 'ABCD-2345-EFGH-6789-JKLM-2345';
        $service = $this->app->make(BenefitCodeService::class);
        $request = new BenefitCodeIssueRequest(
            'rotation-owner-issuance-0001',
            new BenefitCodeCampaignCode('benefit.rotate.owner'),
            1,
            [$chosen],
        );
        $created = $service->issue($request, $this->benefitContext($this->benefitOwner(), 'rotate-owner-create'));
        self::assertFalse($created->replayed);
        self::assertSame(1, (int) DB::table('benefit_codes')->where('public_id', $created->items[0]->codePublicId)->value('key_version'));

        $this->rotateToV2KeepingV1();
        $replay = $service->issue($request, $this->benefitContext($this->benefitOwner(), 'rotate-owner-replay'));
        self::assertTrue($replay->replayed);
        self::assertSame($created->issuanceId, $replay->issuanceId);
        self::assertNull($replay->items[0]->fullCode);

        $this->assertException(
            fn () => $service->issue(
                new BenefitCodeIssueRequest(
                    'rotation-owner-issuance-0002',
                    new BenefitCodeCampaignCode('benefit.rotate.owner'),
                    1,
                    [strtolower(str_replace('-', ' ', $chosen))],
                ),
                $this->benefitContext($this->benefitOwner(), 'rotate-owner-duplicate'),
            ),
            DomainException::class,
        );
        self::assertSame(1, DB::table('benefit_codes')->count());
    }

    public function test_app_key_rotation_does_not_change_lookup_identity_or_redemption(): void
    {
        $this->benefitCampaign('benefit.rotate.app-key', BenefitCodeType::WalletCredit, $this->walletDefinition(amountIrr: 155_000), 'rotate-app-key');
        $issued = $this->benefitIssue('benefit.rotate.app-key', 'rotate-app-key-issue');
        $code = (string) $issued->items[0]->fullCode;
        $storedHash = (string) DB::table('benefit_codes')->where('public_id', $issued->items[0]->codePublicId)->value('lookup_hash');

        config()->set('app.key', 'base64:THIS-IS-A-DIFFERENT-TEST-ONLY-APPLICATION-KEY');
        $userId = $this->benefitUser();
        $walletId = $this->benefitPromotionalWallet($userId);
        $receipt = $this->app->make(BenefitCodeService::class)->redeem(
            new BenefitCodeRedemptionRequest('rotation-app-key-redemption-01', $code, $userId, null, $walletId, 'rotation-app-key-correlation'),
            new BenefitCodeRedemptionContext($userId),
        );

        self::assertFalse($receipt->replayed);
        self::assertSame($storedHash, (string) DB::table('benefit_codes')->where('public_id', $issued->items[0]->codePublicId)->value('lookup_hash'));
    }

    public function test_unsupported_or_missing_historical_key_fails_closed_without_secret_or_plaintext_query_leakage(): void
    {
        $observedBindings = [];
        DB::listen(static function (QueryExecuted $query) use (&$observedBindings): void {
            foreach ($query->bindings as $binding) {
                if (is_string($binding)) {
                    $observedBindings[] = $binding;
                }
            }
        });

        $this->benefitCampaign('benefit.rotate.unsupported', BenefitCodeType::WalletCredit, $this->walletDefinition(), 'rotate-unsupported');
        $issued = $this->benefitIssue('benefit.rotate.unsupported', 'rotate-unsupported-issue');
        $code = (string) $issued->items[0]->fullCode;
        $userId = $this->benefitUser();
        $walletId = $this->benefitPromotionalWallet($userId);

        $this->useLookupV2WithoutPrevious();
        $this->assertException(
            fn () => $this->app->make(BenefitCodeService::class)->redeem(
                new BenefitCodeRedemptionRequest('rotation-unsupported-redemption', $code, $userId, null, $walletId, 'rotation-unsupported-corr'),
                new BenefitCodeRedemptionContext($userId),
            ),
            DomainException::class,
        );

        config()->set('benefit_codes.lookup.previous.version', 1);
        config()->set('benefit_codes.lookup.previous.key', '');
        $this->assertException(
            fn () => $this->app->make(BenefitCodeService::class)->redeem(
                new BenefitCodeRedemptionRequest('rotation-missing-key-redemption', $code, $userId, null, $walletId, 'rotation-missing-key-corr'),
                new BenefitCodeRedemptionContext($userId),
            ),
            RuntimeException::class,
        );

        $normalized = str_replace('-', '', $code);
        self::assertNotContains($code, $observedBindings);
        self::assertNotContains($normalized, $observedBindings);
        self::assertNotContains($this->v1Key(), $observedBindings);
        self::assertNotContains($this->v2Key(), $observedBindings);
    }

    private function useLookupV1(): void
    {
        config()->set('benefit_codes.lookup.current.version', 1);
        config()->set('benefit_codes.lookup.current.key', $this->v1Key());
        config()->set('benefit_codes.lookup.previous.version', null);
        config()->set('benefit_codes.lookup.previous.key', null);
    }

    private function rotateToV2KeepingV1(): void
    {
        config()->set('benefit_codes.lookup.current.version', 2);
        config()->set('benefit_codes.lookup.current.key', $this->v2Key());
        config()->set('benefit_codes.lookup.previous.version', 1);
        config()->set('benefit_codes.lookup.previous.key', $this->v1Key());
    }

    private function useLookupV2WithoutPrevious(): void
    {
        config()->set('benefit_codes.lookup.current.version', 2);
        config()->set('benefit_codes.lookup.current.key', $this->v2Key());
        config()->set('benefit_codes.lookup.previous.version', null);
        config()->set('benefit_codes.lookup.previous.key', null);
    }

    private function v1Key(): string
    {
        return str_repeat('benefit-v1-test-only-', 2);
    }

    private function v2Key(): string
    {
        return str_repeat('benefit-v2-test-only-', 2);
    }
}
