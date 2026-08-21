<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Orders\Application\QuoteService;
use App\Modules\Payments\Application\PurchaseWalletPaymentService;
use App\Modules\Payments\Eligibility\Application\PaymentMethodEligibilityService;
use App\Shared\Application\Clock;
use Illuminate\Database\DatabaseManager;

/** @requirement SVC-007 BUY-002 PAY-002 WAL-002 PRV-002 PRV-003 DAT-002 DAT-003 DAT-004 RUN-004 QUA-004 */
final readonly class ServiceAutoRenewalProcessor
{
    use ServiceAutoRenewalBatchOperations;
    use ServiceAutoRenewalCommercialOperations;
    use ServiceAutoRenewalRemoteOperations;
    use ServiceAutoRenewalPersistence;
    use ServiceAutoRenewalStateOperations;
    use ServiceAutoRenewalSupport;

    private const INSUFFICIENT_WALLET_MESSAGE = 'Wallet available balance is insufficient for this hold.';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private QuoteService $quotes,
        private PaymentMethodEligibilityService $eligibility,
        private PurchaseWalletPaymentService $walletPayments,
        private ServicePurchaseMutationQueueService $mutationQueue,
        private ProvisioningPanelAdapterResolver $panelAdapters,
        private ServiceAutoRenewPricePolicy $pricePolicy,
    ) {}
}
