<?php

declare(strict_types=1);

use App\Modules\Payments\Application\PurchaseProviderMutationBarrier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep an exact provider-finished contradiction fail-closed in canonical Payments
     * authority instead of leaving the purchase represented as safely failed/expired.
     */
    public function up(): void
    {
        // MariaDB commits DDL per statement. Persist a migration-owned financial
        // write fence before the first decision-relevant read, then keep it active
        // through canonical guard composition, final evidence revalidation and
        // normalization. Any interrupted run therefore fails closed and can re-enter.
        $this->activateUpgradeFence();
        app(PurchaseProviderMutationBarrier::class)->blockAll(function (): void {
            $this->installUpgradeFences();

            $this->preflightLegacyTerminalProviderFinishedConflicts();

            $this->createConflictAwarePaymentIntentInsertGuard();
            $this->createConflictAwareNowPaymentsAuthorityGuard();
            $this->createConflictAwarePaymentIntentGuard();

            // Re-read only after every canonical successor guard is durably composed.
            // The migration fence prevents payment/provider writers from changing this
            // evidence set between the revalidation and normalization steps.
            $legacyConflicts = $this->preflightLegacyTerminalProviderFinishedConflicts();
            $this->normalizeLegacyTerminalProviderFinishedConflicts($legacyConflicts);
            $this->assertLegacyTerminalProviderFinishedConflictsNormalized(
                $this->preflightLegacyTerminalProviderFinishedConflicts(),
            );

            $this->releaseUpgradeFence();
        });
    }

    public function down(): void
    {
        $this->activateUpgradeFence();
        app(PurchaseProviderMutationBarrier::class)->blockAll(function (): void {
            $this->installUpgradeFences();

            if (DB::table('nowpayments_reconciliation_findings')
                ->whereIn('code', [
                    'terminal_local_state_conflicts_with_finished_provider',
                    'terminal_finished_conflict_status_changed',
                    'terminal_provider_finished_conflict_competing_intent',
                ])
                ->exists()) {
                // No predecessor guard has been restored yet. Re-enable normal traffic
                // under the still-current successor guards before refusing the semantic
                // rollback.
                $this->releaseUpgradeFence();

                throw new RuntimeException(
                    'Cannot roll back terminal NOWPayments conflict hardening while terminal-conflict reconciliation evidence exists.',
                );
            }

            $this->createPriorPaymentIntentGuard();
            $this->createPriorPaymentIntentInsertGuard();
            $this->createPriorNowPaymentsAuthorityGuard();

            $this->releaseUpgradeFence();
        });
    }

    /**
     * Discover only conflict evidence that is exact enough to normalize without
     * creating any provider, settlement, Order or promotion effect. Ambiguous
     * legacy evidence aborts before any effective trigger replacement.
     *
     * @return list<array{
     *     authority_id:int,
     *     payment_intent_id:int,
     *     finished_observation_id:int,
     *     finished_observed_at:string,
     *     correlation_id:string,
     *     contradictory_observations:list<object{id:int|string,provider_status:string|null,response_hash:string,occurred_at:string,correlation_id:string}>
     * }>
     */
    private function preflightLegacyTerminalProviderFinishedConflicts(): array
    {
        $authorityIds = DB::table('nowpayments_reconciliation_findings')
            ->where('code', 'terminal_local_state_conflicts_with_finished_provider')
            ->where('severity', 'critical')
            ->where('provider_status', 'finished')
            ->distinct()
            ->orderBy('nowpayments_payment_authority_id')
            ->pluck('nowpayments_payment_authority_id');

        $conflicts = [];
        foreach ($authorityIds as $authorityIdValue) {
            $authorityId = (int) $authorityIdValue;
            $authority = DB::table('nowpayments_payment_authorities as authority')
                ->join('payment_intents as intent', 'intent.id', '=', 'authority.payment_intent_id')
                ->where('authority.id', $authorityId)
                ->first([
                    'authority.id as authority_id',
                    'authority.payment_intent_id',
                    'authority.provider_payment_id',
                    'authority.provider_status',
                    'authority.state as authority_state',
                    'intent.purpose as intent_purpose',
                    'intent.provider_code as intent_provider_code',
                    'intent.payment_method_code as intent_payment_method_code',
                    'intent.state as intent_state',
                ]);
            if ($authority === null
                || $authority->provider_payment_id === null
                || $authority->intent_purpose !== 'purchase'
                || $authority->intent_provider_code !== 'nowpayments'
                || $authority->intent_payment_method_code !== 'nowpayments') {
                throw new RuntimeException(
                    'Legacy NOWPayments terminal conflict does not resolve to one exact purchase authority.',
                );
            }

            $findings = DB::table('nowpayments_reconciliation_findings')
                ->where('nowpayments_payment_authority_id', $authorityId)
                ->where('code', 'terminal_local_state_conflicts_with_finished_provider')
                ->where('severity', 'critical')
                ->where('provider_status', 'finished')
                ->orderBy('id')
                ->get(['id', 'evidence_hash', 'correlation_id']);
            if ($findings->isEmpty()) {
                throw new RuntimeException('Legacy NOWPayments terminal conflict finding disappeared during preflight.');
            }

            $finishedObservation = null;
            foreach ($findings as $finding) {
                $observation = DB::table('nowpayments_payment_observations')
                    ->where('nowpayments_payment_authority_id', $authorityId)
                    ->where('event_type', 'status_lookup')
                    ->where('provider_payment_id', (string) $authority->provider_payment_id)
                    ->where('provider_status', 'finished')
                    ->where('response_hash', (string) $finding->evidence_hash)
                    ->orderBy('id')
                    ->first(['id', 'occurred_at', 'correlation_id']);
                if ($observation === null) {
                    throw new RuntimeException(
                        'Legacy NOWPayments terminal conflict lacks its exact finished status observation.',
                    );
                }
                if ($finishedObservation === null || (int) $observation->id < (int) $finishedObservation->id) {
                    $finishedObservation = $observation;
                }
            }
            if ($finishedObservation === null) {
                throw new RuntimeException('Legacy NOWPayments terminal conflict finished observation is unavailable.');
            }

            $settlementCount = DB::table('purchase_settlements')
                ->where('payment_intent_id', (int) $authority->payment_intent_id)
                ->count();
            if ($settlementCount !== 0) {
                $resolvedIntentStates = ['captured', 'refund_pending', 'partially_refunded', 'refunded'];
                if ($settlementCount === 1
                    && $authority->authority_state === 'finished'
                    && in_array($authority->intent_state, $resolvedIntentStates, true)) {
                    continue;
                }

                throw new RuntimeException(
                    'Legacy NOWPayments terminal conflict has ambiguous settlement state and cannot be normalized automatically.',
                );
            }

            if (! in_array($authority->authority_state, ['failed', 'expired', 'manual_review'], true)
                || ! in_array($authority->intent_state, ['failed', 'expired', 'pending_manual_review'], true)) {
                throw new RuntimeException(
                    'Legacy NOWPayments terminal conflict has an unsupported unresolved lifecycle shape.',
                );
            }
            if ($authority->authority_state === 'manual_review' && $authority->provider_status !== 'finished') {
                throw new RuntimeException(
                    'Legacy NOWPayments terminal conflict is partially normalized without retained provider-finished authority.',
                );
            }

            /** @var list<object{id:int|string,provider_status:string|null,response_hash:string,occurred_at:string,correlation_id:string}> $contradictoryObservations */
            $contradictoryObservations = DB::table('nowpayments_payment_observations')
                ->where('nowpayments_payment_authority_id', $authorityId)
                ->where('event_type', 'status_lookup')
                ->where('id', '>', (int) $finishedObservation->id)
                ->where(function ($query): void {
                    $query->whereNull('provider_status')
                        ->orWhere('provider_status', '<>', 'finished');
                })
                ->orderBy('id')
                ->get(['id', 'provider_status', 'response_hash', 'occurred_at', 'correlation_id'])
                ->all();

            $conflicts[] = [
                'authority_id' => $authorityId,
                'payment_intent_id' => (int) $authority->payment_intent_id,
                'finished_observation_id' => (int) $finishedObservation->id,
                'finished_observed_at' => (string) $finishedObservation->occurred_at,
                'correlation_id' => (string) $finishedObservation->correlation_id,
                'contradictory_observations' => $contradictoryObservations,
            ];
        }

        return $conflicts;
    }

    /**
     * @param list<array{
     *     authority_id:int,
     *     payment_intent_id:int,
     *     finished_observation_id:int,
     *     finished_observed_at:string,
     *     correlation_id:string,
     *     contradictory_observations:list<object{id:int|string,provider_status:string|null,response_hash:string,occurred_at:string,correlation_id:string}>
     * }> $conflicts
     */
    private function normalizeLegacyTerminalProviderFinishedConflicts(array $conflicts): void
    {
        if ($conflicts === []) {
            return;
        }

        DB::connection()->transaction(function () use ($conflicts): void {
            foreach ($conflicts as $conflict) {
                $authority = DB::table('nowpayments_payment_authorities')
                    ->where('id', $conflict['authority_id'])
                    ->lockForUpdate()
                    ->first(['id', 'payment_intent_id', 'state', 'provider_status']);
                $intent = DB::table('payment_intents')
                    ->where('id', $conflict['payment_intent_id'])
                    ->lockForUpdate()
                    ->first(['id', 'state']);
                if ($authority === null || $intent === null
                    || (int) $authority->payment_intent_id !== $conflict['payment_intent_id']) {
                    throw new RuntimeException('Legacy NOWPayments terminal conflict changed during normalization.');
                }
                if (DB::table('purchase_settlements')
                    ->where('payment_intent_id', $conflict['payment_intent_id'])
                    ->exists()) {
                    throw new RuntimeException(
                        'Legacy NOWPayments terminal conflict acquired settlement during normalization.',
                    );
                }

                if (in_array($authority->state, ['failed', 'expired'], true)) {
                    $updated = DB::table('nowpayments_payment_authorities')
                        ->where('id', $conflict['authority_id'])
                        ->where('state', (string) $authority->state)
                        ->update([
                            'state' => 'manual_review',
                            'provider_status' => 'finished',
                            'last_status_at' => $conflict['finished_observed_at'],
                            'updated_at' => $this->timestamp(),
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Legacy NOWPayments authority changed during normalization.');
                    }
                } elseif ($authority->state !== 'manual_review' || $authority->provider_status !== 'finished') {
                    throw new RuntimeException('Legacy NOWPayments authority is no longer normalizable.');
                }

                if (in_array($intent->state, ['failed', 'expired'], true)) {
                    $fromState = (string) $intent->state;
                    $updated = DB::table('payment_intents')
                        ->where('id', $conflict['payment_intent_id'])
                        ->where('state', $fromState)
                        ->update([
                            'state' => 'pending_manual_review',
                            'updated_at' => $this->timestamp(),
                        ]);
                    if ($updated !== 1) {
                        throw new RuntimeException('Legacy NOWPayments PaymentIntent changed during normalization.');
                    }

                    DB::table('payment_intent_state_histories')->insert([
                        'payment_intent_id' => $conflict['payment_intent_id'],
                        'from_state' => $fromState,
                        'to_state' => 'pending_manual_review',
                        'reason_code' => 'nowpayments_legacy_terminal_finished_conflict',
                        'correlation_id' => $conflict['correlation_id'],
                        'created_at' => $this->timestamp(),
                    ]);
                } elseif ($intent->state !== 'pending_manual_review') {
                    throw new RuntimeException('Legacy NOWPayments PaymentIntent is no longer normalizable.');
                }

                foreach ($conflict['contradictory_observations'] as $observation) {
                    $responseHash = strtolower((string) $observation->response_hash);
                    $findingKey = 'nowpayments:'.$conflict['authority_id']
                        .':terminal_finished_conflict_status_changed:'.substr($responseHash, 0, 32);

                    DB::table('nowpayments_reconciliation_findings')->insertOrIgnore([
                        'nowpayments_payment_authority_id' => $conflict['authority_id'],
                        'finding_key' => $findingKey,
                        'code' => 'terminal_finished_conflict_status_changed',
                        'severity' => 'critical',
                        'provider_status' => $observation->provider_status,
                        'evidence_hash' => $responseHash,
                        'detected_at' => (string) $observation->occurred_at,
                        'correlation_id' => (string) $observation->correlation_id,
                        'created_at' => $this->timestamp(),
                    ]);
                }
            }
        }, 3);
    }

    /**
     * Verify that every legacy conflict discovered under the migration fence is
     * represented by the canonical fail-closed state and every durable later
     * contradictory status has corresponding sticky reconciliation evidence.
     *
     * @param list<array{
     *     authority_id:int,
     *     payment_intent_id:int,
     *     finished_observation_id:int,
     *     finished_observed_at:string,
     *     correlation_id:string,
     *     contradictory_observations:list<object{id:int|string,provider_status:string|null,response_hash:string,occurred_at:string,correlation_id:string}>
     * }> $conflicts
     */
    private function assertLegacyTerminalProviderFinishedConflictsNormalized(array $conflicts): void
    {
        foreach ($conflicts as $conflict) {
            $authority = DB::table('nowpayments_payment_authorities')
                ->where('id', $conflict['authority_id'])
                ->first(['payment_intent_id', 'state', 'provider_status']);
            $intent = DB::table('payment_intents')
                ->where('id', $conflict['payment_intent_id'])
                ->first(['state']);

            if ($authority === null
                || $intent === null
                || (int) $authority->payment_intent_id !== $conflict['payment_intent_id']
                || $authority->state !== 'manual_review'
                || $authority->provider_status !== 'finished'
                || $intent->state !== 'pending_manual_review'
                || DB::table('purchase_settlements')
                    ->where('payment_intent_id', $conflict['payment_intent_id'])
                    ->exists()) {
                throw new RuntimeException(
                    'Legacy NOWPayments terminal conflict did not converge to exact fail-closed canonical state.',
                );
            }

            foreach ($conflict['contradictory_observations'] as $observation) {
                $responseHash = strtolower((string) $observation->response_hash);
                $exists = DB::table('nowpayments_reconciliation_findings')
                    ->where('nowpayments_payment_authority_id', $conflict['authority_id'])
                    ->where('code', 'terminal_finished_conflict_status_changed')
                    ->where('severity', 'critical')
                    ->where('provider_status', $observation->provider_status)
                    ->where('evidence_hash', $responseHash)
                    ->exists();
                if (! $exists) {
                    throw new RuntimeException(
                        'Legacy NOWPayments contradictory status history did not converge to sticky reconciliation evidence.',
                    );
                }
            }
        }
    }

    /**
     * Activate a persistent migration-local cut before any legacy evidence is read.
     * The marker is DML while all fence triggers use CREATE OR REPLACE. A crash
     * therefore leaves a fail-closed cut or occurs before decision-relevant reads.
     */
    private function activateUpgradeFence(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS nowpayments_terminal_conflict_upgrade_fence (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    active TINYINT UNSIGNED NOT NULL,
    activated_at DATETIME(6) NOT NULL,
    CONSTRAINT nowpayments_terminal_conflict_upgrade_fence_id_chk CHECK (id = 1),
    CONSTRAINT nowpayments_terminal_conflict_upgrade_fence_active_chk CHECK (active = 1)
) ENGINE=InnoDB
SQL);

        $shape = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'nowpayments_terminal_conflict_upgrade_fence'
  AND COLUMN_NAME IN ('id','active','activated_at')
SQL);
        if ($shape === null || (int) $shape->aggregate !== 3) {
            throw new RuntimeException('NOWPayments terminal-conflict upgrade fence table is partially applied.');
        }

        DB::statement(<<<'SQL'
INSERT INTO nowpayments_terminal_conflict_upgrade_fence (id, active, activated_at)
VALUES (1, 1, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE active = 1, activated_at = VALUES(activated_at)
SQL);
    }

    private function installUpgradeFences(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_np_conflict_upgrade_insert_fence
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    IF NEW.purpose = 'purchase'
       AND EXISTS (
           SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
           WHERE fence_row.id = 1 AND fence_row.active = 1
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase payment creation is fenced while NOWPayments conflict authority migration converges.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_np_conflict_upgrade_update_fence
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE exact_reopen_count INT DEFAULT 0;

    IF EXISTS (
        SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        WHERE fence_row.id = 1 AND fence_row.active = 1
    ) AND OLD.purpose = 'purchase' THEN
        IF OLD.provider_code = 'nowpayments'
           AND OLD.payment_method_code = 'nowpayments'
           AND OLD.state IN ('failed','expired')
           AND NEW.state = 'pending_manual_review' THEN
            SELECT COUNT(DISTINCT authority_row.id) INTO exact_reopen_count
            FROM nowpayments_payment_authorities authority_row
            INNER JOIN nowpayments_reconciliation_findings finding_row
                ON finding_row.nowpayments_payment_authority_id = authority_row.id
               AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
               AND finding_row.severity = 'critical'
               AND finding_row.provider_status = 'finished'
            INNER JOIN nowpayments_payment_observations observation_row
                ON observation_row.nowpayments_payment_authority_id = authority_row.id
               AND observation_row.event_type = 'status_lookup'
               AND observation_row.provider_payment_id = authority_row.provider_payment_id
               AND observation_row.provider_status = 'finished'
               AND observation_row.response_hash = finding_row.evidence_hash
            WHERE authority_row.payment_intent_id = OLD.id
              AND authority_row.state = 'manual_review'
              AND authority_row.provider_status = 'finished'
              AND NOT EXISTS (
                  SELECT 1 FROM purchase_settlements settlement_row
                  WHERE settlement_row.payment_intent_id = OLD.id
              );
        END IF;

        IF exact_reopen_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase payment updates are fenced while NOWPayments conflict authority migration converges.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER nowpayments_authority_np_conflict_upgrade_insert_fence
BEFORE INSERT ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        WHERE fence_row.id = 1 AND fence_row.active = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority creation is fenced while conflict authority migration converges.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER nowpayments_authority_np_conflict_upgrade_update_fence
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    DECLARE exact_reopen_count INT DEFAULT 0;

    IF EXISTS (
        SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        WHERE fence_row.id = 1 AND fence_row.active = 1
    ) THEN
        IF OLD.state IN ('failed','expired')
           AND NEW.state = 'manual_review'
           AND NEW.provider_status = 'finished'
           AND OLD.provider_payment_id IS NOT NULL THEN
            SELECT COUNT(DISTINCT finding_row.id) INTO exact_reopen_count
            FROM nowpayments_reconciliation_findings finding_row
            INNER JOIN nowpayments_payment_observations observation_row
                ON observation_row.nowpayments_payment_authority_id = finding_row.nowpayments_payment_authority_id
               AND observation_row.event_type = 'status_lookup'
               AND observation_row.provider_payment_id = OLD.provider_payment_id
               AND observation_row.provider_status = 'finished'
               AND observation_row.response_hash = finding_row.evidence_hash
            WHERE finding_row.nowpayments_payment_authority_id = OLD.id
              AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
              AND finding_row.severity = 'critical'
              AND finding_row.provider_status = 'finished'
              AND NOT EXISTS (
                  SELECT 1 FROM purchase_settlements settlement_row
                  WHERE settlement_row.payment_intent_id = OLD.payment_intent_id
              );
        END IF;

        IF exact_reopen_count < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority updates are fenced while conflict authority migration converges.';
        END IF;
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER nowpayments_observation_np_conflict_upgrade_insert_fence
BEFORE INSERT ON nowpayments_payment_observations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        WHERE fence_row.id = 1 AND fence_row.active = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments status evidence is fenced while conflict authority migration converges.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER zarinpal_request_np_conflict_upgrade_insert_fence
BEFORE INSERT ON zarinpal_payment_requests
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND intent_row.purpose = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Zarinpal purchase authority creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER usdt_authority_np_conflict_upgrade_insert_fence
BEFORE INSERT ON usdt_payment_authorities
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND intent_row.purpose = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'USDT purchase authority creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER c2c_reservation_np_conflict_upgrade_insert_fence
BEFORE INSERT ON c2c_amount_reservations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND intent_row.purpose = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Card-to-card purchase authority creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER gift_card_submission_np_conflict_upgrade_insert_fence
BEFORE INSERT ON gift_card_submissions
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND intent_row.purpose = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gift-card purchase authority creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER wallet_hold_np_conflict_upgrade_insert_fence
BEFORE INSERT ON wallet_holds
FOR EACH ROW
BEGIN
    IF NEW.source_type = 'payment_intent'
       AND EXISTS (
           SELECT 1
           FROM nowpayments_terminal_conflict_upgrade_fence fence_row
           INNER JOIN payment_intents intent_row ON intent_row.public_id = NEW.source_id
           WHERE fence_row.id = 1 AND fence_row.active = 1
             AND intent_row.purpose = 'purchase'
       ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase hold creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_wallet_np_conflict_upgrade_insert_fence
BEFORE INSERT ON purchase_wallet_reservations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1 FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        WHERE fence_row.id = 1 AND fence_row.active = 1
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase reservation creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER promotion_reservation_np_conflict_upgrade_insert_fence
BEFORE INSERT ON promotion_usage_reservations
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN quotes quote_row ON quote_row.id = NEW.quote_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND quote_row.action_snapshot = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase promotion reservation creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER promotion_release_np_conflict_upgrade_insert_fence
BEFORE INSERT ON promotion_usage_releases
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN promotion_usage_reservations reservation_row
            ON reservation_row.id = NEW.promotion_usage_reservation_id
        INNER JOIN quotes quote_row ON quote_row.id = reservation_row.quote_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND quote_row.action_snapshot = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase promotion release is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER purchase_settlement_np_conflict_upgrade_insert_fence
BEFORE INSERT ON purchase_settlements
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM nowpayments_terminal_conflict_upgrade_fence fence_row
        INNER JOIN payment_intents intent_row ON intent_row.id = NEW.payment_intent_id
        WHERE fence_row.id = 1 AND fence_row.active = 1
          AND intent_row.purpose = 'purchase'
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase settlement creation is fenced during NOWPayments conflict migration.';
    END IF;
END
SQL);
    }

    private function releaseUpgradeFence(): void
    {
        if (DB::connection()->getSchemaBuilder()->hasTable('nowpayments_terminal_conflict_upgrade_fence')) {
            DB::table('nowpayments_terminal_conflict_upgrade_fence')->where('id', 1)->delete();
        }

        foreach ([
            'DROP TRIGGER IF EXISTS purchase_settlement_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS promotion_release_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS promotion_reservation_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS purchase_wallet_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS wallet_hold_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS gift_card_submission_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS c2c_reservation_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS usdt_authority_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS zarinpal_request_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS nowpayments_observation_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS nowpayments_authority_np_conflict_upgrade_update_fence',
            'DROP TRIGGER IF EXISTS nowpayments_authority_np_conflict_upgrade_insert_fence',
            'DROP TRIGGER IF EXISTS payment_intents_np_conflict_upgrade_update_fence',
            'DROP TRIGGER IF EXISTS payment_intents_np_conflict_upgrade_insert_fence',
        ] as $statement) {
            DB::unprepared($statement);
        }

        DB::statement('DROP TABLE IF EXISTS nowpayments_terminal_conflict_upgrade_fence');
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private function createConflictAwareNowPaymentsAuthorityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER nowpayments_authority_update_guard
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    DECLARE finished_status_observation_count INT DEFAULT 0;
    DECLARE terminal_finished_finding_count INT DEFAULT 0;
    DECLARE terminal_finished_status_changed_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.rate_source <=> OLD.rate_source)
       OR NOT (NEW.rate_irr <=> OLD.rate_irr)
       OR NOT (NEW.rate_fetched_at <=> OLD.rate_fetched_at)
       OR NOT (NEW.rate_response_hash <=> OLD.rate_response_hash)
       OR NOT (NEW.pricing_policy_code <=> OLD.pricing_policy_code)
       OR NOT (NEW.price_amount_usd <=> OLD.price_amount_usd)
       OR NOT (NEW.price_currency <=> OLD.price_currency)
       OR NOT (NEW.pay_currency <=> OLD.pay_currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.create_attempted_at <=> OLD.create_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments pricing/request authority is immutable.';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL AND NOT (NEW.provider_payment_id <=> OLD.provider_payment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider payment ID is immutable once accepted.';
    END IF;
    IF OLD.create_response_hash IS NOT NULL AND NOT (NEW.create_response_hash <=> OLD.create_response_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments create response identity is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_amount IS NOT NULL AND NOT (NEW.provider_pay_amount <=> OLD.provider_pay_amount) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay amount is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_address IS NOT NULL AND NOT (NEW.provider_pay_address <=> OLD.provider_pay_address) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay address is immutable once accepted.';
    END IF;

    IF OLD.state = 'manual_review' THEN
        SELECT COUNT(*) INTO terminal_finished_finding_count
        FROM nowpayments_reconciliation_findings finding_row
        WHERE finding_row.nowpayments_payment_authority_id = OLD.id
          AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
          AND finding_row.severity = 'critical'
          AND finding_row.provider_status = 'finished';

        SELECT COUNT(*) INTO terminal_finished_status_changed_count
        FROM nowpayments_reconciliation_findings finding_row
        WHERE finding_row.nowpayments_payment_authority_id = OLD.id
          AND finding_row.code = 'terminal_finished_conflict_status_changed'
          AND finding_row.severity = 'critical';

        IF terminal_finished_finding_count > 0
           AND (NEW.state IN ('failed','expired') OR NEW.provider_status <> 'finished') THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments finished conflict remains manual until canonical financial resolution.';
        END IF;

        IF terminal_finished_status_changed_count > 0 AND NEW.state = 'finished' THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Contradictory provider history requires manual NOWPayments financial resolution.';
        END IF;
    END IF;

    IF OLD.state IN ('failed','expired') AND NEW.state = 'manual_review' THEN
        SELECT COUNT(DISTINCT finding_row.id) INTO finished_status_observation_count
        FROM nowpayments_reconciliation_findings finding_row
        INNER JOIN nowpayments_payment_observations observation_row
            ON observation_row.nowpayments_payment_authority_id = finding_row.nowpayments_payment_authority_id
           AND observation_row.event_type = 'status_lookup'
           AND observation_row.provider_payment_id = OLD.provider_payment_id
           AND observation_row.provider_status = 'finished'
           AND observation_row.response_hash = finding_row.evidence_hash
        WHERE finding_row.nowpayments_payment_authority_id = OLD.id
          AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
          AND finding_row.severity = 'critical'
          AND finding_row.provider_status = 'finished'
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.payment_intent_id
          );

        IF finished_status_observation_count < 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments provider-finished recovery requires one exact durable observation/finding evidence pair and no settlement.';
        END IF;
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired')) OR
        (OLD.state IN ('failed','expired') AND NEW.state = 'manual_review'
         AND NEW.provider_payment_id IS NOT NULL AND NEW.provider_status = 'finished'
         AND finished_status_observation_count >= 1)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority state transition is invalid.';
    END IF;
END
SQL);
    }

    private function createPriorNowPaymentsAuthorityGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER nowpayments_authority_update_guard
BEFORE UPDATE ON nowpayments_payment_authorities
FOR EACH ROW
BEGIN
    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.request_key <=> OLD.request_key)
       OR NOT (NEW.payment_intent_id <=> OLD.payment_intent_id)
       OR NOT (NEW.order_id <=> OLD.order_id)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.rate_source <=> OLD.rate_source)
       OR NOT (NEW.rate_irr <=> OLD.rate_irr)
       OR NOT (NEW.rate_fetched_at <=> OLD.rate_fetched_at)
       OR NOT (NEW.rate_response_hash <=> OLD.rate_response_hash)
       OR NOT (NEW.pricing_policy_code <=> OLD.pricing_policy_code)
       OR NOT (NEW.price_amount_usd <=> OLD.price_amount_usd)
       OR NOT (NEW.price_currency <=> OLD.price_currency)
       OR NOT (NEW.pay_currency <=> OLD.pay_currency)
       OR NOT (NEW.callback_url <=> OLD.callback_url)
       OR NOT (NEW.request_payload_hash <=> OLD.request_payload_hash)
       OR NOT (NEW.create_attempted_at <=> OLD.create_attempted_at)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments pricing/request authority is immutable.';
    END IF;

    IF OLD.provider_payment_id IS NOT NULL AND NOT (NEW.provider_payment_id <=> OLD.provider_payment_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider payment ID is immutable once accepted.';
    END IF;
    IF OLD.create_response_hash IS NOT NULL AND NOT (NEW.create_response_hash <=> OLD.create_response_hash) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments create response identity is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_amount IS NOT NULL AND NOT (NEW.provider_pay_amount <=> OLD.provider_pay_amount) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay amount is immutable once accepted.';
    END IF;
    IF OLD.provider_pay_address IS NOT NULL AND NOT (NEW.provider_pay_address <=> OLD.provider_pay_address) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments provider pay address is immutable once accepted.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'initiating' AND NEW.state IN ('created','uncertain','failed')) OR
        (OLD.state = 'uncertain' AND NEW.state = 'manual_review') OR
        (OLD.state = 'created' AND NEW.state IN ('manual_review','finished','failed','expired')) OR
        (OLD.state = 'manual_review' AND NEW.state IN ('finished','failed','expired'))
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NOWPayments authority state transition is invalid.';
    END IF;
END
SQL);
    }

    private function createConflictAwarePaymentIntentInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_decision_count INT DEFAULT 0;
    DECLARE valid_method_count INT DEFAULT 0;
    DECLARE unresolved_purchase_manual_review_count INT DEFAULT 0;

    IF NEW.purpose = 'wallet_top_up' THEN
        IF NEW.source_quote_id IS NOT NULL OR NEW.payment_eligibility_decision_id IS NOT NULL OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;
        SELECT COUNT(*) INTO valid_wallet_count FROM ledger_accounts
        WHERE id = NEW.wallet_account_id AND owner_user_id = NEW.user_id AND account_class = 'liability'
          AND wallet_bucket = 'cash' AND currency = 'IRR' AND is_active = 1;
        IF valid_wallet_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.'; END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.'; END IF;

        SELECT COUNT(*) INTO unresolved_purchase_manual_review_count
        FROM payment_intents existing_intent
        WHERE existing_intent.purpose = 'purchase'
          AND existing_intent.source_quote_id = NEW.source_quote_id
          AND existing_intent.user_id = NEW.user_id
          AND (
              existing_intent.state = 'pending_manual_review'
              OR EXISTS (
                  SELECT 1
                  FROM nowpayments_payment_authorities existing_authority
                  INNER JOIN nowpayments_reconciliation_findings conflict_finding
                      ON conflict_finding.nowpayments_payment_authority_id = existing_authority.id
                  INNER JOIN nowpayments_payment_observations conflict_observation
                      ON conflict_observation.nowpayments_payment_authority_id = existing_authority.id
                     AND conflict_observation.event_type = 'status_lookup'
                     AND conflict_observation.provider_payment_id = existing_authority.provider_payment_id
                     AND conflict_observation.provider_status = 'finished'
                     AND conflict_observation.response_hash = conflict_finding.evidence_hash
                  WHERE existing_authority.payment_intent_id = existing_intent.id
                    AND existing_intent.provider_code = 'nowpayments'
                    AND existing_intent.payment_method_code = 'nowpayments'
                    AND existing_authority.state IN ('failed','expired','manual_review')
                    AND conflict_finding.code = 'terminal_local_state_conflicts_with_finished_provider'
                    AND conflict_finding.severity = 'critical'
                    AND conflict_finding.provider_status = 'finished'
                    AND NOT EXISTS (
                        SELECT 1
                        FROM purchase_settlements settlement_row
                        WHERE settlement_row.payment_intent_id = existing_intent.id
                    )
              )
          );
        IF unresolved_purchase_manual_review_count <> 0 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent is locked by unresolved payment reconciliation.';
        END IF;

        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at AND quote_row.expires_at > NEW.created_at;
        IF valid_quote_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.'; END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        INNER JOIN quotes quote_row ON quote_row.id = decision_row.source_quote_id
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND decision_row.action_snapshot = quote_row.action_snapshot
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;
        IF valid_decision_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.'; END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version ON method_version.id = decision_method.payment_method_version_id
        WHERE decision_method.payment_method_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND decision_method.payment_method_version_id = NEW.payment_method_version_id
          AND decision_method.method_code = NEW.payment_method_code
          AND decision_method.method_version = NEW.payment_method_version
          AND decision_method.route_order IS NOT NULL
          AND decision_method.reason_code = 'eligible'
          AND decision_method.configuration_snapshot_hash = NEW.payment_eligibility_method_configuration_hash
          AND method_version.method_code = NEW.payment_method_code
          AND method_version.version = NEW.payment_method_version
          AND method_version.configuration_snapshot_hash = NEW.payment_method_configuration_hash
          AND NEW.provider_code = NEW.payment_method_code;
        IF valid_method_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
    END IF;
END
SQL);
    }

    private function createPriorPaymentIntentInsertGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_insert_guard
BEFORE INSERT ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE valid_wallet_count INT DEFAULT 0;
    DECLARE valid_quote_count INT DEFAULT 0;
    DECLARE valid_decision_count INT DEFAULT 0;
    DECLARE valid_method_count INT DEFAULT 0;

    IF NEW.purpose = 'wallet_top_up' THEN
        IF NEW.source_quote_id IS NOT NULL OR NEW.payment_eligibility_decision_id IS NOT NULL OR NEW.payment_method_version_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent cannot carry purchase authority bindings.';
        END IF;
        SELECT COUNT(*) INTO valid_wallet_count FROM ledger_accounts
        WHERE id = NEW.wallet_account_id AND owner_user_id = NEW.user_id AND account_class = 'liability'
          AND wallet_bucket = 'cash' AND currency = 'IRR' AND is_active = 1;
        IF valid_wallet_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet top-up intent requires one active owned IRR cash wallet.'; END IF;
    ELSEIF NEW.purpose = 'purchase' THEN
        IF NEW.wallet_account_id IS NOT NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent cannot carry a wallet top-up binding.'; END IF;
        SELECT COUNT(*) INTO valid_quote_count
        FROM quotes quote_row
        WHERE quote_row.id = NEW.source_quote_id AND quote_row.public_id = NEW.source_quote_public_id
          AND quote_row.user_id = NEW.user_id AND quote_row.final_price_irr = NEW.amount_irr
          AND quote_row.currency = NEW.currency AND quote_row.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND quote_row.valid_from <= NEW.created_at AND quote_row.expires_at > NEW.created_at;
        IF valid_quote_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one current matching immutable Quote.'; END IF;

        SELECT COUNT(*) INTO valid_decision_count
        FROM payment_method_eligibility_decisions decision_row
        INNER JOIN quotes quote_row ON quote_row.id = decision_row.source_quote_id
        WHERE decision_row.id = NEW.payment_eligibility_decision_id
          AND decision_row.public_id = NEW.payment_eligibility_decision_public_id
          AND decision_row.source_quote_id = NEW.source_quote_id
          AND decision_row.source_quote_public_id = NEW.source_quote_public_id
          AND decision_row.user_id = NEW.user_id
          AND decision_row.action_snapshot = quote_row.action_snapshot
          AND decision_row.currency_snapshot = NEW.currency
          AND decision_row.amount_irr_snapshot = NEW.amount_irr
          AND decision_row.configuration_snapshot_hash = NEW.payment_eligibility_configuration_hash
          AND decision_row.created_at <= NEW.created_at;
        IF valid_decision_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one matching PAY-001 eligibility decision.'; END IF;

        SELECT COUNT(*) INTO valid_method_count
        FROM payment_method_eligibility_decision_methods decision_method
        INNER JOIN payment_method_versions method_version ON method_version.id = decision_method.payment_method_version_id
        WHERE decision_method.payment_method_eligibility_decision_id = NEW.payment_eligibility_decision_id
          AND decision_method.payment_method_version_id = NEW.payment_method_version_id
          AND decision_method.method_code = NEW.payment_method_code
          AND decision_method.method_version = NEW.payment_method_version
          AND decision_method.route_order IS NOT NULL
          AND decision_method.reason_code = 'eligible'
          AND decision_method.configuration_snapshot_hash = NEW.payment_eligibility_method_configuration_hash
          AND method_version.method_code = NEW.payment_method_code
          AND method_version.version = NEW.payment_method_version
          AND method_version.configuration_snapshot_hash = NEW.payment_method_configuration_hash
          AND NEW.provider_code = NEW.payment_method_code;
        IF valid_method_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase intent requires one selected PAY-001 payment method version.'; END IF;
    ELSE
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent purpose is unsupported.';
    END IF;
END
SQL);
    }

    private function createConflictAwarePaymentIntentGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;
    DECLARE valid_wallet_cancel_count INT DEFAULT 0;
    DECLARE nowpayments_finished_conflict_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.creation_key <=> OLD.creation_key)
       OR NOT (NEW.payload_hash <=> OLD.payload_hash)
       OR NOT (NEW.purpose <=> OLD.purpose)
       OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.wallet_account_id <=> OLD.wallet_account_id)
       OR NOT (NEW.source_quote_id <=> OLD.source_quote_id)
       OR NOT (NEW.source_quote_public_id <=> OLD.source_quote_public_id)
       OR NOT (NEW.source_quote_configuration_hash <=> OLD.source_quote_configuration_hash)
       OR NOT (NEW.payment_eligibility_decision_id <=> OLD.payment_eligibility_decision_id)
       OR NOT (NEW.payment_eligibility_decision_public_id <=> OLD.payment_eligibility_decision_public_id)
       OR NOT (NEW.payment_eligibility_configuration_hash <=> OLD.payment_eligibility_configuration_hash)
       OR NOT (NEW.payment_eligibility_method_configuration_hash <=> OLD.payment_eligibility_method_configuration_hash)
       OR NOT (NEW.payment_method_version_id <=> OLD.payment_method_version_id)
       OR NOT (NEW.payment_method_code <=> OLD.payment_method_code)
       OR NOT (NEW.payment_method_version <=> OLD.payment_method_version)
       OR NOT (NEW.payment_method_configuration_hash <=> OLD.payment_method_configuration_hash)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.creation_correlation_id <=> OLD.creation_correlation_id)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent financial identity is immutable.';
    END IF;

    IF NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id)
       AND NOT (NEW.purpose = 'purchase'
                AND OLD.state IN ('captured','partially_refunded')
                AND NEW.state = 'refund_pending') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent refund authority pointer can change only when a new purchase refund enters pending transition.';
    END IF;

    IF OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' THEN
        SELECT COUNT(*) INTO valid_wallet_cancel_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN wallet_holds hold_row ON hold_row.id = reservation_row.wallet_hold_id
        WHERE reservation_row.payment_intent_id = OLD.id
          AND OLD.purpose = 'purchase'
          AND OLD.provider_code = 'wallet'
          AND OLD.payment_method_code = 'wallet'
          AND OLD.wallet_account_id IS NULL
          AND hold_row.ledger_account_id = reservation_row.wallet_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = OLD.public_id
          AND hold_row.status = 'released'
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );

        IF valid_wallet_cancel_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase cancellation requires its exact released hold and no settlement.';
        END IF;
    END IF;

    IF (OLD.state IN ('failed','expired') AND NEW.state = 'pending_manual_review')
       OR (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','failed')) THEN
        SELECT COUNT(*) INTO nowpayments_finished_conflict_count
        FROM nowpayments_payment_authorities authority_row
        WHERE authority_row.payment_intent_id = OLD.id
          AND OLD.purpose = 'purchase'
          AND OLD.provider_code = 'nowpayments'
          AND OLD.payment_method_code = 'nowpayments'
          AND authority_row.state = 'manual_review'
          AND authority_row.provider_payment_id IS NOT NULL
          AND authority_row.provider_status = 'finished'
          AND EXISTS (
              SELECT 1
              FROM nowpayments_reconciliation_findings finding_row
              INNER JOIN nowpayments_payment_observations observation_row
                  ON observation_row.nowpayments_payment_authority_id = finding_row.nowpayments_payment_authority_id
                 AND observation_row.event_type = 'status_lookup'
                 AND observation_row.provider_payment_id = authority_row.provider_payment_id
                 AND observation_row.provider_status = 'finished'
                 AND observation_row.response_hash = finding_row.evidence_hash
              WHERE finding_row.nowpayments_payment_authority_id = authority_row.id
                AND finding_row.code = 'terminal_local_state_conflicts_with_finished_provider'
                AND finding_row.severity = 'critical'
                AND finding_row.provider_status = 'finished'
          )
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );
    END IF;

    IF OLD.state IN ('failed','expired') AND NEW.state = 'pending_manual_review'
       AND nowpayments_finished_conflict_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments intent can reopen only for one exact finished-provider reconciliation conflict.';
    END IF;

    IF OLD.state = 'pending_manual_review'
       AND NEW.state IN ('verifying','failed')
       AND nowpayments_finished_conflict_count = 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Terminal NOWPayments finished conflict keeps its PaymentIntent in manual review until settlement.';
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' AND valid_wallet_cancel_count = 1) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state = 'refund_pending') OR
        (OLD.state IN ('failed','expired') AND NEW.state = 'pending_manual_review' AND nowpayments_finished_conflict_count = 1)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state <> 'captured' AND NEW.state = 'captured' THEN
        SELECT COUNT(*) INTO purchase_settlement_count
        FROM purchase_settlements
        WHERE payment_intent_id = NEW.id;

        IF purchase_settlement_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase capture requires one authoritative purchase settlement.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND NEW.state = 'refund_pending' AND OLD.state IN ('captured','partially_refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL
           OR (OLD.latest_purchase_refund_id IS NOT NULL AND NEW.latest_purchase_refund_id = OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a new authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(refund_row.resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds refund_row
        INNER JOIN purchase_settlements settlement_row ON settlement_row.id = refund_row.purchase_settlement_id
        WHERE refund_row.id = NEW.latest_purchase_refund_id
          AND refund_row.payment_intent_id = NEW.id
          AND refund_row.user_id = NEW.user_id
          AND settlement_row.payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a matching authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state = 'refund_pending' AND NEW.state IN ('partially_refunded','refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL OR NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion must preserve its authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds
        WHERE id = NEW.latest_purchase_refund_id
          AND payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 OR pointed_result_state <> NEW.state THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion state must match its authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose <> 'purchase' AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-purchase payment intent cannot carry purchase refund authority.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund lifecycle requires authoritative refund linkage.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state NOT IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-refund purchase state cannot carry purchase refund authority.';
    END IF;

    IF NEW.state IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment lifecycle requires captured_at.';
    END IF;
    IF NEW.state NOT IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-capture payment intent cannot carry captured_at.';
    END IF;
    IF OLD.captured_at IS NOT NULL AND NOT (NEW.captured_at <=> OLD.captured_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent capture timestamp is immutable.';
    END IF;
END
SQL);
    }

    private function createPriorPaymentIntentGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE TRIGGER payment_intents_update_guard
BEFORE UPDATE ON payment_intents
FOR EACH ROW
BEGIN
    DECLARE purchase_settlement_count INT DEFAULT 0;
    DECLARE valid_refund_pointer_count INT DEFAULT 0;
    DECLARE pointed_result_state VARCHAR(32) DEFAULT NULL;
    DECLARE valid_wallet_cancel_count INT DEFAULT 0;

    IF NOT (NEW.public_id <=> OLD.public_id)
       OR NOT (NEW.creation_key <=> OLD.creation_key)
       OR NOT (NEW.payload_hash <=> OLD.payload_hash)
       OR NOT (NEW.purpose <=> OLD.purpose)
       OR NOT (NEW.user_id <=> OLD.user_id)
       OR NOT (NEW.wallet_account_id <=> OLD.wallet_account_id)
       OR NOT (NEW.source_quote_id <=> OLD.source_quote_id)
       OR NOT (NEW.source_quote_public_id <=> OLD.source_quote_public_id)
       OR NOT (NEW.source_quote_configuration_hash <=> OLD.source_quote_configuration_hash)
       OR NOT (NEW.payment_eligibility_decision_id <=> OLD.payment_eligibility_decision_id)
       OR NOT (NEW.payment_eligibility_decision_public_id <=> OLD.payment_eligibility_decision_public_id)
       OR NOT (NEW.payment_eligibility_configuration_hash <=> OLD.payment_eligibility_configuration_hash)
       OR NOT (NEW.payment_eligibility_method_configuration_hash <=> OLD.payment_eligibility_method_configuration_hash)
       OR NOT (NEW.payment_method_version_id <=> OLD.payment_method_version_id)
       OR NOT (NEW.payment_method_code <=> OLD.payment_method_code)
       OR NOT (NEW.payment_method_version <=> OLD.payment_method_version)
       OR NOT (NEW.payment_method_configuration_hash <=> OLD.payment_method_configuration_hash)
       OR NOT (NEW.provider_code <=> OLD.provider_code)
       OR NOT (NEW.amount_irr <=> OLD.amount_irr)
       OR NOT (NEW.currency <=> OLD.currency)
       OR NOT (NEW.creation_correlation_id <=> OLD.creation_correlation_id)
       OR NOT (NEW.created_at <=> OLD.created_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent financial identity is immutable.';
    END IF;

    IF NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id)
       AND NOT (NEW.purpose = 'purchase'
                AND OLD.state IN ('captured','partially_refunded')
                AND NEW.state = 'refund_pending') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent refund authority pointer can change only when a new purchase refund enters pending transition.';
    END IF;

    IF OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' THEN
        SELECT COUNT(*) INTO valid_wallet_cancel_count
        FROM purchase_wallet_reservations reservation_row
        INNER JOIN wallet_holds hold_row ON hold_row.id = reservation_row.wallet_hold_id
        WHERE reservation_row.payment_intent_id = OLD.id
          AND OLD.purpose = 'purchase'
          AND OLD.provider_code = 'wallet'
          AND OLD.payment_method_code = 'wallet'
          AND OLD.wallet_account_id IS NULL
          AND hold_row.ledger_account_id = reservation_row.wallet_account_id
          AND hold_row.source_type = 'payment_intent'
          AND hold_row.source_id = OLD.public_id
          AND hold_row.status = 'released'
          AND NOT EXISTS (
              SELECT 1
              FROM purchase_settlements settlement_row
              WHERE settlement_row.payment_intent_id = OLD.id
          );

        IF valid_wallet_cancel_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Wallet purchase cancellation requires its exact released hold and no settlement.';
        END IF;
    END IF;

    IF NEW.state <> OLD.state AND NOT (
        (OLD.state = 'created' AND NEW.state IN ('awaiting_user_action','canceled')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state IN ('submitted','expired')) OR
        (OLD.state = 'awaiting_user_action' AND NEW.state = 'canceled' AND valid_wallet_cancel_count = 1) OR
        (OLD.state = 'submitted' AND NEW.state IN ('verifying','failed')) OR
        (OLD.state = 'verifying' AND NEW.state IN ('pending_manual_review','authorized','captured','failed')) OR
        (OLD.state = 'pending_manual_review' AND NEW.state IN ('verifying','captured','failed')) OR
        (OLD.state = 'authorized' AND NEW.state = 'captured') OR
        (OLD.state = 'captured' AND NEW.state = 'refund_pending') OR
        (OLD.state = 'refund_pending' AND NEW.state IN ('refunded','partially_refunded')) OR
        (OLD.state = 'partially_refunded' AND NEW.state = 'refund_pending')
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent state transition is invalid.';
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state <> 'captured' AND NEW.state = 'captured' THEN
        SELECT COUNT(*) INTO purchase_settlement_count
        FROM purchase_settlements
        WHERE payment_intent_id = NEW.id;

        IF purchase_settlement_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase capture requires one authoritative purchase settlement.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND NEW.state = 'refund_pending' AND OLD.state IN ('captured','partially_refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL
           OR (OLD.latest_purchase_refund_id IS NOT NULL AND NEW.latest_purchase_refund_id = OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a new authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(refund_row.resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds refund_row
        INNER JOIN purchase_settlements settlement_row ON settlement_row.id = refund_row.purchase_settlement_id
        WHERE refund_row.id = NEW.latest_purchase_refund_id
          AND refund_row.payment_intent_id = NEW.id
          AND refund_row.user_id = NEW.user_id
          AND settlement_row.payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund pending transition requires a matching authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose = 'purchase' AND OLD.state = 'refund_pending' AND NEW.state IN ('partially_refunded','refunded') THEN
        IF NEW.latest_purchase_refund_id IS NULL OR NOT (NEW.latest_purchase_refund_id <=> OLD.latest_purchase_refund_id) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion must preserve its authoritative refund pointer.';
        END IF;

        SELECT COUNT(*), MAX(resulting_payment_state)
        INTO valid_refund_pointer_count, pointed_result_state
        FROM purchase_refunds
        WHERE id = NEW.latest_purchase_refund_id
          AND payment_intent_id = NEW.id;

        IF valid_refund_pointer_count <> 1 OR pointed_result_state <> NEW.state THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund completion state must match its authoritative refund record.';
        END IF;
    END IF;

    IF NEW.purpose <> 'purchase' AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-purchase payment intent cannot carry purchase refund authority.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purchase refund lifecycle requires authoritative refund linkage.';
    END IF;

    IF NEW.purpose = 'purchase'
       AND NEW.state NOT IN ('refund_pending','partially_refunded','refunded')
       AND NEW.latest_purchase_refund_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-refund purchase state cannot carry purchase refund authority.';
    END IF;

    IF NEW.state IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Captured payment lifecycle requires captured_at.';
    END IF;
    IF NEW.state NOT IN ('captured','refund_pending','refunded','partially_refunded') AND NEW.captured_at IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Pre-capture payment intent cannot carry captured_at.';
    END IF;
    IF OLD.captured_at IS NOT NULL AND NOT (NEW.captured_at <=> OLD.captured_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Payment intent capture timestamp is immutable.';
    END IF;
END
SQL);
    }
};
