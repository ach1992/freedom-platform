<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Wallet\Application\Contracts\PurchaseWalletRefundAuthority;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final readonly class AdministratorWalletOperationsService
{
    private const REFUND_PERMISSION = 'refunds.approve';

    private const CORRECTION_PERMISSION = 'wallet.corrections.create';

    public function __construct(
        private DatabaseManager $database,
        private AdministratorUserPermissionAuthorizer $authorizer,
        private PurchaseWalletRefundAuthority $purchaseRefunds,
        private WalletCorrectionService $corrections,
        private Clock $clock,
    ) {}

    public function capabilities(int $actorUserId): AdministratorWalletCapabilities
    {
        return new AdministratorWalletCapabilities(
            $this->authorizer->allowsUser($actorUserId, self::REFUND_PERMISSION),
            $this->authorizer->allowsUser($actorUserId, self::CORRECTION_PERMISSION),
        );
    }

    public function availableFor(int $actorUserId): bool
    {
        return $this->capabilities($actorUserId)->available();
    }

    /** @return list<AdministratorWalletRefundCandidate> */
    public function refundablePurchases(
        int $actorUserId,
        string $customerPublicId,
        int $limit = 8,
    ): array {
        $this->authorizer->authorizeUser($actorUserId, self::REFUND_PERMISSION);
        [$customerUserId] = $this->customerWallet($customerPublicId);
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('Administrator wallet refund list limit is invalid.');
        }

        $result = [];
        foreach ($this->purchaseRefunds->refundablePurchases($customerUserId, $limit) as $candidate) {
            $result[] = new AdministratorWalletRefundCandidate(
                $this->refundSelectionToken(
                    $actorUserId,
                    $customerPublicId,
                    $candidate->purchaseSettlementPublicId,
                ),
                $candidate->remainingIrr,
                $candidate->settledAt,
            );
        }

        return $result;
    }

    public function refundFullRemaining(
        int $actorUserId,
        string $customerPublicId,
        string $selectionToken,
        string $reason,
        string $operationKey,
    ): PurchaseWalletRefundExecutionReceipt {
        $administratorId = $this->authorizer->authorizeUser($actorUserId, self::REFUND_PERMISSION);
        [$customerUserId] = $this->customerWallet($customerPublicId);
        $reason = $this->reason($reason);
        $this->operationKey($operationKey);

        $selected = null;
        foreach ($this->purchaseRefunds->refundablePurchases($customerUserId, 20) as $candidate) {
            if (hash_equals(
                $this->refundSelectionToken(
                    $actorUserId,
                    $customerPublicId,
                    $candidate->purchaseSettlementPublicId,
                ),
                $selectionToken,
            )) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === null) {
            throw new DomainException('Selected wallet purchase is no longer refundable.');
        }

        $fingerprint = 'tg-admin-wallet-refund:'.substr(hash('sha256', $operationKey), 0, 40);
        $correlationId = 'tg-admin-wallet-refund:'.substr(hash('sha256', $operationKey.':correlation'), 0, 40);
        $refundKey = 'telegram.admin.wallet.refund:'.$operationKey;

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $refundKey,
            $customerUserId,
            $selected,
            $correlationId,
            $administratorId,
            $customerPublicId,
            $reason,
            $fingerprint,
        ): PurchaseWalletRefundExecutionReceipt {
            $receipt = $this->purchaseRefunds->refund(
                $refundKey,
                $customerUserId,
                $selected->purchaseSettlementPublicId,
                $selected->remainingIrr,
                $correlationId,
            );

            $connection->table('audit_logs')->insertOrIgnore([
                'actor_type' => 'administrator',
                'actor_id' => (string) $administratorId,
                'action' => 'wallet.purchase_refund.admin_executed',
                'target_type' => 'purchase_refund',
                'target_id' => $receipt->refundPublicId,
                'before_safe_data' => json_encode([
                    'customer_account_public_id' => $customerPublicId,
                    'purchase_settlement_public_id' => $receipt->purchaseSettlementPublicId,
                    'remaining_refundable_irr' => $selected->remainingIrr,
                ], JSON_THROW_ON_ERROR),
                'after_safe_data' => json_encode([
                    'refund_public_id' => $receipt->refundPublicId,
                    'amount_irr' => $receipt->amountIrr,
                    'cumulative_refunded_irr' => $receipt->cumulativeRefundedIrr,
                    'resulting_payment_state' => $receipt->resultingPaymentState,
                ], JSON_THROW_ON_ERROR),
                'reason_code' => 'telegram_admin_wallet_refund',
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'request_fingerprint' => $fingerprint,
                'created_at' => $this->timestamp(),
            ]);

            return $receipt;
        }, 3);
    }

    public function previewCorrection(
        int $actorUserId,
        string $customerPublicId,
        WalletCorrectionDirection $direction,
        int $amountIrr,
        string $reason,
        string $operationKey,
    ): AdministratorWalletCorrectionPreview {
        $administratorId = $this->authorizer->authorizeUser($actorUserId, self::CORRECTION_PERMISSION);
        [$customerUserId, $walletAccountId] = $this->customerWallet($customerPublicId);
        if ($amountIrr < 1) {
            throw new DomainException('Wallet correction amount must be positive.');
        }
        $reason = $this->reason($reason);
        $this->operationKey($operationKey);

        $preview = $this->corrections->preview(
            'telegram.admin.wallet.correction:'.$operationKey,
            $customerUserId,
            $walletAccountId,
            $direction,
            IrrMoney::positive($amountIrr),
            'Telegram administrator wallet correction',
            $this->context(
                $administratorId,
                $operationKey,
                'preview',
                'telegram_admin_wallet_correction',
                $reason,
            ),
            null,
            null,
        );

        $approvalId = null;
        if ($preview->approvalRequired) {
            $approval = $this->corrections->requestApproval(
                $preview->previewId,
                $this->context(
                    $administratorId,
                    $operationKey,
                    'approval',
                    'telegram_admin_wallet_correction',
                    $reason,
                ),
            );
            $approvalId = $approval->approvalId;
        }

        return new AdministratorWalletCorrectionPreview(
            $preview->previewId,
            $preview->direction,
            $preview->amount->amount,
            $preview->ledgerBalance->amount,
            $preview->availableBalance->amount,
            $preview->resultingLedgerBalance->amount,
            $preview->resultingAvailableBalance->amount,
            $preview->approvalRequired,
            $preview->confirmationToken,
            $approvalId,
            $preview->replayed,
        );
    }

    public function executeCorrection(
        int $actorUserId,
        string $customerPublicId,
        int $previewId,
        string $confirmationToken,
        ?string $approvalId,
        string $operationKey,
    ): WalletCorrectionReceipt {
        $administratorId = $this->authorizer->authorizeUser($actorUserId, self::CORRECTION_PERMISSION);
        [$customerUserId, $walletAccountId] = $this->customerWallet($customerPublicId);
        $this->operationKey($operationKey);

        $preview = $this->database->connection()->table('wallet_correction_previews')
            ->where('id', $previewId)
            ->first([
                'owner_user_id',
                'ledger_account_id',
                'requested_by_administrator_id',
                'reason',
                'confirmation_token',
            ]);
        if ($preview === null
            || (int) $preview->owner_user_id !== $customerUserId
            || (int) $preview->ledger_account_id !== $walletAccountId
            || (int) $preview->requested_by_administrator_id !== $administratorId
            || ! is_string($preview->reason)
            || ! is_string($preview->confirmation_token)
            || ! hash_equals($preview->confirmation_token, $confirmationToken)) {
            throw new AuthorizationException('Wallet correction preview is not bound to the selected administrator/customer context.');
        }

        return $this->corrections->execute(
            $previewId,
            $confirmationToken,
            $approvalId,
            $this->context(
                $administratorId,
                $operationKey,
                'execute',
                'telegram_admin_wallet_correction',
                $preview->reason,
            ),
        );
    }

    /** @return array{0:int,1:int} */
    private function customerWallet(string $customerPublicId): array
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $customerPublicId) !== 1) {
            throw new AuthorizationException('Customer wallet target is unavailable.');
        }

        $user = $this->database->connection()->table('users')
            ->where('public_id', $customerPublicId)
            ->where('account_type', 'customer')
            ->where('account_status', '<>', 'deleted')
            ->first(['id']);
        if ($user === null || (int) $user->id < 1) {
            throw new AuthorizationException('Customer wallet target is unavailable.');
        }

        $wallet = $this->database->connection()->table('ledger_accounts')
            ->where('owner_user_id', (int) $user->id)
            ->where('wallet_bucket', 'cash')
            ->where('currency', 'IRR')
            ->where('is_active', true)
            ->first(['id']);
        if ($wallet === null || (int) $wallet->id < 1) {
            throw new DomainException('Customer cash wallet is unavailable.');
        }

        return [(int) $user->id, (int) $wallet->id];
    }

    private function refundSelectionToken(
        int $actorUserId,
        string $customerPublicId,
        string $settlementPublicId,
    ): string {
        return substr(hash(
            'sha256',
            implode("\0", [
                'telegram-admin-wallet-refund-v1',
                (string) $actorUserId,
                $customerPublicId,
                $settlementPublicId,
            ]),
        ), 0, 40);
    }

    private function context(
        int $administratorId,
        string $operationKey,
        string $surface,
        string $reasonCode,
        string $reason,
    ): AccessChangeContext {
        return new AccessChangeContext(
            'tg-admin-wallet-'.$surface.':'.substr(hash('sha256', $operationKey), 0, 40),
            'tg-admin-wallet:'.substr(hash('sha256', $operationKey.':'.$surface), 0, 48),
            $reasonCode,
            $reason,
            $administratorId,
        );
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === ''
            || mb_strlen($reason) > 1000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reason) === 1) {
            throw new InvalidArgumentException('Administrator wallet operation reason is invalid.');
        }

        return $reason;
    }

    private function operationKey(string $operationKey): void
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $operationKey) !== 1) {
            throw new InvalidArgumentException('Administrator wallet operation identity is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
