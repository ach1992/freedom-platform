<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeCampaignCode;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeDefinition;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Promotions\BenefitCodes\Infrastructure\BenefitCodeCodec;
use App\Modules\Promotions\BenefitCodes\Infrastructure\HmacBenefitCodeLookupHasher;
use App\Modules\Wallet\Application\LedgerPostingService;
use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;

final readonly class BenefitCodeService
{
    use BenefitCodeIssuanceSupport;
    use BenefitCodeManagementSupport;
    use BenefitCodePersistenceSupport;
    use BenefitCodeRedemptionSupport;

    private const MANAGE_PERMISSION = 'promotions.benefit_codes.manage';

    private const PROMOTIONAL_FUNDING_ACCOUNT_CODE = 'system.benefit-code.promotional-funding';

    private const LEDGER_TRANSACTION_TYPE = 'benefit_code_promotional_credit';

    private const SNAPSHOT_VERSION = 'pro-002-benefit-code-v1';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private AdministratorPermissionAuthorizer $authorizer,
        private BenefitCodeCodec $codec,
        private HmacBenefitCodeLookupHasher $hasher,
        private LedgerPostingService $ledger,
    ) {}

    /** @requirement PRO-002 WAL-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function create(
        string $mutationKey,
        BenefitCodeCampaignCode $campaignCode,
        BenefitCodeType $type,
        BenefitCodeDefinition $definition,
        AccessChangeContext $context,
    ): BenefitCodeCampaignVersionReceipt {
        return $this->writeCampaign('create', $mutationKey, $campaignCode, $type, $definition, $context);
    }

    /** @requirement PRO-002 WAL-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function revise(
        string $mutationKey,
        BenefitCodeCampaignCode $campaignCode,
        BenefitCodeDefinition $definition,
        AccessChangeContext $context,
    ): BenefitCodeCampaignVersionReceipt {
        $typeValue = $this->database->connection()->table('benefit_code_campaigns')->where('campaign_code', $campaignCode->value)->value('type');
        $type = is_string($typeValue) ? BenefitCodeType::tryFrom($typeValue) : null;
        if ($type === null) {
            throw new \DomainException('Benefit code campaign does not exist or has invalid identity.');
        }

        return $this->writeCampaign('revise', $mutationKey, $campaignCode, $type, $definition, $context);
    }

    /** @requirement PRO-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function issue(BenefitCodeIssueRequest $request, AccessChangeContext $context): BenefitCodeIssueReceipt
    {
        return $this->issueCodes($request, $context);
    }

    /** @requirement PRO-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function disableCode(string $mutationKey, string $codePublicId, AccessChangeContext $context): BenefitCodeDisableReceipt
    {
        return $this->disableStoredCode($mutationKey, $codePublicId, $context);
    }

    /** @requirement PRO-002 WAL-002 DAT-002 DAT-003 DAT-004 SEC-001 SEC-002 SEC-003 SEC-008 QUA-001 */
    public function redeem(BenefitCodeRedemptionRequest $request, BenefitCodeRedemptionContext $context): BenefitCodeRedemptionReceipt
    {
        return $this->redeemCode($request, $context);
    }
}
