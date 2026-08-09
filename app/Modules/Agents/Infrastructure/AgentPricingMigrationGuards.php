<?php

declare(strict_types=1);

namespace App\Modules\Agents\Infrastructure;

use Illuminate\Support\Facades\DB;

final class AgentPricingMigrationGuards
{
    public static function create(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_profiles_update_guard
BEFORE UPDATE ON agent_pricing_profiles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile identity is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_profiles_delete_guard
BEFORE DELETE ON agent_pricing_profiles FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile identity cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_profile_versions_insert_guard
BEFORE INSERT ON agent_pricing_profile_versions FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED;

    IF NOT EXISTS (SELECT 1 FROM agent_pricing_profiles WHERE id = NEW.agent_pricing_profile_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile parent does not exist.';
    END IF;

    SELECT COALESCE(MAX(version), 0) INTO latest_version
      FROM agent_pricing_profile_versions WHERE agent_pricing_profile_id = NEW.agent_pricing_profile_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile version is not sequential.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile configuration hash mismatch.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_profile_versions_update_guard
BEFORE UPDATE ON agent_pricing_profile_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile version is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_profile_versions_delete_guard
BEFORE DELETE ON agent_pricing_profile_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing profile version cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_rules_update_guard
BEFORE UPDATE ON agent_pricing_rules FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule identity is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_rules_delete_guard
BEFORE DELETE ON agent_pricing_rules FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule identity cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_rule_versions_insert_guard
BEFORE INSERT ON agent_pricing_rule_versions FOR EACH ROW
BEGIN
    DECLARE latest_version BIGINT UNSIGNED;
    DECLARE parent_profile BIGINT UNSIGNED;
    DECLARE offering_product BIGINT UNSIGNED;
    DECLARE offering_server BIGINT UNSIGNED;

    SELECT agent_pricing_profile_id INTO parent_profile
      FROM agent_pricing_rules WHERE id = NEW.agent_pricing_rule_id;
    IF parent_profile IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule parent does not exist.';
    END IF;

    SELECT COALESCE(MAX(version), 0) INTO latest_version
      FROM agent_pricing_rule_versions WHERE agent_pricing_rule_id = NEW.agent_pricing_rule_id;
    IF NEW.version <> latest_version + 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule version is not sequential.';
    END IF;

    IF NEW.plan_offering_id IS NOT NULL THEN
        SELECT product_id, sales_server_id INTO offering_product, offering_server
          FROM plan_offerings WHERE id = NEW.plan_offering_id;
        IF offering_product IS NULL OR offering_server IS NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule offering does not exist.';
        END IF;
        IF NEW.product_id IS NOT NULL AND NEW.product_id <> offering_product THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing offering/product scope is inconsistent.';
        END IF;
        IF NEW.sales_server_id IS NOT NULL AND NEW.sales_server_id <> offering_server THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing offering/server scope is inconsistent.';
        END IF;
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule configuration hash mismatch.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_rule_versions_update_guard
BEFORE UPDATE ON agent_pricing_rule_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule version is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_rule_versions_delete_guard
BEFORE DELETE ON agent_pricing_rule_versions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing rule version cannot be deleted.';
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_resolutions_insert_guard
BEFORE INSERT ON agent_pricing_resolutions FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM users u
        INNER JOIN agent_profiles a ON a.user_id = u.id
        WHERE u.id = NEW.user_id
          AND u.account_type = 'agent'
          AND u.account_status = 'active'
          AND a.id = NEW.agent_profile_id
          AND a.status = 'active'
          AND a.pricing_profile_code = NEW.pricing_profile_code_snapshot
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution subject is not an active current-profile agent.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM agent_pricing_profile_versions v
        INNER JOIN agent_pricing_profiles p ON p.id = v.agent_pricing_profile_id
        WHERE v.id = NEW.agent_pricing_profile_version_id
          AND p.id = NEW.agent_pricing_profile_id
          AND p.public_id = NEW.pricing_profile_public_id_snapshot
          AND p.profile_code = NEW.pricing_profile_code_snapshot
          AND v.version = NEW.pricing_profile_version
          AND v.version = (
              SELECT MAX(current_profile_version.version)
              FROM agent_pricing_profile_versions current_profile_version
              WHERE current_profile_version.agent_pricing_profile_id = p.id
          )
          AND v.state = 'active'
          AND v.configuration_hash = NEW.pricing_profile_configuration_hash
          AND v.discount_combination_allowed = NEW.discount_combination_allowed
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution profile identity mismatch.';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM plan_offerings o
        WHERE o.id = NEW.plan_offering_id
          AND o.product_id = NEW.product_id_snapshot
          AND o.sales_server_id = NEW.sales_server_id_snapshot
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution offering scope mismatch.';
    END IF;

    IF NEW.agent_pricing_rule_version_id IS NOT NULL AND NOT EXISTS (
        SELECT 1
        FROM agent_pricing_rule_versions v
        INNER JOIN agent_pricing_rules r ON r.id = v.agent_pricing_rule_id
        WHERE v.id = NEW.agent_pricing_rule_version_id
          AND r.id = NEW.agent_pricing_rule_id
          AND r.agent_pricing_profile_id = NEW.agent_pricing_profile_id
          AND r.public_id = NEW.rule_public_id_snapshot
          AND r.rule_code = NEW.rule_code_snapshot
          AND v.version = NEW.rule_version
          AND v.version = (
              SELECT MAX(current_rule_version.version)
              FROM agent_pricing_rule_versions current_rule_version
              WHERE current_rule_version.agent_pricing_rule_id = r.id
          )
          AND v.state = 'active'
          AND v.configuration_hash = NEW.rule_configuration_hash
          AND v.override_price_irr = NEW.override_price_irr
          AND (v.action IS NULL OR v.action = NEW.action)
          AND (v.plan_offering_id IS NULL OR v.plan_offering_id = NEW.plan_offering_id)
          AND (v.product_id IS NULL OR v.product_id = NEW.product_id_snapshot)
          AND (v.sales_server_id IS NULL OR v.sales_server_id = NEW.sales_server_id_snapshot)
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution rule identity mismatch.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_snapshot_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution snapshot hash mismatch.';
    END IF;
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_resolutions_update_guard
BEFORE UPDATE ON agent_pricing_resolutions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution is immutable.';
END
SQL);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER agent_price_resolutions_delete_guard
BEFORE DELETE ON agent_pricing_resolutions FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Agent pricing resolution cannot be deleted.';
END
SQL);
    }

    public static function drop(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_resolutions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_resolutions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_resolutions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_rule_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_rule_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_rule_versions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_rules_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_rules_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_profile_versions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_profile_versions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_profile_versions_insert_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_profiles_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS agent_price_profiles_update_guard');
    }
}
