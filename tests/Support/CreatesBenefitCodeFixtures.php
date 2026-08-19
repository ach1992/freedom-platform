<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeCampaignVersionReceipt;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeIssueReceipt;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeIssueRequest;
use App\Modules\Promotions\BenefitCodes\Application\BenefitCodeService;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeAudience;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeDefinition;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait CreatesBenefitCodeFixtures
{
    use CreatesPromotionUsageFixtures;

    protected function benefitOwner(): int
    {
        return $this->usageAdministrator();
    }

    protected function benefitUser(string $accountType = 'customer'): int
    {
        return $this->usageUser($accountType);
    }

    /** @return array{id:int,product_id:int,server_id:int} */
    protected function benefitOffering(string $suffix = 'benefit'): array
    {
        return $this->usageOffering(suffix: $suffix);
    }

    /** @return array{id:int,product_id:int,server_id:int} */
    protected function activeBenefitOffering(string $suffix = 'benefit'): array
    {
        $offering = $this->benefitOffering($suffix);
        $updated = DB::table('plan_offerings')
            ->where('id', $offering['id'])
            ->where('state', 'draft')
            ->update([
                'state' => 'active',
                'updated_at' => now('UTC'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Benefit test Plan Offering could not be activated.');
        }

        return $offering;
    }

    protected function benefitContext(int $administratorId, string $suffix): AccessChangeContext
    {
        return new AccessChangeContext(
            'benefit-request-'.substr(hash('sha256', $suffix), 0, 40),
            'benefit-correlation-'.substr(hash('sha256', $suffix), 0, 32),
            'benefit_code_test',
            'Benefit code lifecycle test mutation.',
            $administratorId,
        );
    }

    protected function benefitCampaign(
        string $code,
        BenefitCodeType $type,
        BenefitCodeDefinition $definition,
        string $suffix,
    ): BenefitCodeCampaignVersionReceipt {
        $this->ensureBenefitFundingAccountFixture();

        return $this->app->make(BenefitCodeService::class)->create(
            'benefit-campaign-'.substr(hash('sha256', $suffix), 0, 40),
            new BenefitCodeCampaignCode($code),
            $type,
            $definition,
            $this->benefitContext($this->benefitOwner(), 'campaign-'.$suffix),
        );
    }

    protected function benefitIssue(string $campaignCode, string $suffix, int $quantity = 1): BenefitCodeIssueReceipt
    {
        return $this->app->make(BenefitCodeService::class)->issue(
            new BenefitCodeIssueRequest(
                'benefit-issue-'.substr(hash('sha256', $suffix), 0, 40),
                new BenefitCodeCampaignCode($campaignCode),
                $quantity,
            ),
            $this->benefitContext($this->benefitOwner(), 'issue-'.$suffix),
        );
    }

    protected function walletDefinition(
        int $amountIrr = 125_000,
        BenefitCodeAudience $audience = BenefitCodeAudience::Both,
        bool $singleUse = true,
        ?int $totalUseLimit = 1,
        ?int $perUserUseLimit = 1,
        ?DateTimeImmutable $effectiveFrom = null,
        ?DateTimeImmutable $effectiveUntil = null,
        ?int $offeringId = null,
        ?int $productId = null,
        ?int $serverId = null,
        BenefitCodeState $state = BenefitCodeState::Active,
    ): BenefitCodeDefinition {
        return new BenefitCodeDefinition(
            $state,
            $audience,
            $singleUse,
            $totalUseLimit,
            $perUserUseLimit,
            $effectiveFrom,
            $effectiveUntil,
            $offeringId,
            $productId,
            $serverId,
            $amountIrr,
            null,
        );
    }

    protected function freeServiceDefinition(
        int $offeringId,
        ?int $productId = null,
        ?int $serverId = null,
        BenefitCodeAudience $audience = BenefitCodeAudience::Both,
        bool $singleUse = true,
        ?int $totalUseLimit = 1,
        ?int $perUserUseLimit = 1,
        BenefitCodeState $state = BenefitCodeState::Active,
    ): BenefitCodeDefinition {
        return new BenefitCodeDefinition(
            $state,
            $audience,
            $singleUse,
            $totalUseLimit,
            $perUserUseLimit,
            null,
            null,
            $offeringId,
            $productId,
            $serverId,
            null,
            null,
        );
    }

    protected function discountDefinition(
        string $ruleCode,
        ?int $offeringId = null,
        ?int $productId = null,
        ?int $serverId = null,
        BenefitCodeAudience $audience = BenefitCodeAudience::Both,
        bool $singleUse = true,
        ?int $totalUseLimit = 1,
        ?int $perUserUseLimit = 1,
        BenefitCodeState $state = BenefitCodeState::Active,
    ): BenefitCodeDefinition {
        return new BenefitCodeDefinition(
            $state,
            $audience,
            $singleUse,
            $totalUseLimit,
            $perUserUseLimit,
            null,
            null,
            $offeringId,
            $productId,
            $serverId,
            null,
            $ruleCode,
        );
    }

    protected function benefitPromotionalWallet(int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.user.'.$userId.'.promotional',
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'promotional',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function benefitCashWallet(int $userId): int
    {
        $now = now('UTC');

        return (int) DB::table('ledger_accounts')->insertGetId([
            'code' => 'wallet.user.'.$userId.'.cash',
            'account_class' => 'liability',
            'owner_user_id' => $userId,
            'wallet_bucket' => 'cash',
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function nonOwnerAdministrator(): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => $this->benefitUser(),
            'status' => 'active',
            'is_owner' => false,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureBenefitFundingAccountFixture(): void
    {
        if (DB::table('ledger_accounts')->where('code', 'system.benefit-code.promotional-funding')->exists()) {
            return;
        }

        $now = now('UTC');
        DB::table('ledger_accounts')->insert([
            'code' => 'system.benefit-code.promotional-funding',
            'account_class' => 'equity',
            'owner_user_id' => null,
            'wallet_bucket' => null,
            'currency' => 'IRR',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
