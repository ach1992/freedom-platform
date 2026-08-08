<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @requirement PAY-002 PAY-003 WAL-001 DAT-003 DAT-004 SEC-002 QUA-001 */
    public function up(): void
    {
        DB::statement(<<<'SQL'
ALTER TABLE payment_provider_events
ADD CONSTRAINT payment_provider_event_safe_evidence_bounds_chk
CHECK (
    JSON_VALID(`safe_evidence`)
    AND JSON_TYPE(`safe_evidence`) = 'OBJECT'
    AND JSON_LENGTH(`safe_evidence`) <= 32
    AND OCTET_LENGTH(`safe_evidence`) <= 8192
)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_provider_events DROP CONSTRAINT payment_provider_event_safe_evidence_bounds_chk');
    }
};
