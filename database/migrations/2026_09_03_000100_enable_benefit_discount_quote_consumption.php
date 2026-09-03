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
        DB::statement("ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT benefit_discount_quote_consumption_snapshot_chk CHECK (JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) = 10 AND OCTET_LENGTH(`configuration_snapshot`) < 8192)");

        $this->createTriggers();
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

    private function createTriggers(): void
    {
        DB::unprepared(
            "CREATE TRIGGER benefit_discount_quote_consumptions_insert_guard\n".
            "BEFORE INSERT ON benefit_code_discount_quote_consumptions FOR EACH ROW\n".
            $this->insertGuardBody(),
        );
        DB::unprepared(
            'CREATE TRIGGER benefit_discount_quote_consumptions_update_guard '.
            'BEFORE UPDATE ON benefit_code_discount_quote_consumptions FOR EACH ROW '.
            $this->updateGuardBody(),
        );
        DB::unprepared(
            'CREATE TRIGGER benefit_discount_quote_consumptions_delete_guard '.
            'BEFORE DELETE ON benefit_code_discount_quote_consumptions FOR EACH ROW '.
            $this->deleteGuardBody(),
        );
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
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $this->requiredColumnsPresent();
        }

        $database = DB::connection()->getDatabaseName();

        return $this->columnsReady($database)
            && $this->constraintsReady($database)
            && $this->indexesReady($database)
            && $this->triggersReady($database);
    }

    private function requiredColumnsPresent(): bool
    {
        foreach (array_keys($this->expectedColumns()) as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                return false;
            }
        }

        return true;
    }

    private function columnsReady(string $database): bool
    {
        $expected = $this->expectedColumns();
        $rows = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', self::TABLE)
            ->orderBy('ORDINAL_POSITION')
            ->get(['COLUMN_NAME', 'ORDINAL_POSITION', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA']);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row->COLUMN_NAME] = [
                (int) $row->ORDINAL_POSITION,
                strtolower((string) $row->COLUMN_TYPE),
                (string) $row->IS_NULLABLE,
                $row->COLUMN_DEFAULT === null ? null : (string) $row->COLUMN_DEFAULT,
                strtolower(trim((string) $row->EXTRA)),
            ];
        }

        return $actual === $expected;
    }

    /** @return array<string,array{int,string,string,string|null,string}> */
    private function expectedColumns(): array
    {
        return [
            'id' => [1, 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
            'public_id' => [2, 'char(26)', 'NO', null, ''],
            'consumption_key' => [3, 'varchar(128)', 'NO', null, ''],
            'request_payload_hash' => [4, 'char(64)', 'NO', null, ''],
            'user_id' => [5, 'bigint(20) unsigned', 'NO', null, ''],
            'benefit_code_discount_grant_id' => [6, 'bigint(20) unsigned', 'NO', null, ''],
            'pricing_rule_resolution_id' => [7, 'bigint(20) unsigned', 'NO', null, ''],
            'source_quote_id' => [8, 'bigint(20) unsigned', 'NO', null, ''],
            'discounted_quote_id' => [9, 'bigint(20) unsigned', 'NO', null, ''],
            'rule_code_snapshot' => [10, 'varchar(128)', 'NO', null, ''],
            'discount_irr' => [11, 'bigint(20)', 'NO', null, ''],
            'grant_configuration_hash' => [12, 'char(64)', 'NO', null, ''],
            'pricing_rule_resolution_configuration_hash' => [13, 'char(64)', 'NO', null, ''],
            'source_quote_configuration_hash' => [14, 'char(64)', 'NO', null, ''],
            'discounted_quote_configuration_hash' => [15, 'char(64)', 'NO', null, ''],
            'configuration_snapshot' => [16, 'longtext', 'NO', null, ''],
            'configuration_hash' => [17, 'char(64)', 'NO', null, ''],
            'correlation_id' => [18, 'varchar(64)', 'NO', null, ''],
            'created_at' => [19, 'datetime(6)', 'NO', null, ''],
        ];
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
        if ($actualForeignKeys !== $expectedForeignKeys) {
            return false;
        }

        $expectedChecks = [
            'benefit_discount_quote_consumption_amount_chk' => '`discount_irr` >= 1',
            'benefit_discount_quote_consumption_hashes_chk' => "`request_payload_hash` REGEXP '^[0-9a-f]{64}$' AND `grant_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `pricing_rule_resolution_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `source_quote_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `discounted_quote_configuration_hash` REGEXP '^[0-9a-f]{64}$' AND `configuration_hash` REGEXP '^[0-9a-f]{64}$'",
            'benefit_discount_quote_consumption_snapshot_chk' => "JSON_VALID(`configuration_snapshot`) AND JSON_TYPE(`configuration_snapshot`) = 'OBJECT' AND JSON_LENGTH(`configuration_snapshot`) = 10 AND OCTET_LENGTH(`configuration_snapshot`) < 8192",
        ];
        $checkRows = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', self::TABLE)
            ->whereIn('CONSTRAINT_NAME', array_keys($expectedChecks))
            ->get(['CONSTRAINT_NAME', 'CHECK_CLAUSE']);
        $actualChecks = [];
        foreach ($checkRows as $row) {
            $actualChecks[(string) $row->CONSTRAINT_NAME] = $this->normalizeSql((string) $row->CHECK_CLAUSE);
        }
        foreach ($expectedChecks as $name => $clause) {
            $expectedChecks[$name] = $this->normalizeSql($clause);
        }
        ksort($expectedChecks, SORT_STRING);
        ksort($actualChecks, SORT_STRING);

        return $actualChecks === $expectedChecks;
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
            'benefit_discount_quote_consumptions_delete_guard' => ['BEFORE', 'DELETE', $this->deleteGuardBody()],
            'benefit_discount_quote_consumptions_insert_guard' => ['BEFORE', 'INSERT', $this->insertGuardBody()],
            'benefit_discount_quote_consumptions_update_guard' => ['BEFORE', 'UPDATE', $this->updateGuardBody()],
        ];
        $rows = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('EVENT_OBJECT_TABLE', self::TABLE)
            ->get(['TRIGGER_NAME', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'ACTION_STATEMENT']);
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row->TRIGGER_NAME] = [
                (string) $row->ACTION_TIMING,
                (string) $row->EVENT_MANIPULATION,
                (string) $row->ACTION_STATEMENT,
            ];
        }
        foreach ($expected as $name => [$timing, $event, $body]) {
            if (! isset($actual[$name])
                || $actual[$name][0] !== $timing
                || $actual[$name][1] !== $event
                || $this->normalizeSql($actual[$name][2]) !== $this->normalizeSql($body)) {
                return false;
            }
        }

        return count($actual) === count($expected);
    }

    private function normalizeSql(string $sql): string
    {
        $sql = str_replace('`', '', $sql);
        $normalized = '';
        $inSingleQuote = false;
        $pendingSpace = false;
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];
            if ($character === "'") {
                if ($pendingSpace && $normalized !== '' && ! str_ends_with($normalized, ' ')) {
                    $normalized .= ' ';
                }
                $pendingSpace = false;
                $normalized .= $character;
                if ($inSingleQuote && $index + 1 < $length && $sql[$index + 1] === "'") {
                    $normalized .= "'";
                    $index++;

                    continue;
                }
                $inSingleQuote = ! $inSingleQuote;

                continue;
            }
            if ($inSingleQuote) {
                $normalized .= $character;

                continue;
            }
            if (ctype_space($character)) {
                $pendingSpace = true;

                continue;
            }
            if ($pendingSpace && $normalized !== '' && ! str_ends_with($normalized, ' ')) {
                $normalized .= ' ';
            }
            $pendingSpace = false;
            $normalized .= strtolower($character);
        }
        if ($inSingleQuote) {
            throw new RuntimeException('Benefit discount Quote authority SQL normalization encountered an unterminated literal.');
        }

        return trim($normalized);
    }

    /** @return literal-string */
    private function insertGuardBody(): string
    {
        return <<<'SQL'
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
          AND JSON_LENGTH(NEW.configuration_snapshot) = 10
          AND CAST(JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.discount_irr')) AS SIGNED) = NEW.discount_irr
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.discounted_quote_configuration_hash')) = NEW.discounted_quote_configuration_hash
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.discounted_quote_public_id')) = discounted_quote.public_id
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.grant_configuration_hash')) = NEW.grant_configuration_hash
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.grant_public_id')) = grant_record.public_id
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.resolution_configuration_hash')) = NEW.pricing_rule_resolution_configuration_hash
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.resolution_public_id')) = resolution.public_id
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.rule_code')) = NEW.rule_code_snapshot
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote_configuration_hash')) = NEW.source_quote_configuration_hash
          AND JSON_UNQUOTE(JSON_EXTRACT(NEW.configuration_snapshot, '$.source_quote_public_id')) = source_quote.public_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption identity mismatch.';
    END IF;

    IF LOWER(SHA2(CAST(NEW.configuration_snapshot AS CHAR), 256)) <> NEW.configuration_hash THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption hash mismatch.';
    END IF;
END
SQL;
    }

    /** @return literal-string */
    private function updateGuardBody(): string
    {
        return "BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption is immutable.'; END";
    }

    /** @return literal-string */
    private function deleteGuardBody(): string
    {
        return "BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Benefit discount Quote consumption cannot be deleted.'; END";
    }
};
