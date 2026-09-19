<?php

declare(strict_types=1);

use App\Modules\Telegram\Application\TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'telegram_administrator_direct_messages';

    private const KEYBOARD_CIPHERTEXT = 'inline_keyboard_ciphertext';

    private const KEYBOARD_HASH = 'inline_keyboard_hash';

    private const CONSTRAINT_TYPE = 'tg_admin_direct_message_type_chk';

    private const CONSTRAINT_LENGTH = 'tg_admin_direct_message_length_chk';

    private const CONSTRAINT_MEDIA = 'tg_admin_direct_message_media_shape_chk';

    private const CONSTRAINT_KEYBOARD = 'tg_admin_direct_message_keyboard_chk';

    /** @requirement COM-001 DAT-002 DAT-003 SEC-002 SEC-003 QUA-004 */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertRecognizedKeyboardColumns($this->keyboardColumnMetadata());
            $this->assertRecognizedCheckConstraintState($this->completionCheckConstraints());
            $this->assertRecognizedInteractivePresentationAuthority();
        }

        $this->ensureKeyboardColumns();

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->reconcileCheckConstraints($this->currentCheckConstraints());
        $this->upgradeInteractivePresentationTrigger();
    }

    public function down(): void
    {
        if (DB::table(self::TABLE)
            ->whereIn('content_type', ['forward', 'copy'])
            ->exists()
            || $this->keyboardHistoryExists()) {
            throw new RuntimeException(
                'Administrator direct-message source/button history must be retained; rollback requires no source/button records.',
            );
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertRecognizedKeyboardColumns($this->keyboardColumnMetadata());
            $this->assertRecognizedCheckConstraintState($this->completionCheckConstraints());
            $this->assertRecognizedInteractivePresentationAuthority();
            $this->restoreInteractivePresentationTrigger();
            $this->reconcileCheckConstraints($this->legacyCheckConstraints());
        }

        $this->dropKeyboardColumns();
    }

    private function ensureKeyboardColumns(): void
    {
        $columns = $this->keyboardColumnMetadata();
        $this->assertRecognizedKeyboardColumns($columns);

        $alter = [];
        if (! isset($columns[self::KEYBOARD_CIPHERTEXT])) {
            $alter[] = 'ADD COLUMN '.self::KEYBOARD_CIPHERTEXT.' LONGTEXT NULL AFTER media_content_sha256';
        }
        if (! isset($columns[self::KEYBOARD_HASH])) {
            $alter[] = 'ADD COLUMN '.self::KEYBOARD_HASH.' CHAR(64) NULL AFTER '.self::KEYBOARD_CIPHERTEXT;
        }
        if ($alter === []) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE '.self::TABLE."\n    ".implode(",\n    ", $alter));
        } else {
            Schema::table(self::TABLE, function ($table) use ($columns): void {
                if (! isset($columns[self::KEYBOARD_CIPHERTEXT])) {
                    $table->longText(self::KEYBOARD_CIPHERTEXT)->nullable();
                }
                if (! isset($columns[self::KEYBOARD_HASH])) {
                    $table->char(self::KEYBOARD_HASH, 64)->nullable();
                }
            });
        }

        $this->assertRecognizedKeyboardColumns($this->keyboardColumnMetadata(), requireBoth: true);
    }

    private function dropKeyboardColumns(): void
    {
        $columns = $this->keyboardColumnMetadata();
        $this->assertRecognizedKeyboardColumns($columns);
        $present = array_values(array_filter(
            [self::KEYBOARD_HASH, self::KEYBOARD_CIPHERTEXT],
            static fn (string $column): bool => isset($columns[$column]),
        ));
        if ($present === []) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE '.self::TABLE."\n    ".implode(",\n    ", array_map(
                static fn (string $column): string => 'DROP COLUMN '.$column,
                $present,
            )));
        } else {
            Schema::table(self::TABLE, function ($table) use ($present): void {
                $table->dropColumn($present);
            });
        }

        $remaining = $this->keyboardColumnMetadata();
        if ($remaining !== []) {
            throw new RuntimeException(
                'Telegram direct-message rollback did not remove keyboard columns exactly.',
            );
        }
    }

    /** @return array<string,array{data_type:string,nullable:string,length:int|null}> */
    private function keyboardColumnMetadata(): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $columns = [];
            if (Schema::hasColumn(self::TABLE, self::KEYBOARD_CIPHERTEXT)) {
                $columns[self::KEYBOARD_CIPHERTEXT] = [
                    'data_type' => 'longtext',
                    'nullable' => 'YES',
                    'length' => null,
                ];
            }
            if (Schema::hasColumn(self::TABLE, self::KEYBOARD_HASH)) {
                $columns[self::KEYBOARD_HASH] = [
                    'data_type' => 'char',
                    'nullable' => 'YES',
                    'length' => 64,
                ];
            }

            return $columns;
        }

        $rows = DB::select(<<<'SQL'
SELECT COLUMN_NAME AS column_name,
       DATA_TYPE AS data_type,
       IS_NULLABLE AS is_nullable,
       CHARACTER_MAXIMUM_LENGTH AS character_maximum_length
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = ?
  AND COLUMN_NAME IN (?, ?)
SQL, [self::TABLE, self::KEYBOARD_CIPHERTEXT, self::KEYBOARD_HASH]);

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) $row->column_name;
            $length = $row->character_maximum_length;
            $columns[$name] = [
                'data_type' => strtolower((string) $row->data_type),
                'nullable' => strtoupper((string) $row->is_nullable),
                'length' => $length === null ? null : (int) $length,
            ];
        }

        return $columns;
    }

    /** @param array<string,array{data_type:string,nullable:string,length:int|null}> $columns */
    private function assertRecognizedKeyboardColumns(array $columns, bool $requireBoth = false): void
    {
        foreach ($columns as $name => $metadata) {
            $expected = match ($name) {
                self::KEYBOARD_CIPHERTEXT => [
                    'data_type' => 'longtext',
                    'nullable' => 'YES',
                    'length' => DB::connection()->getDriverName() === 'mysql' ? 4_294_967_295 : null,
                ],
                self::KEYBOARD_HASH => ['data_type' => 'char', 'nullable' => 'YES', 'length' => 64],
                default => null,
            };
            if ($expected === null || $metadata !== $expected) {
                throw new RuntimeException(
                    'Telegram direct-message completion found an unrecognized keyboard column state.',
                );
            }
        }

        if ($requireBoth
            && (! isset($columns[self::KEYBOARD_CIPHERTEXT]) || ! isset($columns[self::KEYBOARD_HASH]))) {
            throw new RuntimeException(
                'Telegram direct-message completion did not install keyboard columns exactly.',
            );
        }
    }

    private function keyboardHistoryExists(): bool
    {
        foreach ([self::KEYBOARD_CIPHERTEXT, self::KEYBOARD_HASH] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)
                && DB::table(self::TABLE)->whereNotNull($column)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,string> $desired */
    private function reconcileCheckConstraints(array $desired): void
    {
        $existing = $this->completionCheckConstraints();
        $this->assertRecognizedCheckConstraintState($existing);

        if ($this->constraintsMatchDesiredState($existing, $desired)) {
            return;
        }

        foreach ($desired as $name => $clause) {
            $violations = DB::selectOne(
                'SELECT COUNT(*) AS aggregate FROM '.self::TABLE.' WHERE NOT ('.$clause.')',
            );
            if ($violations === null || (int) $violations->aggregate !== 0) {
                throw new RuntimeException(
                    'Telegram direct-message completion cannot install constraint '.$name.' because existing data violates it.',
                );
            }
        }

        $relevantNames = array_keys($this->knownCheckClauses());
        $parts = [];
        foreach ($relevantNames as $name) {
            if (isset($existing[$name])) {
                $parts[] = 'DROP CONSTRAINT '.$name;
            }
        }
        foreach ($desired as $name => $clause) {
            $parts[] = 'ADD CONSTRAINT '.$name.' CHECK ('.$clause.')';
        }
        if ($parts === []) {
            return;
        }

        DB::statement('ALTER TABLE '.self::TABLE."\n    ".implode(",\n    ", $parts));

        $after = $this->completionCheckConstraints();
        if (! $this->constraintsMatchDesiredState($after, $desired)) {
            throw new RuntimeException(
                'Telegram direct-message completion did not converge database constraints exactly.',
            );
        }
    }

    /** @param array<string,string> $existing */
    private function assertRecognizedCheckConstraintState(array $existing): void
    {
        $known = $this->knownCheckClauses();
        foreach ($existing as $name => $clause) {
            if (! isset($known[$name])) {
                continue;
            }
            $normalized = $this->normalizeCheckClause($clause);
            $recognized = false;
            foreach ($known[$name] as $knownClause) {
                if (hash_equals($this->normalizeCheckClause($knownClause), $normalized)) {
                    $recognized = true;
                    break;
                }
            }
            if (! $recognized) {
                throw new RuntimeException(
                    'Telegram direct-message completion found an unrecognized database constraint state: '.$name.'.',
                );
            }
        }
    }

    /** @return array<string,string> */
    private function completionCheckConstraints(): array
    {
        $rows = DB::select(<<<'SQL'
SELECT tc.CONSTRAINT_NAME AS constraint_name,
       cc.CHECK_CLAUSE AS check_clause
FROM information_schema.TABLE_CONSTRAINTS tc
JOIN information_schema.CHECK_CONSTRAINTS cc
  ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
 AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.TABLE_SCHEMA = DATABASE()
  AND tc.TABLE_NAME = ?
  AND tc.CONSTRAINT_TYPE = 'CHECK'
SQL, [self::TABLE]);

        $constraints = [];
        foreach ($rows as $row) {
            $constraints[(string) $row->constraint_name] = (string) $row->check_clause;
        }

        return $constraints;
    }

    /**
     * @param  array<string,string>  $existing
     * @param  array<string,string>  $desired
     */
    private function constraintsMatchDesiredState(array $existing, array $desired): bool
    {
        foreach ($this->knownCheckClauses() as $name => $_known) {
            if (! isset($desired[$name])) {
                if (isset($existing[$name])) {
                    return false;
                }

                continue;
            }
            if (! isset($existing[$name])
                || ! hash_equals(
                    $this->normalizeCheckClause($desired[$name]),
                    $this->normalizeCheckClause($existing[$name]),
                )) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,list<string>> */
    private function knownCheckClauses(): array
    {
        $current = $this->currentCheckConstraints();
        $legacy = $this->legacyCheckConstraints();
        $known = [];
        foreach (array_unique(array_merge(array_keys($current), array_keys($legacy))) as $name) {
            $known[$name] = array_values(array_unique(array_filter([
                $current[$name] ?? null,
                $legacy[$name] ?? null,
            ], static fn (?string $clause): bool => $clause !== null)));
        }

        return $known;
    }

    /** @return array<string,string> */
    private function currentCheckConstraints(): array
    {
        return [
            self::CONSTRAINT_TYPE => "content_type IN ('text','photo','video','document','forward','copy')",
            self::CONSTRAINT_LENGTH => <<<'SQL'
content_type = 'text' AND content_length BETWEEN 1 AND 3500
OR content_type = 'photo' AND content_length BETWEEN 0 AND 1024
OR content_type IN ('video','document','forward','copy') AND content_length = 0
SQL,
            self::CONSTRAINT_MEDIA => <<<'SQL'
content_type IN ('text','forward','copy')
    AND media_public_id IS NULL
    AND media_detected_mime IS NULL
    AND media_byte_size IS NULL
    AND media_content_sha256 IS NULL
OR content_type IN ('photo','video','document')
    AND media_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
    AND BINARY media_public_id = BINARY UPPER(media_public_id)
    AND media_byte_size BETWEEN 1 AND 20000000
    AND media_content_sha256 REGEXP '^[0-9a-f]{64}$'
    AND (
        content_type = 'photo'
            AND media_byte_size <= 10000000
            AND media_detected_mime IN ('image/jpeg','image/png','image/webp')
        OR content_type = 'video' AND media_detected_mime = 'video/mp4'
        OR content_type = 'document' AND media_detected_mime IN (
            'image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain'
        )
    )
SQL,
            self::CONSTRAINT_KEYBOARD => <<<'SQL'
inline_keyboard_ciphertext IS NULL AND inline_keyboard_hash IS NULL
OR content_type <> 'forward'
    AND inline_keyboard_ciphertext IS NOT NULL
    AND OCTET_LENGTH(inline_keyboard_ciphertext) BETWEEN 1 AND 65536
    AND inline_keyboard_hash REGEXP '^[0-9a-f]{64}$'
SQL,
        ];
    }

    /** @return array<string,string> */
    private function legacyCheckConstraints(): array
    {
        return [
            self::CONSTRAINT_TYPE => "content_type IN ('text','photo','video','document')",
            self::CONSTRAINT_LENGTH => <<<'SQL'
content_type = 'text' AND content_length BETWEEN 1 AND 3500
OR content_type = 'photo' AND content_length BETWEEN 0 AND 1024
OR content_type IN ('video','document') AND content_length = 0
SQL,
            self::CONSTRAINT_MEDIA => <<<'SQL'
content_type = 'text'
    AND media_public_id IS NULL
    AND media_detected_mime IS NULL
    AND media_byte_size IS NULL
    AND media_content_sha256 IS NULL
OR content_type IN ('photo','video','document')
    AND media_public_id REGEXP '^[0-9A-HJKMNP-TV-Z]{26}$'
    AND BINARY media_public_id = BINARY UPPER(media_public_id)
    AND media_byte_size BETWEEN 1 AND 20000000
    AND media_content_sha256 REGEXP '^[0-9a-f]{64}$'
    AND (
        content_type = 'photo'
            AND media_byte_size <= 10000000
            AND media_detected_mime IN ('image/jpeg','image/png','image/webp')
        OR content_type = 'video' AND media_detected_mime = 'video/mp4'
        OR content_type = 'document' AND media_detected_mime IN (
            'image/jpeg','image/png','image/webp','video/mp4','application/pdf','text/plain'
        )
    )
SQL,
        ];
    }

    private function normalizeCheckClause(string $clause): string
    {
        $normalized = strtolower(str_replace('`', '', trim($clause)));
        $normalized = preg_replace('/\s+/', '', $normalized);
        if (! is_string($normalized) || $normalized === '') {
            throw new RuntimeException('Telegram direct-message database constraint clause is invalid.');
        }

        $normalized = str_replace('ucase(', 'upper(', $normalized);
        $normalized = str_replace(
            'cast(media_public_idascharcharsetbinary)',
            'binarymedia_public_id',
            $normalized,
        );
        $normalized = str_replace(
            'cast(upper(media_public_id)ascharcharsetbinary)',
            'binaryupper(media_public_id)',
            $normalized,
        );
        if ($normalized === '') {
            throw new RuntimeException('Telegram direct-message database constraint clause is invalid.');
        }

        while ($normalized[0] === '(' && str_ends_with($normalized, ')')
            && $this->outerParenthesesWrapEntireExpression($normalized)) {
            $normalized = substr($normalized, 1, -1);
        }

        return $normalized;
    }

    private function outerParenthesesWrapEntireExpression(string $expression): bool
    {
        $depth = 0;
        $length = strlen($expression);
        $quoted = false;
        for ($index = 0; $index < $length; $index++) {
            $character = $expression[$index];
            if ($character === "'") {
                if ($quoted && $index + 1 < $length && $expression[$index + 1] === "'") {
                    $index++;

                    continue;
                }
                $quoted = ! $quoted;

                continue;
            }
            if ($quoted) {
                continue;
            }
            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
                if ($depth === 0 && $index !== $length - 1) {
                    return false;
                }
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0 && ! $quoted;
    }

    private function assertRecognizedInteractivePresentationAuthority(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection)
            || $surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            return;
        }

        throw new RuntimeException(
            'Telegram direct-message completion found an unrecognized interactive presentation authority.',
        );
    }

    private function upgradeInteractivePresentationTrigger(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection)) {
            return;
        }
        if (! $surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            throw new RuntimeException(
                'Telegram direct-message completion cannot upgrade an unrecognized interactive presentation authority.',
            );
        }

        $connection->unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::insertTriggerBody(),
        );
        if (! $surface->isReady($connection)) {
            throw new RuntimeException(
                'Telegram direct-message completion did not upgrade interactive presentation authority exactly.',
            );
        }
    }

    private function restoreInteractivePresentationTrigger(): void
    {
        $connection = DB::connection();
        $surface = new TelegramDeliveryInteractivePresentationDatabaseSurfaceV1;
        if ($surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            return;
        }
        if (! $surface->isReady($connection)) {
            throw new RuntimeException(
                'Telegram direct-message rollback cannot restore an unrecognized interactive presentation authority.',
            );
        }

        $connection->unprepared(
            'CREATE OR REPLACE TRIGGER '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::INSERT_TRIGGER
            .' BEFORE INSERT ON '.TelegramDeliveryInteractivePresentationDatabaseSurfaceV1::TABLE
            .' FOR EACH ROW '.$surface::legacyV3InsertTriggerBody(),
        );
        if (! $surface->isReady($connection, $surface::legacyV3InsertTriggerBody())) {
            throw new RuntimeException(
                'Telegram direct-message rollback did not restore the exact v2/v3 interactive presentation authority.',
            );
        }
    }
};
