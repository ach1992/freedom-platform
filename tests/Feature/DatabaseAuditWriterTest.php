<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** @requirement DAT-004 OPS-001 SEC-003 QUA-004 */
final class DatabaseAuditWriterTest extends TestCase
{
    use DatabaseTruncation;

    public function test_audit_write_succeeds_while_direct_update_and_delete_are_rejected(): void
    {
        $auditLogId = $this->writeAuditLog();

        self::assertSame(1, DB::table('audit_logs')->where('id', $auditLogId)->count());

        $this->assertQueryRejected(static fn (): int => DB::table('audit_logs')
            ->where('id', $auditLogId)
            ->update(['reason_code' => 'tampered']));

        self::assertNull(DB::table('audit_logs')->where('id', $auditLogId)->value('reason_code'));

        $this->assertQueryRejected(static fn (): int => DB::table('audit_logs')
            ->where('id', $auditLogId)
            ->delete());

        self::assertSame(1, DB::table('audit_logs')->where('id', $auditLogId)->count());
    }

    private function writeAuditLog(): int
    {
        return (int) DB::table('audit_logs')->insertGetId([
            'actor_type' => 'system',
            'actor_id' => null,
            'action' => 'test.audit.write',
            'target_type' => 'test',
            'target_id' => 'audit-log-immutability',
            'before_safe_data' => null,
            'after_safe_data' => null,
            'reason_code' => null,
            'reason' => null,
            'correlation_id' => 'audit-log-immutability-test',
            'request_fingerprint' => null,
            'created_at' => now('UTC'),
        ]);
    }

    private function assertQueryRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected audit-log database guard rejection.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }
}
