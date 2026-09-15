<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement BUY-002 PRO-001 PRO-002 DAT-003 DAT-004 SEC-001 SEC-002 QUA-001 QUA-004 */
final class BenefitDiscountQuoteMigrationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_reentry_repairs_exact_column_shape_and_same_name_wrong_check_clause(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions MODIFY correlation_id VARCHAR(65) NOT NULL');
        $migration->up();

        $columnType = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('COLUMN_NAME', 'correlation_id')
            ->value('COLUMN_TYPE');
        self::assertSame('varchar(64)', strtolower((string) $columnType));

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP CONSTRAINT benefit_discount_quote_consumption_amount_chk');
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT benefit_discount_quote_consumption_amount_chk CHECK (`discount_irr` >= 0)');
        $weakenedClause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'benefit_discount_quote_consumption_amount_chk')
            ->value('CHECK_CLAUSE');
        self::assertSame('discount_irr >= 0', $this->normalizeSql((string) $weakenedClause));

        $migration->up();

        $repairedClause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'benefit_discount_quote_consumption_amount_chk')
            ->value('CHECK_CLAUSE');
        self::assertSame('discount_irr >= 1', $this->normalizeSql((string) $repairedClause));
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_reentry_repairs_same_name_fk_referential_actions_and_unexpected_check(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP FOREIGN KEY bdqc_user_fk');
        DB::statement(
            'ALTER TABLE benefit_code_discount_quote_consumptions '.
            'ADD CONSTRAINT bdqc_user_fk FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) '.
            'ON DELETE CASCADE ON UPDATE CASCADE',
        );
        $weakenedRules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'bdqc_user_fk')
            ->first(['DELETE_RULE', 'UPDATE_RULE']);
        self::assertNotNull($weakenedRules);
        self::assertSame('CASCADE', (string) $weakenedRules->DELETE_RULE);
        self::assertSame('CASCADE', (string) $weakenedRules->UPDATE_RULE);

        $migration->up();

        $repairedRules = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', 'bdqc_user_fk')
            ->first(['DELETE_RULE', 'UPDATE_RULE']);
        self::assertNotNull($repairedRules);
        self::assertSame('RESTRICT', (string) $repairedRules->DELETE_RULE);
        self::assertSame('RESTRICT', (string) $repairedRules->UPDATE_RULE);

        DB::statement(
            'ALTER TABLE benefit_code_discount_quote_consumptions '.
            'ADD CONSTRAINT bdqc_unexpected_check CHECK (`discount_irr` >= 1 AND CHAR_LENGTH(`rule_code_snapshot`) > 0)',
        );
        self::assertContains('bdqc_unexpected_check', $this->checkConstraintNames($database));

        $migration->up();

        self::assertSame([
            'benefit_discount_quote_consumption_amount_chk',
            'benefit_discount_quote_consumption_hashes_chk',
            'benefit_discount_quote_consumption_snapshot_chk',
            'configuration_snapshot',
        ], $this->checkConstraintNames($database));
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_reentry_repairs_restrictive_unique_and_prefix_unique_index_drift(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();

        DB::statement(
            'CREATE UNIQUE INDEX bdqc_unexpected_user_uq '.
            'ON benefit_code_discount_quote_consumptions (`user_id`)',
        );
        self::assertSame(1, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', 'bdqc_unexpected_user_uq')
            ->where('NON_UNIQUE', 0)
            ->count());

        $migration->up();

        self::assertFalse(DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', 'bdqc_unexpected_user_uq')
            ->exists());

        $indexName = 'benefit_code_discount_quote_consumptions_consumption_key_unique';
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP INDEX '.$indexName);
        DB::statement(
            'CREATE UNIQUE INDEX '.$indexName.' '.
            'ON benefit_code_discount_quote_consumptions (`consumption_key`(64))',
        );
        $prefixLength = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', $indexName)
            ->value('SUB_PART');
        self::assertSame(64, (int) $prefixLength);

        $migration->up();

        $repairedPrefixLength = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', $indexName)
            ->value('SUB_PART');
        self::assertNull($repairedPrefixLength);

        $operationalIndex = 'bdqc_extra_operational_idx';
        DB::statement(
            'CREATE INDEX '.$operationalIndex.' '.
            'ON benefit_code_discount_quote_consumptions (`rule_code_snapshot`, `created_at`)',
        );
        $migration->up();
        self::assertSame(2, DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('INDEX_NAME', $operationalIndex)
            ->where('NON_UNIQUE', 1)
            ->count());
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_reentry_repairs_semantically_weakened_same_name_trigger_that_retains_old_markers(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();
        $triggerName = 'benefit_discount_quote_consumptions_insert_guard';

        $body = $this->triggerBody($database, $triggerName);
        self::assertStringContainsString('source_quote.user_id = NEW.user_id', $body);

        $weakened = str_replace(
            'source_quote.user_id = NEW.user_id',
            'source_quote.user_id = source_quote.user_id',
            $body,
        );
        self::assertNotSame($body, $weakened);
        self::assertStringContainsString(
            'grant_record.pricing_rule_version_id = resolution.pricing_rule_version_id',
            $weakened,
        );
        self::assertStringContainsString('JSON_LENGTH(NEW.configuration_snapshot) = 10', $weakened);
        self::assertStringContainsString("JSON_EXTRACT(NEW.configuration_snapshot, '$.grant_public_id')", $weakened);
        self::assertStringContainsString('Benefit discount Quote consumption identity mismatch.', $weakened);

        $this->replaceInsertTrigger($triggerName, $weakened);
        $migration->up();

        $repaired = $this->triggerBody($database, $triggerName);
        self::assertStringContainsString('source_quote.user_id = NEW.user_id', $repaired);
        self::assertStringNotContainsString('source_quote.user_id = source_quote.user_id', $repaired);

        $caseWeakened = str_replace('$.grant_public_id', '$.GRANT_PUBLIC_ID', $repaired);
        self::assertNotSame($repaired, $caseWeakened);
        $this->replaceInsertTrigger($triggerName, $caseWeakened);
        $migration->up();

        $literalRepaired = $this->triggerBody($database, $triggerName);
        self::assertStringContainsString("JSON_EXTRACT(NEW.configuration_snapshot, '$.grant_public_id')", $literalRepaired);
        self::assertStringNotContainsString('$.GRANT_PUBLIC_ID', $literalRepaired);

        DB::unprepared(
            'CREATE TRIGGER benefit_discount_quote_consumptions_unexpected_guard '.
            'AFTER INSERT ON benefit_code_discount_quote_consumptions FOR EACH ROW BEGIN SET @bdqc_unexpected = 1; END',
        );
        $migration->up();
        $triggerNames = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('EVENT_OBJECT_TABLE', 'benefit_code_discount_quote_consumptions')
            ->orderBy('TRIGGER_NAME')
            ->pluck('TRIGGER_NAME')
            ->all();
        self::assertSame([
            'benefit_discount_quote_consumptions_delete_guard',
            'benefit_discount_quote_consumptions_insert_guard',
            'benefit_discount_quote_consumptions_update_guard',
        ], $triggerNames);
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_reentry_preserves_backticks_inside_check_and_trigger_string_literals(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();
        $constraintName = 'benefit_discount_quote_consumption_hashes_chk';
        $canonicalClause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->value('CHECK_CLAUSE');
        self::assertIsString($canonicalClause);
        $driftedClause = str_replace("'^[0-9a-f]{64}$'", "'^[0-9a-f`]{64}$'", $canonicalClause, $replacementCount);
        self::assertGreaterThan(0, $replacementCount);
        self::assertNotSame($canonicalClause, $driftedClause);

        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions DROP CONSTRAINT '.$constraintName);
        DB::statement('ALTER TABLE benefit_code_discount_quote_consumptions ADD CONSTRAINT '.$constraintName.' CHECK ('.$driftedClause.')');
        $installedDrift = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->value('CHECK_CLAUSE');
        self::assertIsString($installedDrift);
        self::assertStringContainsString("'^[0-9a-f`]{64}$'", $installedDrift);

        $migration->up();

        $repairedClause = DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->where('CONSTRAINT_NAME', $constraintName)
            ->value('CHECK_CLAUSE');
        self::assertIsString($repairedClause);
        self::assertStringContainsString("'^[0-9a-f]{64}$'", $repairedClause);
        self::assertStringNotContainsString("'^[0-9a-f`]{64}$'", $repairedClause);

        $triggerName = 'benefit_discount_quote_consumptions_insert_guard';
        $canonicalTrigger = $this->triggerBody($database, $triggerName);
        $driftedTrigger = str_replace('$.grant_public_id', '$.grant_public_id`', $canonicalTrigger, $triggerReplacementCount);
        self::assertGreaterThan(0, $triggerReplacementCount);
        self::assertNotSame($canonicalTrigger, $driftedTrigger);
        $this->replaceInsertTrigger($triggerName, $driftedTrigger);
        self::assertStringContainsString('$.grant_public_id`', $this->triggerBody($database, $triggerName));

        $migration->up();

        $repairedTrigger = $this->triggerBody($database, $triggerName);
        self::assertStringContainsString('$.grant_public_id', $repairedTrigger);
        self::assertStringNotContainsString('$.grant_public_id`', $repairedTrigger);
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    public function test_reentry_uses_the_installed_trigger_sql_mode_when_parsing_literals(): void
    {
        $migration = $this->migration();
        $migration->up();
        $database = DB::connection()->getDatabaseName();
        $triggerName = 'benefit_discount_quote_consumptions_insert_guard';
        $canonicalTrigger = $this->triggerBody($database, $triggerName);
        $driftedTrigger = str_replace(
            "Benefit discount Quote consumption identity mismatch.';",
            "Benefit discount Quote consumption identity mismatch.\\';",
            $canonicalTrigger,
            $replacementCount,
        );
        self::assertGreaterThan(0, $replacementCount);
        self::assertNotSame($canonicalTrigger, $driftedTrigger);

        $row = DB::selectOne('SELECT @@SESSION.sql_mode AS sql_mode');
        $originalSqlMode = is_object($row) && property_exists($row, 'sql_mode') ? (string) $row->sql_mode : '';
        $modes = array_values(array_filter(array_map('trim', explode(',', $originalSqlMode))));
        if (! in_array('NO_BACKSLASH_ESCAPES', array_map('strtoupper', $modes), true)) {
            $modes[] = 'NO_BACKSLASH_ESCAPES';
        }

        try {
            DB::statement('SET SESSION sql_mode = ?', [implode(',', $modes)]);
            $this->replaceInsertTrigger($triggerName, $driftedTrigger);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalSqlMode]);
        }

        $installed = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('TRIGGER_NAME', $triggerName)
            ->first(['ACTION_STATEMENT', 'SQL_MODE']);
        self::assertNotNull($installed);
        self::assertStringContainsString('NO_BACKSLASH_ESCAPES', strtoupper((string) $installed->SQL_MODE));
        self::assertStringContainsString("identity mismatch.\\';", (string) $installed->ACTION_STATEMENT);

        $migration->up();

        $repaired = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('TRIGGER_NAME', $triggerName)
            ->first(['ACTION_STATEMENT', 'SQL_MODE']);
        self::assertNotNull($repaired);
        self::assertStringContainsString('Benefit discount Quote consumption identity mismatch.', (string) $repaired->ACTION_STATEMENT);
        self::assertStringNotContainsString("identity mismatch.\\';", (string) $repaired->ACTION_STATEMENT);
        self::assertSame(0, DB::table('benefit_code_discount_quote_consumptions')->count());
    }

    private function migration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_03_000100_enable_benefit_discount_quote_consumption.php');

        return $migration;
    }

    /** @return list<string> */
    private function checkConstraintNames(string $database): array
    {
        return DB::table('information_schema.CHECK_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', $database)
            ->where('TABLE_NAME', 'benefit_code_discount_quote_consumptions')
            ->orderBy('CONSTRAINT_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();
    }

    private function triggerBody(string $database, string $triggerName): string
    {
        $body = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', $database)
            ->where('TRIGGER_NAME', $triggerName)
            ->value('ACTION_STATEMENT');
        self::assertIsString($body);

        return $body;
    }

    private function replaceInsertTrigger(string $triggerName, string $body): void
    {
        DB::unprepared('DROP TRIGGER '.$triggerName);
        DB::unprepared(
            'CREATE TRIGGER '.$triggerName.
            ' BEFORE INSERT ON benefit_code_discount_quote_consumptions FOR EACH ROW '.$body,
        );
    }

    private function normalizeSql(string $sql): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower(str_replace('`', '', $sql)));
        self::assertIsString($normalized);

        return trim($normalized);
    }
}
