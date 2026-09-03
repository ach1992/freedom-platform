<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'benefit_code_discount_quote_consumptions';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            if ($this->isReady()) {
                return;
            }
            if (DB::table(self::TABLE)->exists()) {
                throw new RuntimeException('Benefit discount Quote authority cannot repair a non-empty incomplete surface.');
            }
            $this->dropTriggers();
            Schema::drop('benefit_code_discount_quote_consumptions');
        }

        Schema::create('benefit_code_discount_quote_consumptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('public_id')->unique();
            $table->string('consumption_key', 128)->unique();
            $table->char('request_payload_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('benefit_code_discount_grant_id');
            $table->unsignedBigInteger('pricing_rule_resolution_id');
            $table->unsignedBigInteger('source_quote_id');
            $table->unsignedBigInteger('discounted_quote_id');
            $table->unique('benefit_code_discount_grant_id', 'bdqc_grant_uq');
            $table->unique('pricing_rule_resolution_id', 'bdqc_resolution_uq');
            $table->unique('source_quote_id', 'bdqc_source_quote_uq');
            $table->unique('discounted_quote_id', 'bdqc_discount_quote_uq');
            $table->foreign('user_id', 'bdqc_user_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('benefit_code_discount_grant_id', 'bdqc_grant_fk')->references('id')->on('benefit_code_discount_grants')->restrictOnDelete();
            $table->foreign('pricing_rule_resolution_id', 'bdqc_resolution_fk')->references('id')->on('pricing_rule_resolutions')->restrictOnDelete();
            $table->foreign('source_quote_id', 'bdqc_source_quote_fk')->references('id')->on('quotes')->restrictOnDelete();
            $table->foreign('discounted_quote_id', 'bdqc_discount_quote_fk')->references('id')->on('quotes')->restrictOnDelete();
            $table->string('rule_code_snapshot', 128);
            $table->bigInteger('discount_irr');
            $table->char('grant_configuration_hash', 64);
            $table->char('pricing_rule_resolution_configuration_hash', 64);
            $table->char('source_quote_configuration_hash', 64);
            $table->char('discounted_quote_configuration_hash', 64);
            $table->json('configuration_snapshot');
            $table->char('configuration_hash', 64);
            $table->string('correlation_id', 64);
            $table->dateTime('created_at', 6);
            $table->index(['user_id', 'created_at'], 'benefit_discount_quote_consumption_user_idx');
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT benefit_discount_quote_consumption_amount_chk CHECK (`discount_irr` >= 1)');
        DB::statement("ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT benefit_discount_quote_consumption_hashes_chk CHECK (`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `grant_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `pricing_rule_resolution_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `source_quote_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `discounted_quote_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT benefit_discount_quote_consumption_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) <= 16 AND OCTET_LENGTH(`configuration_snapshot`) < 8192)");

        DB::unprepared(<<<'SQL'
CREATE TRIGGER benefit_discount_quote_consumptions_insert_guard
BEFORE INSERT ON benefit_code_discount_quote_consumptions FOR EACH ROW
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM benefit_code_discount_grants grant_record
        INNER JOIN benefit_code_redemptions redemption
            ON redemption.id = grant_record.benefit_code_redemption_id
        INNER JOIN pricing_rule_resolutions resolution
            ON resolution.id = NEW.pricing_rule_resolution_id
        INNER JOIN quotes source_quote
            ON source_quote.id = NEW.source_quote_id
        INNER JOIN quotes discounted_quote
            ON discounted_quote.id = NEW.discounted_quote_id
        WHERE grant_record.id = NEW.benefit_code_discount_grant_id
          AND grant_record.user_id = NEW.user_id
          AND grant_record.configuration_hash = NEW.grant_configuration_hash
          AND redemption.user_id = NEW.user_id
          AND redemption.type_snapshot = 'discount_grant'
          AND redemption.plan_offering_id = source_quote.plan_offering_id
          AND grant_record.pricing_rule_id = resolution.pricing_rule_id
          AND grant_record.pricing_rule_version_id = resolution.pricing_rule_version_id
          AND grant_record.rule_code_snapshot = NEW.rule_code_snapshot
          AND grant_record.rule_code_snapshot = resolution.rule_code_snapshot
          AND grant_record.rule_version = resolution.rule_version
          AND grant_record.rule_configuration_hash = resolution.rule_configuration_hash
          AND resolution.user_id = NEW.user_id
          AND resolution.plan_offering_id = source_quote.plan_offering_id
          AND resolution.action = 'purchase'
          AND resolution.input_price_irr = source_quote.effective_price_irr
          AND resolution.discount_irr = NEW.discount_irr
          AND resolution.configuration_snapshot_hash = NEW.pricing_rule_resolution_configuration_hash
          AND source_quote.user_id = NEW.user_id
          AND source_quote.account_type_snapshot = 'customer'
          AND source_quote.action_snapshot = 'purchase'
          AND source_quote.currency = 'IRR'
          AND source_quote.discount_reference_code IS NULL
          AND source_quote.discount_irr = 0
          AND source_quote.configuration_snapshot_hash = NEW.source_quote_configuration_hash
          AND discounted_quote.user_id = NEW.user_id
          AND discounted_quote.account_type_snapshot = 'customer'
          AND discounted_quote.action_snapshot = 'purchase'
          AND discounted_quote.plan_offering_id = source_quote.plan_offering_id
          AND discounted_quote.offering_version = source_quote.offering_version
          AND discounted_quote.offering_configuration_hash = source_quote.offering_configuration_hash
          AND discounted_quote.base_price_irr = source_quote.base_price_irr
          AND discounted_quote.effective_price_irr = source_quote.effective_price_irr
          AND discounted_quote.discount_reference_code = NEW.rule_code_snapshot
          AND discounted_quote.discount_irr = NEW.discount_irr
          AND discounted_quote.final_price_irr = source_quote.effective_price_irr - NEW.discount_irr
          AND discounted_quote.currency = 'IRR'
          AND discounted_quote.configuration_snapshot_hash = NEW.discounted_quote_configuration_hash
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption identity mismatch.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption hash mismatch.';
    END IF;
END
SQL);
        DB::unprepared("CREATE TRIGGER benefit_discount_quote_consumptions_update_guard BEFORE UPDATE ON benefit_code_discount_quote_consumptions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption is immutable.'; END");
        DB::unprepared("CREATE TRIGGER benefit_discount_quote_consumptions_delete_guard BEFORE DELETE ON benefit_code_discount_quote_consumptions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption cannot be deleted.'; END");
        if (! $this->isReady()) {
            throw new RuntimeException('Benefit discount Quote authority did not reach its exact database surface.');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }
        if (DB::table(self::TABLE)->exists()) {
            throw new RuntimeException('Benefit discount Quote authority cannot be removed while durable consumptions exist.');
        }
        $this->dropTriggers();
        Schema::drop('benefit_code_discount_quote_consumptions');
    }

    private function dropTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::unprepared('DROP TRIGGER IF EXISTS benefit_discount_quote_consumptions_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS benefit_discount_quote_consumptions_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS benefit_discount_quote_consumptions_insert_guard');
    }

    private function isReady(): bool
    {
        if (! Schema::hasTable(self::TABLE)) {
            return false;
        }
        foreach ([
            'id', 'public_id', 'consumption_key', 'request_payload_hash', 'user_id',
            'benefit_code_discount_grant_id', 'pricing_rule_resolution_id', 'source_quote_id',
            'discounted_quote_id', 'rule_code_snapshot', 'discount_irr', 'grant_configuration_hash',
            'pricing_rule_resolution_configuration_hash', 'source_quote_configuration_hash',
            'discounted_quote_configuration_hash', 'configuration_snapshot', 'configuration_hash',
            'correlation_id', 'created_at',
        ] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return false;
            }
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        $database = DB::connection()->getDatabaseName();

        return $this->constraintsReady($database)
            && $this->indexesReady($database)
            && $this->triggersReady($database);
    }

    private function constraintsReady(string $database): bool
    {
        $expected = [
            'bdqc_discount_quote_fk' => 'FOREIGN KEY',
            'bdqc_grant_fk' => 'FOREIGN KEY',
            'bdqc_resolution_fk' => 'FOREIGN KEY',
            'bdqc_source_quote_fk' => 'FOREIGN KEY',
            'bdqc_user_fk' => 'FOREIGN KEY',
            'benefit_discount_quote_consumption_amount_chk' => 'CHECK',
            'benefit_discount_quote_consumption_hashes_chk' => 'CHECK',
            'benefit_discount_quote_consumption_snapshot_chk' => 'CHECK',
        ];
        $rows = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', self::TABLE)
            ->whereIn('CONSTRAINT_NAME', array_keys($expected))
            ->get(['CONSTRAINT_NAME', 'CONSTRAINT_TYPE']);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row->CONSTRAINT_NAME] = (string) $row->CONSTRAINT_TYPE;
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        if ($actual !== $expected) {
            return false;
        }

        $expectedForeignKeys = [
            'bdqc_discount_quote_fk' => ['discounted_quote_id', 'quotes', 'id'],
            'bdqc_grant_fk' => ['benefit_code_discount_grant_id', 'benefit_code_discount_grants', 'id'],
            'bdqc_resolution_fk' => ['pricing_rule_resolution_id', 'pricing_rule_resolutions', 'id'],
            'bdqc_source_quote_fk' => ['source_quote_id', 'quotes', 'id'],
            'bdqc_user_fk' => ['user_id', 'users', 'id'],
        ];
        $foreignKeyRows = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', self::TABLE)
            ->whereIn('CONSTRAINT_NAME', array_keys($expectedForeignKeys))
            ->get(['CONSTRAINT_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME']);
        $actualForeignKeys = [];
        foreach ($foreignKeyRows as $row) {
            $actualForeignKeys[(string) $row->CONSTRAINT_NAME] = [
                (string) $row->COLUMN_NAME,
                (string) $row->REFERENCED_TABLE_NAME,
                (string) $row->REFERENCED_COLUMN_NAME,
            ];
        }
        ksort($expectedForeignKeys, SORT_STRING);
        ksort($actualForeignKeys, SORT_STRING);

        return $actualForeignKeys === $expectedForeignKeys;
    }

    private function indexesReady(string $database): bool
    {
        $expected = [
            'bdqc_discount_quote_uq' => [0, ['discounted_quote_id']],
            'bdqc_grant_uq' => [0, ['benefit_code_discount_grant_id']],
            'bdqc_resolution_uq' => [0, ['pricing_rule_resolution_id']],
            'bdqc_source_quote_uq' => [0, ['source_quote_id']],
            'benefit_code_discount_quote_consumptions_consumption_key_unique' => [0, ['consumption_key']],
            'benefit_code_discount_quote_consumptions_public_id_unique' => [0, ['public_id']],
            'benefit_discount_quote_consumption_user_idx' => [1, ['user_id', 'created_at']],
        ];
        $rows = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', self::TABLE)
            ->whereIn('INDEX_NAME', array_keys($expected))
            ->orderBy('INDEX_NAME')
            ->orderBy('SEQ_IN_INDEX')
            ->get(['INDEX_NAME', 'COLUMN_NAME', 'NON_UNIQUE']);
        $actual = [];
        foreach ($rows as $row) {
            $name = (string) $row->INDEX_NAME;
            $actual[$name] ??= [(int) $row->NON_UNIQUE, []];
            $actual[$name][1][] = (string) $row->COLUMN_NAME;
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        return $actual === $expected;
    }

    private function triggersReady(string $database): bool
    {
        $expected = [
            'benefit_discount_quote_consumptions_delete_guard' => ['BEFORE', 'DELETE'],
            'benefit_discount_quote_consumptions_insert_guard' => ['BEFORE', 'INSERT'],
            'benefit_discount_quote_consumptions_update_guard' => ['BEFORE', 'UPDATE'],
        ];
        $rows = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('EVENT_OBJECT_TABLE', self::TABLE)
            ->whereIn('TRIGGER_NAME', array_keys($expected))
            ->get(['TRIGGER_NAME', 'ACTION_TIMING', 'EVENT_MANIPULATION']);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row->TRIGGER_NAME] = [(string) $row->ACTION_TIMING, (string) $row->EVENT_MANIPULATION];
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        return $actual === $expected;
    }
};
