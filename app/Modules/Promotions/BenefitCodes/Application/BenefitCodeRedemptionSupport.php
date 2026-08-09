<?php

declare(strict_types=1);

namespace App\Modules\Promotions\BenefitCodes\Application;

use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeState;
use App\Modules\Promotions\BenefitCodes\Domain\BenefitCodeType;
use App\Modules\Wallet\Application\LedgerEntryDraft;
use App\Modules\Wallet\Domain\IrrMoney;
use App\Modules\Wallet\Domain\LedgerDirection;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

trait BenefitCodeRedemptionSupport
{
    private function redeemCode(BenefitCodeRedemptionRequest $request, BenefitCodeRedemptionContext $context): BenefitCodeRedemptionReceipt
    {
        if ($context->actorUserId !== $request->userId) {
            throw new AuthorizationException('Benefit code redemption actor does not own the subject.');
        }
        $normalized = $this->codec->normalize($request->code);
        $lookupHash = $this->hasher->hash($normalized);
        $payloadHash = $this->hash([
            'code_lookup_hash' => $lookupHash,
            'plan_offering_id' => $request->planOfferingId,
            'promotional_wallet_account_id' => $request->promotionalWalletAccountId,
            'user_id' => $request->userId,
        ]);
        $existing = $this->redemptionByKey($this->database->connection(), $request->redemptionKey);
        if ($existing !== null) {
            return $this->redemptionReceipt($this->database->connection(), $existing, $payloadHash, true);
        }

        try {
            return $this->database->connection()->transaction(function (Connection $db) use ($request, $lookupHash, $payloadHash): BenefitCodeRedemptionReceipt {
                $existing = $this->redemptionByKey($db, $request->redemptionKey, true);
                if ($existing !== null) {
                    return $this->redemptionReceipt($db, $existing, $payloadHash, true);
                }
                $code = $this->codeByHash($db, $lookupHash, true);
                if ($code === null || $this->positive($code->key_version, 'Benefit code key version') !== $this->hasher->keyVersion()) {
                    throw new DomainException('Benefit code is invalid.');
                }

                $existing = $this->redemptionByKey($db, $request->redemptionKey, true);
                if ($existing !== null) {
                    return $this->redemptionReceipt($db, $existing, $payloadHash, true);
                }

                $codeId = $this->positive($code->id, 'Benefit code ID');
                if ($db->table('benefit_code_disables')->where('benefit_code_id', $codeId)->exists()) {
                    throw new DomainException('Benefit code is disabled.');
                }
                /** @var object{account_type:string,account_status:string}|null $user */
                $user = $db->table('users')->where('id', $request->userId)->lockForUpdate()->first(['account_type', 'account_status']);
                if ($user === null || $user->account_status !== 'active' || ! in_array($user->account_type, ['customer', 'agent'], true)) {
                    throw new AuthorizationException('Benefit code redemption subject is not active.');
                }

                $latest = $this->latestCampaign($db, (string) $code->campaign_code, true);
                if ($latest === null || $latest->state !== BenefitCodeState::Active->value) {
                    throw new DomainException('Benefit code campaign is not active.');
                }
                $configuration = $this->decodeConfiguration($code->configuration_snapshot, $code->configuration_hash);
                if ($this->stateFromConfiguration($configuration) !== BenefitCodeState::Active) {
                    throw new DomainException('Issued benefit code configuration is not active.');
                }
                $audience = $this->audienceFromConfiguration($configuration);
                if (! $audience->allows($user->account_type)) {
                    throw new AuthorizationException('Benefit code audience does not allow this subject.');
                }
                $this->assertEffectiveWindow($configuration);
                $offering = $this->offeringContext($db, $request->planOfferingId);
                $this->assertScope($configuration, $offering);
                $this->assertRedemptionCapacity($db, $codeId, $request->userId, $configuration);

                $type = BenefitCodeType::tryFrom((string) $code->type) ?? throw new RuntimeException('Stored benefit code type is invalid.');
                $publicId = (string) Str::ulid();
                $ledgerTransactionId = null;
                if ($type === BenefitCodeType::WalletCredit) {
                    $ledgerTransactionId = $this->postPromotionalWalletCredit($db, $request, $configuration, $publicId);
                } elseif ($request->promotionalWalletAccountId !== null) {
                    throw new DomainException('Non-wallet benefit redemptions cannot specify a wallet account.');
                }

                $snapshot = [
                    'campaign' => [
                        'code' => (string) $code->campaign_code,
                        'configuration_hash' => (string) $code->configuration_hash,
                        'version' => $this->positive($code->version, 'Benefit code campaign version'),
                    ],
                    'code_public_id' => (string) $code->public_id,
                    'effect' => [
                        'ledger_transaction_id' => $ledgerTransactionId,
                        'plan_offering_id' => $request->planOfferingId,
                    ],
                    'formula_version' => self::SNAPSHOT_VERSION,
                    'type' => $type->value,
                    'user_id' => $request->userId,
                ];
                $snapshotJson = $this->json($snapshot);
                $redemptionId = (int) $db->table('benefit_code_redemptions')->insertGetId([
                    'public_id' => $publicId,
                    'redemption_key' => $request->redemptionKey,
                    'request_payload_hash' => $payloadHash,
                    'benefit_code_id' => $codeId,
                    'user_id' => $request->userId,
                    'benefit_code_campaign_id' => $this->positive($code->benefit_code_campaign_id, 'Benefit code campaign ID'),
                    'benefit_code_campaign_version_id' => $this->positive($code->benefit_code_campaign_version_id, 'Benefit code campaign version ID'),
                    'campaign_code_snapshot' => (string) $code->campaign_code,
                    'campaign_version' => $this->positive($code->version, 'Benefit code campaign version'),
                    'type_snapshot' => $type->value,
                    'plan_offering_id' => $request->planOfferingId,
                    'promotional_wallet_account_id' => $type === BenefitCodeType::WalletCredit ? $request->promotionalWalletAccountId : null,
                    'ledger_transaction_id' => $ledgerTransactionId,
                    'configuration_snapshot' => $snapshotJson,
                    'configuration_snapshot_hash' => hash('sha256', $snapshotJson),
                    'correlation_id' => $request->correlationId,
                    'created_at' => $this->timestamp(),
                ]);

                if ($type === BenefitCodeType::FreeService) {
                    $this->createFreeServiceEntitlement($db, $redemptionId, $publicId, $request->userId, $configuration);
                } elseif ($type === BenefitCodeType::DiscountGrant) {
                    $this->createDiscountGrant($db, $redemptionId, $publicId, $request->userId, $configuration);
                }

                $created = $this->redemptionByKey($db, $request->redemptionKey);
                if ($created === null) {
                    throw new RuntimeException('Benefit code redemption persistence failed.');
                }

                return $this->redemptionReceipt($db, $created, $payloadHash, false);
            });
        } catch (QueryException $exception) {
            $existing = $this->redemptionByKey($this->database->connection(), $request->redemptionKey);
            if ($existing !== null) {
                return $this->redemptionReceipt($this->database->connection(), $existing, $payloadHash, true);
            }

            throw $exception;
        }
    }

    /** @param array<string,mixed> $configuration */
    private function assertEffectiveWindow(array $configuration): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $from = $configuration['effective_from'] ?? null;
        $until = $configuration['effective_until'] ?? null;
        if ($from !== null && (! is_string($from) || $now < new DateTimeImmutable($from, new DateTimeZone('UTC')))) {
            throw new DomainException('Benefit code is not yet effective.');
        }
        if ($until !== null && (! is_string($until) || $now >= new DateTimeImmutable($until, new DateTimeZone('UTC')))) {
            throw new DomainException('Benefit code is expired.');
        }
    }

    /** @return array{id:int,product_id:int,sales_server_id:int}|null */
    private function offeringContext(Connection $db, ?int $offeringId): ?array
    {
        if ($offeringId === null) {
            return null;
        }
        /** @var object{id:int|string,product_id:int|string,sales_server_id:int|string}|null $row */
        $row = $db->table('plan_offerings')->where('id', $offeringId)->first(['id', 'product_id', 'sales_server_id']);
        if ($row === null) {
            throw new DomainException('Benefit code redemption plan offering does not exist.');
        }

        return [
            'id' => $this->positive($row->id, 'Benefit code offering ID'),
            'product_id' => $this->positive($row->product_id, 'Benefit code offering product ID'),
            'sales_server_id' => $this->positive($row->sales_server_id, 'Benefit code offering server ID'),
        ];
    }

    /** @param array<string,mixed> $configuration
     * @param  array{id:int,product_id:int,sales_server_id:int}|null  $offering
     */
    private function assertScope(array $configuration, ?array $offering): void
    {
        $scope = $configuration['scope'] ?? null;
        if (! is_array($scope)) {
            throw new RuntimeException('Stored benefit code scope is invalid.');
        }
        $offeringId = $this->nullablePositiveConfig($scope['plan_offering_id'] ?? null, 'Benefit code offering scope');
        $productId = $this->nullablePositiveConfig($scope['product_id'] ?? null, 'Benefit code product scope');
        $serverId = $this->nullablePositiveConfig($scope['sales_server_id'] ?? null, 'Benefit code server scope');
        if (($offeringId !== null || $productId !== null || $serverId !== null) && $offering === null) {
            throw new DomainException('Benefit code redemption requires offering context for its scope.');
        }
        if ($offering !== null && (($offeringId !== null && $offering['id'] !== $offeringId)
            || ($productId !== null && $offering['product_id'] !== $productId)
            || ($serverId !== null && $offering['sales_server_id'] !== $serverId))) {
            throw new DomainException('Benefit code scope does not match the redemption context.');
        }
    }

    /** @param array<string,mixed> $configuration */
    private function assertRedemptionCapacity(Connection $db, int $codeId, int $userId, array $configuration): void
    {
        $limits = $configuration['limits'] ?? null;
        if (! is_array($limits)) {
            throw new RuntimeException('Stored benefit code limits are invalid.');
        }
        $totalLimit = $this->nullablePositiveConfig($limits['total_use_limit'] ?? null, 'Benefit code total use limit');
        $userLimit = $this->nullablePositiveConfig($limits['per_user_use_limit'] ?? null, 'Benefit code per-user use limit');
        if (($limits['single_use'] ?? null) === true) {
            $totalLimit = 1;
        }
        $totalUses = (int) $db->table('benefit_code_redemptions')->where('benefit_code_id', $codeId)->count();
        $userUses = (int) $db->table('benefit_code_redemptions')->where('benefit_code_id', $codeId)->where('user_id', $userId)->count();
        if ($totalLimit !== null && $totalUses >= $totalLimit) {
            throw new DomainException('Benefit code total redemption capacity is exhausted.');
        }
        if ($userLimit !== null && $userUses >= $userLimit) {
            throw new DomainException('Benefit code per-user redemption capacity is exhausted.');
        }
    }

    private function nullablePositiveConfig(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new RuntimeException($label.' is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string,mixed> $configuration */
    private function postPromotionalWalletCredit(Connection $db, BenefitCodeRedemptionRequest $request, array $configuration, string $redemptionPublicId): int
    {
        $benefit = $configuration['benefit'] ?? null;
        $amount = is_array($benefit) ? $this->nullablePositiveConfig($benefit['wallet_credit_irr'] ?? null, 'Benefit code wallet credit') : null;
        if ($amount === null || $request->promotionalWalletAccountId === null) {
            throw new DomainException('Wallet-credit redemption requires a promotional wallet account and amount.');
        }
        /** @var object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool|string}|null $wallet */
        $wallet = $db->table('ledger_accounts')->where('id', $request->promotionalWalletAccountId)->lockForUpdate()->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($wallet === null || $wallet->account_class !== 'liability' || (int) $wallet->owner_user_id !== $request->userId || $wallet->wallet_bucket !== 'promotional' || $wallet->currency !== 'IRR' || ! (bool) $wallet->is_active) {
            throw new AuthorizationException('Promotional wallet account is not eligible for benefit credit.');
        }
        /** @var object{id:int|string,account_class:string,owner_user_id:int|string|null,wallet_bucket:string|null,currency:string,is_active:int|bool|string}|null $funding */
        $funding = $db->table('ledger_accounts')->where('code', self::PROMOTIONAL_FUNDING_ACCOUNT_CODE)->lockForUpdate()->first(['id', 'account_class', 'owner_user_id', 'wallet_bucket', 'currency', 'is_active']);
        if ($funding === null || $funding->account_class !== 'equity' || $funding->owner_user_id !== null || $funding->wallet_bucket !== null || $funding->currency !== 'IRR' || ! (bool) $funding->is_active) {
            throw new RuntimeException('Benefit code promotional funding account is unavailable.');
        }

        $money = IrrMoney::positive($amount);
        $receipt = $this->ledger->post(
            'benefit-code:'.substr(hash('sha256', $request->redemptionKey), 0, 96),
            self::LEDGER_TRANSACTION_TYPE,
            $request->correlationId,
            [
                new LedgerEntryDraft($this->positive($funding->id, 'Benefit code funding account ID'), LedgerDirection::Debit, $money),
                new LedgerEntryDraft($this->positive($wallet->id, 'Benefit code wallet account ID'), LedgerDirection::Credit, $money),
            ],
            'benefit_code_redemption',
            $redemptionPublicId,
        );

        return $receipt->transactionId;
    }

    /** @param array<string,mixed> $configuration */
    private function createFreeServiceEntitlement(Connection $db, int $redemptionId, string $redemptionPublicId, int $userId, array $configuration): void
    {
        $benefit = $configuration['benefit'] ?? null;
        $offering = is_array($benefit) ? ($benefit['free_service_offering'] ?? null) : null;
        if (! is_array($offering)) {
            throw new RuntimeException('Free-service benefit snapshot is invalid.');
        }
        $snapshot = [
            'offering' => $offering,
            'redemption_public_id' => $redemptionPublicId,
            'user_id' => $userId,
        ];
        $json = $this->json($snapshot);
        $db->table('benefit_code_free_service_entitlements')->insert([
            'public_id' => (string) Str::ulid(),
            'benefit_code_redemption_id' => $redemptionId,
            'user_id' => $userId,
            'plan_offering_id' => $this->nullablePositiveConfig($offering['id'] ?? null, 'Benefit code entitlement offering ID'),
            'configuration_snapshot' => $json,
            'configuration_hash' => hash('sha256', $json),
            'created_at' => $this->timestamp(),
        ]);
    }

    /** @param array<string,mixed> $configuration */
    private function createDiscountGrant(Connection $db, int $redemptionId, string $redemptionPublicId, int $userId, array $configuration): void
    {
        $benefit = $configuration['benefit'] ?? null;
        $rule = is_array($benefit) ? ($benefit['discount_rule'] ?? null) : null;
        if (! is_array($rule)) {
            throw new RuntimeException('Discount-grant benefit snapshot is invalid.');
        }
        $ruleCode = $rule['rule_code'] ?? null;
        $ruleVersion = $this->nullablePositiveConfig($rule['version'] ?? null, 'Benefit code grant rule version');
        $ruleHash = $rule['configuration_hash'] ?? null;
        if (! is_string($ruleCode) || ! is_string($ruleHash) || $ruleVersion === null) {
            throw new RuntimeException('Discount-grant benefit identity is invalid.');
        }
        /** @var object{id:int|string,version_id:int|string}|null $stored */
        $stored = $db->table('pricing_rules as r')->join('pricing_rule_versions as v', 'v.pricing_rule_id', '=', 'r.id')
            ->where('r.rule_code', $ruleCode)->where('v.version', $ruleVersion)->where('v.configuration_hash', $ruleHash)
            ->first(['r.id', 'v.id as version_id']);
        if ($stored === null) {
            throw new RuntimeException('Discount-grant promotion rule identity no longer matches its immutable snapshot.');
        }
        $snapshot = [
            'redemption_public_id' => $redemptionPublicId,
            'rule' => $rule,
            'user_id' => $userId,
        ];
        $json = $this->json($snapshot);
        $db->table('benefit_code_discount_grants')->insert([
            'public_id' => (string) Str::ulid(),
            'benefit_code_redemption_id' => $redemptionId,
            'user_id' => $userId,
            'pricing_rule_id' => $this->positive($stored->id, 'Benefit code grant rule ID'),
            'pricing_rule_version_id' => $this->positive($stored->version_id, 'Benefit code grant rule version ID'),
            'rule_code_snapshot' => $ruleCode,
            'rule_version' => $ruleVersion,
            'rule_configuration_hash' => $ruleHash,
            'configuration_snapshot' => $json,
            'configuration_hash' => hash('sha256', $json),
            'created_at' => $this->timestamp(),
        ]);
    }
}
