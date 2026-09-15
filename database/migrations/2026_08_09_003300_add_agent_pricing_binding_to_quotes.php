<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @requirement AGT-005 BUY-002 DAT-002 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            $table->foreignId('agent_pricing_resolution_id')->nullable()->after('configuration_snapshot_hash');
            $table->ulid('agent_pricing_resolution_public_id')->nullable()->after('agent_pricing_resolution_id');
            $table->char('agent_pricing_resolution_configuration_hash', 64)->nullable()->after('agent_pricing_resolution_public_id');
            $table->foreignId('agent_profile_id_snapshot')->nullable()->after('agent_pricing_resolution_configuration_hash');
            $table->foreignId('agent_pricing_profile_id_snapshot')->nullable()->after('agent_profile_id_snapshot');
            $table->ulid('agent_pricing_profile_public_id_snapshot')->nullable()->after('agent_pricing_profile_id_snapshot');
            $table->string('agent_pricing_profile_code_snapshot', 64)->nullable()->after('agent_pricing_profile_public_id_snapshot');
            $table->unsignedBigInteger('agent_pricing_profile_version_snapshot')->nullable()->after('agent_pricing_profile_code_snapshot');
            $table->char('agent_pricing_profile_configuration_hash', 64)->nullable()->after('agent_pricing_profile_version_snapshot');
            $table->string('agent_pricing_action_snapshot', 32)->nullable()->after('agent_pricing_profile_configuration_hash');
            $table->foreignId('agent_pricing_rule_id_snapshot')->nullable()->after('agent_pricing_action_snapshot');
            $table->ulid('agent_pricing_rule_public_id_snapshot')->nullable()->after('agent_pricing_rule_id_snapshot');
            $table->string('agent_pricing_rule_code_snapshot', 128)->nullable()->after('agent_pricing_rule_public_id_snapshot');
            $table->unsignedBigInteger('agent_pricing_rule_version_snapshot')->nullable()->after('agent_pricing_rule_code_snapshot');
            $table->char('agent_pricing_rule_configuration_hash', 64)->nullable()->after('agent_pricing_rule_version_snapshot');
            $table->boolean('agent_discount_combination_allowed')->nullable()->after('agent_pricing_rule_configuration_hash');

            $table->foreign('agent_pricing_resolution_id', 'quotes_agent_resolution_fk')
                ->references('id')->on('agent_pricing_resolutions')->restrictOnDelete();
            $table->foreign('agent_profile_id_snapshot', 'quotes_agent_profile_fk')
                ->references('id')->on('agent_profiles')->restrictOnDelete();
            $table->foreign('agent_pricing_profile_id_snapshot', 'quotes_agent_price_profile_fk')
                ->references('id')->on('agent_pricing_profiles')->restrictOnDelete();
            $table->foreign('agent_pricing_rule_id_snapshot', 'quotes_agent_price_rule_fk')
                ->references('id')->on('agent_pricing_rules')->restrictOnDelete();
            $table->unique('agent_pricing_resolution_id', 'quotes_agent_resolution_unique');
            $table->index(['agent_pricing_profile_id_snapshot', 'created_at'], 'quotes_agent_profile_idx');
        });

        DB::statement(<<<'SQL'
ALTER TABLE quotes ADD CONSTRAINT quotes_agent_pricing_binding_shape_chk CHECK (
    (`agent_pricing_resolution_id` IS NULL
        AND `agent_pricing_resolution_public_id` IS NULL
        AND `agent_pricing_resolution_configuration_hash` IS NULL
        AND `agent_profile_id_snapshot` IS NULL
        AND `agent_pricing_profile_id_snapshot` IS NULL
        AND `agent_pricing_profile_public_id_snapshot` IS NULL
        AND `agent_pricing_profile_code_snapshot` IS NULL
        AND `agent_pricing_profile_version_snapshot` IS NULL
        AND `agent_pricing_profile_configuration_hash` IS NULL
        AND `agent_pricing_action_snapshot` IS NULL
        AND `agent_pricing_rule_id_snapshot` IS NULL
        AND `agent_pricing_rule_public_id_snapshot` IS NULL
        AND `agent_pricing_rule_code_snapshot` IS NULL
        AND `agent_pricing_rule_version_snapshot` IS NULL
        AND `agent_pricing_rule_configuration_hash` IS NULL
        AND `agent_discount_combination_allowed` IS NULL)
    OR
    (`agent_pricing_resolution_id` IS NOT NULL
        AND `account_type_snapshot` = 'agent'
        AND `agent_pricing_resolution_public_id` IS NOT NULL
        AND `agent_pricing_resolution_configuration_hash` IS NOT NULL
        AND `agent_profile_id_snapshot` IS NOT NULL
        AND `agent_pricing_profile_id_snapshot` IS NOT NULL
        AND `agent_pricing_profile_public_id_snapshot` IS NOT NULL
        AND `agent_pricing_profile_code_snapshot` IS NOT NULL
        AND `agent_pricing_profile_version_snapshot` >= 1
        AND `agent_pricing_profile_configuration_hash` IS NOT NULL
        AND `agent_pricing_action_snapshot` IS NOT NULL
        AND `agent_discount_combination_allowed` IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE quotes ADD CONSTRAINT quotes_agent_pricing_rule_shape_chk CHECK (
    `agent_pricing_resolution_id` IS NULL
    OR
    (`agent_pricing_rule_id_snapshot` IS NULL
        AND `agent_pricing_rule_public_id_snapshot` IS NULL
        AND `agent_pricing_rule_code_snapshot` IS NULL
        AND `agent_pricing_rule_version_snapshot` IS NULL
        AND `agent_pricing_rule_configuration_hash` IS NULL)
    OR
    (`agent_pricing_rule_id_snapshot` IS NOT NULL
        AND `agent_pricing_rule_public_id_snapshot` IS NOT NULL
        AND `agent_pricing_rule_code_snapshot` IS NOT NULL
        AND `agent_pricing_rule_version_snapshot` >= 1
        AND `agent_pricing_rule_configuration_hash` IS NOT NULL)
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE quotes ADD CONSTRAINT quotes_agent_pricing_hashes_chk CHECK (
    `agent_pricing_resolution_id` IS NULL
    OR
    (`agent_pricing_resolution_configuration_hash` REGEXP '^[0-9a-f]{64}$'
        AND `agent_pricing_profile_configuration_hash` REGEXP '^[0-9a-f]{64}$'
        AND (`agent_pricing_rule_configuration_hash` IS NULL OR `agent_pricing_rule_configuration_hash` REGEXP '^[0-9a-f]{64}$'))
)
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE quotes ADD CONSTRAINT quotes_agent_pricing_action_chk CHECK (
    `agent_pricing_action_snapshot` IS NULL
    OR `agent_pricing_action_snapshot` IN ('purchase','renew','add_data','add_days')
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER quotes_agent_pricing_insert_guard
BEFORE INSERT ON quotes
FOR EACH ROW
BEGIN
    IF NEW.account_type_snapshot = 'agent' THEN
        IF NEW.agent_pricing_resolution_id IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent Quote requires an authoritative pricing resolution binding.';
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM agent_pricing_resolutions r
            WHERE r.id = NEW.agent_pricing_resolution_id
              AND r.public_id = NEW.agent_pricing_resolution_public_id
              AND r.configuration_snapshot_hash = NEW.agent_pricing_resolution_configuration_hash
              AND r.user_id = NEW.user_id
              AND r.agent_profile_id = NEW.agent_profile_id_snapshot
              AND r.agent_pricing_profile_id = NEW.agent_pricing_profile_id_snapshot
              AND r.pricing_profile_public_id_snapshot = NEW.agent_pricing_profile_public_id_snapshot
              AND r.pricing_profile_code_snapshot = NEW.agent_pricing_profile_code_snapshot
              AND r.pricing_profile_version = NEW.agent_pricing_profile_version_snapshot
              AND r.pricing_profile_configuration_hash = NEW.agent_pricing_profile_configuration_hash
              AND r.plan_offering_id = NEW.plan_offering_id
              AND r.action = NEW.agent_pricing_action_snapshot
              AND (r.agent_pricing_rule_id <=> NEW.agent_pricing_rule_id_snapshot)
              AND (r.rule_public_id_snapshot <=> NEW.agent_pricing_rule_public_id_snapshot)
              AND (r.rule_code_snapshot <=> NEW.agent_pricing_rule_code_snapshot)
              AND (r.rule_version <=> NEW.agent_pricing_rule_version_snapshot)
              AND (r.rule_configuration_hash <=> NEW.agent_pricing_rule_configuration_hash)
              AND (r.override_price_irr <=> NEW.override_price_irr)
              AND r.discount_combination_allowed = NEW.agent_discount_combination_allowed
        ) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote agent pricing resolution identity mismatch.';
        END IF;

        IF NEW.agent_pricing_rule_id_snapshot IS NULL THEN
            IF NEW.override_source <> 'none'
               OR NEW.override_reference_code IS NOT NULL
               OR NEW.override_price_irr IS NOT NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent Quote no-match must use base pricing without an invented override.';
            END IF;
        ELSE
            IF NEW.override_source <> 'agent'
               OR NEW.override_reference_code <> NEW.agent_pricing_profile_code_snapshot
               OR NEW.override_price_irr IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent Quote override does not match authoritative pricing resolution.';
            END IF;
        END IF;

        IF NEW.discount_irr > 0 AND NEW.agent_discount_combination_allowed <> 1 THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution forbids discount combination.';
        END IF;
    ELSEIF NEW.agent_pricing_resolution_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Non-agent Quote cannot carry an agent pricing resolution.';
    END IF;
END
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS quotes_agent_pricing_insert_guard');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_agent_pricing_action_chk');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_agent_pricing_hashes_chk');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_agent_pricing_rule_shape_chk');
        DB::statement('ALTER TABLE quotes DROP CONSTRAINT quotes_agent_pricing_binding_shape_chk');

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropIndex('quotes_agent_profile_idx');
            $table->dropUnique('quotes_agent_resolution_unique');
            $table->dropForeign('quotes_agent_price_rule_fk');
            $table->dropForeign('quotes_agent_price_profile_fk');
            $table->dropForeign('quotes_agent_profile_fk');
            $table->dropForeign('quotes_agent_resolution_fk');
            $table->dropColumn([
                'agent_pricing_resolution_id',
                'agent_pricing_resolution_public_id',
                'agent_pricing_resolution_configuration_hash',
                'agent_profile_id_snapshot',
                'agent_pricing_profile_id_snapshot',
                'agent_pricing_profile_public_id_snapshot',
                'agent_pricing_profile_code_snapshot',
                'agent_pricing_profile_version_snapshot',
                'agent_pricing_profile_configuration_hash',
                'agent_pricing_action_snapshot',
                'agent_pricing_rule_id_snapshot',
                'agent_pricing_rule_public_id_snapshot',
                'agent_pricing_rule_code_snapshot',
                'agent_pricing_rule_version_snapshot',
                'agent_pricing_rule_configuration_hash',
                'agent_discount_combination_allowed',
            ]);
        });
    }
};
