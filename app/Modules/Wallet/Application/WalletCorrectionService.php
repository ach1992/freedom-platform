<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Application;

use App\Modules\AccessControl\Application\AccessChangeContext;
use App\Modules\AccessControl\Application\AdministratorPermissionAuthorizer;
use App\Modules\AccessControl\Application\SensitiveActionApprovalService;
use App\Modules\AccessControl\Application\SensitiveApprovalReceipt;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use App\Modules\Wallet\Domain\WalletCorrectionDirection;
use App\Modules\Wallet\Domain\WalletHoldStatus;
use App\Modules\Wallet\Domain\WalletSystemAccountCode;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * @phpstan-type PreviewRow object{
 *     id:int|string,
 *     correction_key:string,
 *     payload_hash:string,
 *     owner_user_id:int|string,
 *     ledger_account_id:int|string,
 *     wallet_bucket:string,
 *     direction:string,
 *     amount_irr:int|string,
 *     requested_by_administrator_id:int|string,
 *     reason_code:string,
 *     reason:string,
 *     note:string,
 *     related_type:string|null,
 *     related_id:string|null,
 *     preview_ledger_balance_irr:int|string,
 *     preview_active_holds_irr:int|string,
 *     preview_available_balance_irr:int|string,
 *     preview_resulting_ledger_balance_irr:int|string,
 *     preview_resulting_available_balance_irr:int|string,
 *     approval_required:int|bool,
 *     confirmation_token:string
 * }
 * @phpstan-type CorrectionRow object{
 *     id:int|string,
 *     preview_id:int|string,
 *     correction_key:string,
 *     payload_hash:string,
 *     requested_by_administrator_id:int|string,
 *     approval_id:string|null,
 *     ledger_transaction_id:int|string,
 *     executed_ledger_balance_before_irr:int|string,
 *     executed_active_holds_irr:int|string,
 *     executed_available_before_irr:int|string,
 *     executed_ledger_balance_after_irr:int|string,
 *     executed_available_after_irr:int|string
 * }
 */
final readonly class WalletCorrectionService
{
    private const CREATE_PERMISSION = 'wallet.corrections.create';

    private const LARGE_PERMISSION = 'wallet.corrections.large';

    private const APPROVAL_ACTION = 'wallet.correction.execute';

    private const APPROVAL_TARGET_TYPE = 'wallet_correction_preview';

    private const LEDGER_TRANSACTION_TYPE = 'wallet_correction';

    public function __construct(
        private DatabaseManager $database,
        private Clock $clock,
        private ConfigRepository $config,
        private AdministratorPermissionAuthorizer $authorizer,
        private SensitiveActionApprovalService $approvals,
        private LedgerPostingService $ledger,
    ) {}

    /**
     * @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001
     */
    public function preview(
        string $correctionKey,
        int $ownerUserId,
        int $ledgerAccountId,
        WalletCorrectionDirection $direction,
        IrrMoney $amount,
        string $note,
        AccessChangeContext $context,
        ?string $relatedType = null,
        ?string $relatedId = null,
    ): WalletCorrectionPreviewReceipt {
        $this->assertToken($correctionKey, 'Wallet correction key', 8, 128);
        if ($ownerUserId < 1 || $ledgerAccountId < 1) {
            throw new DomainException('Wallet correction target is invalid.');
        }
        if ($amount->isZero()) {
            throw new DomainException('Wallet correction amount must be positive.');
        }
        $reason = $context->requireReason();
        $note = trim($note);
        $this->assertPrintable($note, 'Wallet correction note', 1, 2000);
        [$relatedType, $relatedId] = $this->normalizeRelatedReference($relatedType, $relatedId);
        $this->authorizer->authorize($context->actorAdministratorId, self::CREATE_PERMISSION);

        $payloadHash = $this->payloadHash(
            $ownerUserId,
            $ledgerAccountId,
            $direction,
            $amount,
            $context->actorAdministratorId,
            $context->reasonCode,
            $reason,
            $note,
            $relatedType,
            $relatedId,
        );

        try {
            return $this->database->connection()->transaction(function (Connection $connection) use (
                $correctionKey,
                $ownerUserId,
                $ledgerAccountId,
                $direction,
                $amount,
                $note,
                $context,
                $relatedType,
                $relatedId,
                $reason,
                $payloadHash,
            ): WalletCorrectionPreviewReceipt {
                $existing = $this->previewByKey($connection, $correctionKey, true);
                if ($existing !== null) {
                    return $this->previewReceipt($existing, $payloadHash, true);
                }

                $walletBucket = $this->lockWalletAccount($connection, $ownerUserId, $ledgerAccountId);
                [$ledgerBalance, $activeHolds, $availableBalance] = $this->balanceLocked($connection, $ledgerAccountId);
                if ($direction === WalletCorrectionDirection::Debit && $amount->amount > $availableBalance->amount) {
                    throw new DomainException('Wallet correction debit exceeds current available balance.');
                }

                if ($direction === WalletCorrectionDirection::Credit) {
                    $resultingLedger = $ledgerBalance->add($amount);
                    $resultingAvailable = $availableBalance->add($amount);
                } else {
                    $resultingLedger = $ledgerBalance->subtract($amount);
                    $resultingAvailable = $availableBalance->subtract($amount);
                }

                $approvalRequired = $this->approvalRequired(
                    $connection,
                    $context->actorAdministratorId,
                    $amount->amount,
                );
                if ($approvalRequired) {
                    $this->authorizer->authorize($context->actorAdministratorId, self::LARGE_PERMISSION);
                }

                $confirmationToken = $this->confirmationToken(
                    $correctionKey,
                    $payloadHash,
                    $ledgerBalance->amount,
                    $activeHolds->amount,
                    $availableBalance->amount,
                    $resultingLedger->amount,
                    $resultingAvailable->amount,
                    $approvalRequired,
                );
                $createdAt = $this->timestamp();
                $previewId = (int) $connection->table('wallet_correction_previews')->insertGetId([
                    'correction_key' => $correctionKey,
                    'payload_hash' => $payloadHash,
                    'owner_user_id' => $ownerUserId,
                    'ledger_account_id' => $ledgerAccountId,
                    'wallet_bucket' => $walletBucket,
                    'direction' => $direction->value,
                    'amount_irr' => $amount->amount,
                    'requested_by_administrator_id' => $context->actorAdministratorId,
                    'reason_code' => $context->reasonCode,
                    'reason' => $reason,
                    'note' => $note,
                    'related_type' => $relatedType,
                    'related_id' => $relatedId,
                    'preview_ledger_balance_irr' => $ledgerBalance->amount,
                    'preview_active_holds_irr' => $activeHolds->amount,
                    'preview_available_balance_irr' => $availableBalance->amount,
                    'preview_resulting_ledger_balance_irr' => $resultingLedger->amount,
                    'preview_resulting_available_balance_irr' => $resultingAvailable->amount,
                    'approval_required' => $approvalRequired,
                    'confirmation_token' => $confirmationToken,
                    'created_at' => $createdAt,
                ]);

                $this->recordPreviewAudit(
                    $connection,
                    $previewId,
                    $ownerUserId,
                    $ledgerAccountId,
                    $walletBucket,
                    $direction,
                    $amount,
                    $ledgerBalance,
                    $activeHolds,
                    $availableBalance,
                    $resultingLedger,
                    $resultingAvailable,
                    $approvalRequired,
                    $relatedType,
                    $relatedId,
                    $context,
                    $reason,
                );

                return new WalletCorrectionPreviewReceipt(
                    $previewId,
                    $ownerUserId,
                    $ledgerAccountId,
                    $walletBucket,
                    $direction,
                    $amount,
                    $ledgerBalance,
                    $activeHolds,
                    $availableBalance,
                    $resultingLedger,
                    $resultingAvailable,
                    $approvalRequired,
                    $confirmationToken,
                    false,
                );
            });
        } catch (QueryException $exception) {
            $existing = $this->previewByKey($this->database->connection(), $correctionKey);
            if ($existing !== null) {
                return $this->previewReceipt($existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @requirement WAL-005 ACL-001 ACL-002 QUA-001 */
    public function requestApproval(int $previewId, AccessChangeContext $context): SensitiveApprovalReceipt
    {
        if ($previewId < 1) {
            throw new DomainException('Wallet correction preview ID must be positive.');
        }
        $context->requireReason();
        /** @var PreviewRow|null $preview */
        $preview = $this->database->connection()->table('wallet_correction_previews')
            ->where('id', $previewId)
            ->first($this->previewColumns());
        if ($preview === null) {
            throw new DomainException('Wallet correction preview does not exist.');
        }
        if ((int) $preview->requested_by_administrator_id !== $context->actorAdministratorId) {
            throw new AuthorizationException('Wallet correction approval request is bound to the preview requester.');
        }
        if (! (bool) $preview->approval_required) {
            throw new DomainException('Wallet correction preview does not require independent approval.');
        }

        return $this->approvals->request(
            self::LARGE_PERMISSION,
            self::APPROVAL_ACTION,
            self::APPROVAL_TARGET_TYPE,
            (string) $previewId,
            true,
            $this->approvalTtlSeconds(),
            $context,
        );
    }

    /**
     * @requirement WAL-005 DAT-002 DAT-003 DAT-004 ACL-001 ACL-002 SEC-002 QUA-001
     */
    public function execute(
        int $previewId,
        string $confirmationToken,
        ?string $approvalId,
        AccessChangeContext $context,
    ): WalletCorrectionReceipt {
        if ($previewId < 1) {
            throw new DomainException('Wallet correction preview ID must be positive.');
        }
        $this->assertToken($confirmationToken, 'Wallet correction confirmation token', 64, 64);
        $reason = $context->requireReason();

        return $this->database->connection()->transaction(function (Connection $connection) use (
            $previewId,
            $confirmationToken,
            $approvalId,
            $context,
            $reason,
        ): WalletCorrectionReceipt {
            /** @var PreviewRow|null $preview */
            $preview = $connection->table('wallet_correction_previews')
                ->where('id', $previewId)
                ->lockForUpdate()
                ->first($this->previewColumns());
            if ($preview === null) {
                throw new DomainException('Wallet correction preview does not exist.');
            }
            if ((int) $preview->requested_by_administrator_id !== $context->actorAdministratorId) {
                throw new AuthorizationException('Wallet correction execution is bound to the preview requester.');
            }
            $this->authorizer->authorize($context->actorAdministratorId, self::CREATE_PERMISSION);
            if (! hash_equals($preview->confirmation_token, $confirmationToken)) {
                throw new RuntimeException('Wallet correction confirmation does not match the immutable preview.');
            }

            $existing = $this->correctionByPreview($connection, $previewId);
            if ($existing !== null) {
                $this->assertReplayApproval($preview, $existing, $approvalId);

                return $this->correctionReceipt($connection, $preview, $existing, true);
            }

            $ownerUserId = $this->positiveDatabaseInt($preview->owner_user_id, 'Wallet correction owner user ID');
            $ledgerAccountId = $this->positiveDatabaseInt($preview->ledger_account_id, 'Wallet correction ledger account ID');
            $offsetAccountId = $this->correctionOffsetAccountId($connection);
            $walletBucket = $this->lockCorrectionAccounts($connection, $ownerUserId, $ledgerAccountId, $offsetAccountId);
            if (! hash_equals($preview->wallet_bucket, $walletBucket)) {
                throw new RuntimeException('Wallet correction preview bucket no longer matches the target account.');
            }
            [$ledgerBalance, $activeHolds, $availableBalance] = $this->balanceLocked($connection, $ledgerAccountId);

            $existing = $this->correctionByPreview($connection, $previewId);
            if ($existing !== null) {
                $this->assertReplayApproval($preview, $existing, $approvalId);

                return $this->correctionReceipt($connection, $preview, $existing, true);
            }

            if ($ledgerBalance->amount !== (int) $preview->preview_ledger_balance_irr
                || $activeHolds->amount !== (int) $preview->preview_active_holds_irr
                || $availableBalance->amount !== (int) $preview->preview_available_balance_irr) {
                throw new RuntimeException('Wallet correction preview is stale; create a new correction preview.');
            }

            $direction = WalletCorrectionDirection::tryFrom($preview->direction);
            if ($direction === null) {
                throw new RuntimeException('Wallet correction preview direction is invalid.');
            }
            $amount = IrrMoney::positive($this->positiveDatabaseInt($preview->amount_irr, 'Wallet correction amount'));
            if ($direction === WalletCorrectionDirection::Debit && $amount->amount > $availableBalance->amount) {
                throw new DomainException('Wallet correction debit exceeds current available balance.');
            }

            $approvalRequired = (bool) $preview->approval_required;
            $currentApprovalRequired = $this->approvalRequired(
                $connection,
                $context->actorAdministratorId,
                $amount->amount,
            );
            if ($currentApprovalRequired !== $approvalRequired) {
                throw new RuntimeException('Wallet correction approval policy changed; create a new correction preview.');
            }

            $acceptedApprovalId = null;
            if ($approvalRequired) {
                if ($approvalId === null) {
                    throw new DomainException('Wallet correction requires independent approval.');
                }
                $this->assertToken($approvalId, 'Wallet correction approval ID', 26, 26);
                $this->authorizer->authorize($context->actorAdministratorId, self::LARGE_PERMISSION);
                $approval = $this->approvals->consume(
                    $approvalId,
                    self::APPROVAL_ACTION,
                    self::APPROVAL_TARGET_TYPE,
                    (string) $previewId,
                    $context,
                );
                if (! $approval->consumed) {
                    throw new RuntimeException('Wallet correction approval was not consumed.');
                }
                $acceptedApprovalId = $approval->approvalId;
            } elseif ($approvalId !== null) {
                throw new DomainException('Wallet correction preview does not accept an approval ID.');
            }

            $entries = $direction === WalletCorrectionDirection::Credit
                ? [
                    new LedgerEntryDraft($offsetAccountId, LedgerDirection::Debit, $amount),
                    new LedgerEntryDraft($ledgerAccountId, LedgerDirection::Credit, $amount),
                ]
                : [
                    new LedgerEntryDraft($ledgerAccountId, LedgerDirection::Debit, $amount),
                    new LedgerEntryDraft($offsetAccountId, LedgerDirection::Credit, $amount),
                ];

            $ledgerReceipt = $this->ledger->post(
                $this->ledgerCommandKey($preview->correction_key),
                self::LEDGER_TRANSACTION_TYPE,
                $context->correlationId,
                $entries,
                'wallet_correction',
                $preview->correction_key,
            );

            $resultingLedger = $direction === WalletCorrectionDirection::Credit
                ? $ledgerBalance->add($amount)
                : $ledgerBalance->subtract($amount);
            $resultingAvailable = $direction === WalletCorrectionDirection::Credit
                ? $availableBalance->add($amount)
                : $availableBalance->subtract($amount);
            if ($resultingLedger->amount !== (int) $preview->preview_resulting_ledger_balance_irr
                || $resultingAvailable->amount !== (int) $preview->preview_resulting_available_balance_irr) {
                throw new RuntimeException('Wallet correction execution no longer matches the confirmed preview.');
            }

            $correctionId = (int) $connection->table('wallet_corrections')->insertGetId([
                'preview_id' => $previewId,
                'correction_key' => $preview->correction_key,
                'payload_hash' => $preview->payload_hash,
                'requested_by_administrator_id' => $context->actorAdministratorId,
                'approval_id' => $acceptedApprovalId,
                'ledger_transaction_id' => $ledgerReceipt->transactionId,
                'executed_ledger_balance_before_irr' => $ledgerBalance->amount,
                'executed_active_holds_irr' => $activeHolds->amount,
                'executed_available_before_irr' => $availableBalance->amount,
                'executed_ledger_balance_after_irr' => $resultingLedger->amount,
                'executed_available_after_irr' => $resultingAvailable->amount,
                'created_at' => $this->timestamp(),
            ]);

            $this->recordExecutionAudit(
                $connection,
                $correctionId,
                $preview,
                $direction,
                $amount,
                $ledgerBalance,
                $activeHolds,
                $availableBalance,
                $resultingLedger,
                $resultingAvailable,
                $acceptedApprovalId,
                $ledgerReceipt->transactionId,
                $context,
                $reason,
            );

            return new WalletCorrectionReceipt(
                $correctionId,
                $previewId,
                $ownerUserId,
                $ledgerAccountId,
                $walletBucket,
                $direction,
                $amount,
                $ledgerBalance,
                $activeHolds,
                $availableBalance,
                $resultingLedger,
                $resultingAvailable,
                $acceptedApprovalId,
                $ledgerReceipt->transactionId,
                false,
            );
        });
    }

    /** @return array{0:string|null,1:string|null} */
    private function normalizeRelatedReference(?string $relatedType, ?string $relatedId): array
    {
        if ($relatedType === null && $relatedId === null) {
            return [null, null];
        }
        if ($relatedType === null || $relatedId === null) {
            throw new DomainException('Wallet correction related reference type and ID must be supplied together.');
        }
        if (! in_array($relatedType, ['ticket', 'order', 'payment'], true)) {
            throw new DomainException('Wallet correction related reference type is invalid.');
        }
        $this->assertToken($relatedId, 'Wallet correction related reference ID', 1, 191);

        return [$relatedType, $relatedId];
    }

    private function payloadHash(
        int $ownerUserId,
        int $ledgerAccountId,
        WalletCorrectionDirection $direction,
        IrrMoney $amount,
        int $actorAdministratorId,
        string $reasonCode,
        string $reason,
        string $note,
        ?string $relatedType,
        ?string $relatedId,
    ): string {
        return hash('sha256', json_encode([
            'owner_user_id' => $ownerUserId,
            'ledger_account_id' => $ledgerAccountId,
            'direction' => $direction->value,
            'amount_irr' => $amount->amount,
            'actor_administrator_id' => $actorAdministratorId,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'note' => $note,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
        ], JSON_THROW_ON_ERROR));
    }

    private function confirmationToken(
        string $correctionKey,
        string $payloadHash,
        int $ledgerBalance,
        int $activeHolds,
        int $availableBalance,
        int $resultingLedger,
        int $resultingAvailable,
        bool $approvalRequired,
    ): string {
        return hash('sha256', json_encode([
            'version' => 1,
            'correction_key' => $correctionKey,
            'payload_hash' => $payloadHash,
            'ledger_balance_irr' => $ledgerBalance,
            'active_holds_irr' => $activeHolds,
            'available_balance_irr' => $availableBalance,
            'resulting_ledger_balance_irr' => $resultingLedger,
            'resulting_available_balance_irr' => $resultingAvailable,
            'approval_required' => $approvalRequired,
        ], JSON_THROW_ON_ERROR));
    }

    private function approvalRequired(Connection $connection, int $administratorId, int $amountIrr): bool
    {
        /** @var object{status:string,is_owner:int|bool}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('id', $administratorId)
            ->lockForUpdate()
            ->first(['status', 'is_owner']);
        if ($administrator === null || $administrator->status !== 'active') {
            throw new AuthorizationException('Administrator authorization failed.');
        }
        if ((bool) $administrator->is_owner) {
            return false;
        }

        $threshold = $this->dualApprovalThreshold();

        return $threshold === 0 || $amountIrr >= $threshold;
    }

    private function dualApprovalThreshold(): int
    {
        $value = $this->config->get('wallet.corrections.dual_approval_threshold_irr', 0);
        if (! is_int($value) || $value < 0) {
            throw new RuntimeException('Wallet correction dual-approval threshold configuration is invalid.');
        }

        return $value;
    }

    private function approvalTtlSeconds(): int
    {
        $value = $this->config->get('wallet.corrections.approval_ttl_seconds', 600);
        if (! is_int($value) || $value < 60 || $value > 900) {
            throw new RuntimeException('Wallet correction approval TTL configuration is invalid.');
        }

        return $value;
    }

    private function lockWalletAccount(Connection $connection, int $ownerUserId, int $ledgerAccountId): string
    {
        /** @var object{account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool}|null $account */
        $account = $connection->table('ledger_accounts')
            ->where('id', $ledgerAccountId)
            ->lockForUpdate()
            ->first(['account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($account === null
            || $account->account_class !== 'liability'
            || (int) $account->owner_user_id !== $ownerUserId
            || $account->wallet_bucket === null
            || ! in_array($account->wallet_bucket, ['cash', 'promotional'], true)
            || $account->currency !== 'IRR'
            || ! (bool) $account->is_active) {
            throw new DomainException('Wallet correction target account is not an active owned IRR wallet.');
        }

        return $account->wallet_bucket;
    }

    private function lockCorrectionAccounts(
        Connection $connection,
        int $ownerUserId,
        int $ledgerAccountId,
        int $offsetAccountId,
    ): string {
        $accounts = LedgerAccountLockSet::acquire($connection, [$ledgerAccountId, $offsetAccountId]);

        $wallet = $accounts[$ledgerAccountId] ?? null;
        if ($wallet === null
            || $wallet->account_class !== 'liability'
            || $wallet->owner_user_id === null
            || (int) $wallet->owner_user_id !== $ownerUserId
            || $wallet->wallet_bucket === null
            || ! in_array($wallet->wallet_bucket, ['cash', 'promotional'], true)
            || $wallet->currency !== 'IRR'
            || ! (bool) $wallet->is_active) {
            throw new DomainException('Wallet correction target account is not an active owned IRR wallet.');
        }

        $offset = $accounts[$offsetAccountId] ?? null;
        if ($offset === null
            || $offset->account_class !== 'equity'
            || $offset->owner_user_id !== null
            || $offset->wallet_bucket !== null
            || $offset->currency !== 'IRR'
            || ! (bool) $offset->is_active) {
            throw new RuntimeException('Wallet correction offset account is unavailable or invalid.');
        }

        return $wallet->wallet_bucket;
    }

    /** @return array{0:IrrMoney,1:IrrMoney,2:IrrMoney} */
    private function balanceLocked(Connection $connection, int $ledgerAccountId): array
    {
        /** @var object{credits:int|string|null,debits:int|string|null}|null $totals */
        $totals = $connection->table('ledger_entries as entries')
            ->join('ledger_transactions as transactions', 'transactions.id', '=', 'entries.ledger_transaction_id')
            ->where('entries.ledger_account_id', $ledgerAccountId)
            ->whereNotNull('transactions.finalized_at')
            ->selectRaw("SUM(CASE WHEN entries.direction = 'credit' THEN entries.amount_irr ELSE 0 END) AS credits")
            ->selectRaw("SUM(CASE WHEN entries.direction = 'debit' THEN entries.amount_irr ELSE 0 END) AS debits")
            ->first();
        $credits = $this->nonNegativeDatabaseInt($totals?->credits, 'Wallet ledger credit total');
        $debits = $this->nonNegativeDatabaseInt($totals?->debits, 'Wallet ledger debit total');
        if ($debits > $credits) {
            throw new RuntimeException('Wallet ledger balance is negative and requires reconciliation.');
        }
        $ledgerBalance = IrrMoney::fromInt($credits - $debits);

        $activeHolds = IrrMoney::fromInt($this->nonNegativeDatabaseInt(
            $connection->table('wallet_holds')
                ->where('ledger_account_id', $ledgerAccountId)
                ->where('status', WalletHoldStatus::Active->value)
                ->sum('amount_irr'),
            'Wallet active hold total',
        ));
        if ($activeHolds->amount > $ledgerBalance->amount) {
            throw new RuntimeException('Wallet active holds exceed ledger balance and require reconciliation.');
        }

        return [$ledgerBalance, $activeHolds, $ledgerBalance->subtract($activeHolds)];
    }

    private function correctionOffsetAccountId(Connection $connection): int
    {
        $accountId = $connection->table('ledger_accounts')
            ->where('code', WalletSystemAccountCode::CORRECTION_OFFSET)
            ->value('id');
        if (! is_int($accountId) && ! is_string($accountId)) {
            throw new RuntimeException('Wallet correction offset account is unavailable or invalid.');
        }

        return $this->positiveDatabaseInt($accountId, 'Wallet correction offset account ID');
    }

    /** @return PreviewRow|null */
    private function previewByKey(Connection $connection, string $correctionKey, bool $lock = false): ?object
    {
        $query = $connection->table('wallet_correction_previews')->where('correction_key', $correctionKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var PreviewRow|null $row */
        $row = $query->first($this->previewColumns());

        return $row;
    }

    /** @return list<string> */
    private function previewColumns(): array
    {
        return [
            'id', 'correction_key', 'payload_hash', 'owner_user_id', 'ledger_account_id', 'wallet_bucket',
            'direction', 'amount_irr', 'requested_by_administrator_id', 'reason_code', 'reason', 'note',
            'related_type', 'related_id', 'preview_ledger_balance_irr', 'preview_active_holds_irr',
            'preview_available_balance_irr', 'preview_resulting_ledger_balance_irr',
            'preview_resulting_available_balance_irr', 'approval_required', 'confirmation_token',
        ];
    }

    /** @param PreviewRow $row */
    private function previewReceipt(object $row, string $payloadHash, bool $replayed): WalletCorrectionPreviewReceipt
    {
        if (! hash_equals($row->payload_hash, $payloadHash)) {
            throw new RuntimeException('Wallet correction key conflict.');
        }
        $direction = WalletCorrectionDirection::tryFrom($row->direction);
        if ($direction === null) {
            throw new RuntimeException('Stored wallet correction preview direction is invalid.');
        }

        return new WalletCorrectionPreviewReceipt(
            $this->positiveDatabaseInt($row->id, 'Wallet correction preview ID'),
            $this->positiveDatabaseInt($row->owner_user_id, 'Wallet correction owner user ID'),
            $this->positiveDatabaseInt($row->ledger_account_id, 'Wallet correction ledger account ID'),
            $row->wallet_bucket,
            $direction,
            IrrMoney::positive($this->positiveDatabaseInt($row->amount_irr, 'Wallet correction amount')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($row->preview_ledger_balance_irr, 'Wallet correction preview ledger balance')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($row->preview_active_holds_irr, 'Wallet correction preview active holds')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($row->preview_available_balance_irr, 'Wallet correction preview available balance')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($row->preview_resulting_ledger_balance_irr, 'Wallet correction preview resulting ledger balance')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($row->preview_resulting_available_balance_irr, 'Wallet correction preview resulting available balance')),
            (bool) $row->approval_required,
            $row->confirmation_token,
            $replayed,
        );
    }

    /** @return CorrectionRow|null */
    private function correctionByPreview(Connection $connection, int $previewId): ?object
    {
        /** @var CorrectionRow|null $row */
        $row = $connection->table('wallet_corrections')
            ->where('preview_id', $previewId)
            ->first([
                'id', 'preview_id', 'correction_key', 'payload_hash', 'requested_by_administrator_id',
                'approval_id', 'ledger_transaction_id', 'executed_ledger_balance_before_irr',
                'executed_active_holds_irr', 'executed_available_before_irr',
                'executed_ledger_balance_after_irr', 'executed_available_after_irr',
            ]);

        return $row;
    }

    /**
     * @param  PreviewRow  $preview
     * @param  CorrectionRow  $correction
     */
    private function assertReplayApproval(object $preview, object $correction, ?string $approvalId): void
    {
        $storedApprovalId = $correction->approval_id;
        if ((bool) $preview->approval_required) {
            if ($approvalId === null) {
                throw new DomainException('Wallet correction requires independent approval.');
            }
            $this->assertToken($approvalId, 'Wallet correction approval ID', 26, 26);
            if ($storedApprovalId === null || ! hash_equals($storedApprovalId, $approvalId)) {
                throw new RuntimeException('Wallet correction approval replay conflicts with the committed approval.');
            }

            return;
        }

        if ($approvalId !== null) {
            throw new DomainException('Wallet correction preview does not accept an approval ID.');
        }
        if ($storedApprovalId !== null) {
            throw new RuntimeException('Stored wallet correction approval state is invalid.');
        }
    }

    /**
     * @param  PreviewRow  $preview
     * @param  CorrectionRow  $correction
     */
    private function correctionReceipt(
        Connection $connection,
        object $preview,
        object $correction,
        bool $replayed,
    ): WalletCorrectionReceipt {
        if (! hash_equals($preview->correction_key, $correction->correction_key)
            || ! hash_equals($preview->payload_hash, $correction->payload_hash)
            || (int) $preview->requested_by_administrator_id !== (int) $correction->requested_by_administrator_id) {
            throw new RuntimeException('Stored wallet correction does not match its immutable preview.');
        }
        $direction = WalletCorrectionDirection::tryFrom($preview->direction);
        if ($direction === null) {
            throw new RuntimeException('Stored wallet correction direction is invalid.');
        }
        $amount = IrrMoney::positive($this->positiveDatabaseInt($preview->amount_irr, 'Wallet correction amount'));
        $ledgerTransactionId = $this->positiveDatabaseInt($correction->ledger_transaction_id, 'Wallet correction ledger transaction ID');
        /** @var object{transaction_type:string,source_type:string|null,source_id:string|null,expected_total_irr:int|string,posted_debit_irr:int|string,posted_credit_irr:int|string,entry_count:int|string,finalized_at:string|null}|null $ledgerTransaction */
        $ledgerTransaction = $connection->table('ledger_transactions')
            ->where('id', $ledgerTransactionId)
            ->first(['transaction_type', 'source_type', 'source_id', 'expected_total_irr', 'posted_debit_irr', 'posted_credit_irr', 'entry_count', 'finalized_at']);
        if ($ledgerTransaction === null
            || $ledgerTransaction->transaction_type !== self::LEDGER_TRANSACTION_TYPE
            || $ledgerTransaction->source_type !== 'wallet_correction'
            || $ledgerTransaction->source_id !== $preview->correction_key
            || $ledgerTransaction->finalized_at === null
            || (int) $ledgerTransaction->expected_total_irr !== $amount->amount
            || (int) $ledgerTransaction->posted_debit_irr !== $amount->amount
            || (int) $ledgerTransaction->posted_credit_irr !== $amount->amount
            || (int) $ledgerTransaction->entry_count !== 2) {
            throw new RuntimeException('Stored wallet correction ledger effect failed integrity verification.');
        }

        $approvalId = $correction->approval_id;
        if ((bool) $preview->approval_required !== ($approvalId !== null)) {
            throw new RuntimeException('Stored wallet correction approval state is invalid.');
        }

        return new WalletCorrectionReceipt(
            $this->positiveDatabaseInt($correction->id, 'Wallet correction ID'),
            $this->positiveDatabaseInt($correction->preview_id, 'Wallet correction preview ID'),
            $this->positiveDatabaseInt($preview->owner_user_id, 'Wallet correction owner user ID'),
            $this->positiveDatabaseInt($preview->ledger_account_id, 'Wallet correction ledger account ID'),
            $preview->wallet_bucket,
            $direction,
            $amount,
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($correction->executed_ledger_balance_before_irr, 'Wallet correction ledger balance before')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($correction->executed_active_holds_irr, 'Wallet correction active holds')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($correction->executed_available_before_irr, 'Wallet correction available balance before')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($correction->executed_ledger_balance_after_irr, 'Wallet correction ledger balance after')),
            IrrMoney::fromInt($this->nonNegativeDatabaseInt($correction->executed_available_after_irr, 'Wallet correction available balance after')),
            $approvalId,
            $ledgerTransactionId,
            $replayed,
        );
    }

    private function recordPreviewAudit(
        Connection $connection,
        int $previewId,
        int $ownerUserId,
        int $ledgerAccountId,
        string $walletBucket,
        WalletCorrectionDirection $direction,
        IrrMoney $amount,
        IrrMoney $ledgerBalance,
        IrrMoney $activeHolds,
        IrrMoney $availableBalance,
        IrrMoney $resultingLedger,
        IrrMoney $resultingAvailable,
        bool $approvalRequired,
        ?string $relatedType,
        ?string $relatedId,
        AccessChangeContext $context,
        string $reason,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => 'wallet.correction.preview',
            'target_type' => 'wallet_correction_preview',
            'target_id' => (string) $previewId,
            'before_safe_data' => null,
            'after_safe_data' => json_encode([
                'owner_user_id' => $ownerUserId,
                'ledger_account_id' => $ledgerAccountId,
                'wallet_bucket' => $walletBucket,
                'direction' => $direction->value,
                'amount_irr' => $amount->amount,
                'ledger_balance_irr' => $ledgerBalance->amount,
                'active_holds_irr' => $activeHolds->amount,
                'available_balance_irr' => $availableBalance->amount,
                'resulting_ledger_balance_irr' => $resultingLedger->amount,
                'resulting_available_balance_irr' => $resultingAvailable->amount,
                'approval_required' => $approvalRequired,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @param PreviewRow $preview */
    private function recordExecutionAudit(
        Connection $connection,
        int $correctionId,
        object $preview,
        WalletCorrectionDirection $direction,
        IrrMoney $amount,
        IrrMoney $ledgerBalance,
        IrrMoney $activeHolds,
        IrrMoney $availableBalance,
        IrrMoney $resultingLedger,
        IrrMoney $resultingAvailable,
        ?string $approvalId,
        int $ledgerTransactionId,
        AccessChangeContext $context,
        string $reason,
    ): void {
        $connection->table('audit_logs')->insert([
            'actor_type' => 'administrator',
            'actor_id' => (string) $context->actorAdministratorId,
            'action' => 'wallet.correction.execute',
            'target_type' => 'wallet_correction',
            'target_id' => (string) $correctionId,
            'before_safe_data' => json_encode([
                'ledger_balance_irr' => $ledgerBalance->amount,
                'active_holds_irr' => $activeHolds->amount,
                'available_balance_irr' => $availableBalance->amount,
            ], JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode([
                'preview_id' => (int) $preview->id,
                'owner_user_id' => (int) $preview->owner_user_id,
                'ledger_account_id' => (int) $preview->ledger_account_id,
                'wallet_bucket' => $preview->wallet_bucket,
                'direction' => $direction->value,
                'amount_irr' => $amount->amount,
                'resulting_ledger_balance_irr' => $resultingLedger->amount,
                'resulting_available_balance_irr' => $resultingAvailable->amount,
                'approval_required' => (bool) $preview->approval_required,
                'approval_id' => $approvalId,
                'related_type' => $preview->related_type,
                'related_id' => $preview->related_id,
                'ledger_transaction_id' => $ledgerTransactionId,
            ], JSON_THROW_ON_ERROR),
            'reason_code' => $context->reasonCode,
            'reason' => $reason,
            'correlation_id' => $context->correlationId,
            'request_fingerprint' => $context->requestFingerprint,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function ledgerCommandKey(string $correctionKey): string
    {
        return 'ledger.wallet-correction.'.hash('sha256', $correctionKey);
    }

    private function positiveDatabaseInt(int|string $value, string $label): int
    {
        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function nonNegativeDatabaseInt(int|float|string|null $value, string $label): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_float($value)) {
            if (! is_finite($value) || floor($value) !== $value) {
                throw new RuntimeException($label.' is invalid.');
            }
        } elseif (is_string($value) && preg_match('/\A[0-9]+\z/', $value) !== 1) {
            throw new RuntimeException($label.' is invalid.');
        }
        $integer = (int) $value;
        if ($integer < 0) {
            throw new RuntimeException($label.' is invalid.');
        }

        return $integer;
    }

    private function assertToken(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || preg_match('/\A[A-Za-z0-9._:-]+\z/', $value) !== 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function assertPrintable(string $value, string $label, int $minimum, int $maximum): void
    {
        $length = strlen($value);
        if ($length < $minimum || $length > $maximum || $value !== trim($value) || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label.' is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
