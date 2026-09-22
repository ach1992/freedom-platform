<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

trait AssertsPurchaseProviderMutationAttempt
{
    /**
     * @param  non-empty-string  $mutationKeyPrefix
     */
    protected function assertExternalProviderMutationAttempt(
        string $providerCode,
        string $mutationKeyPrefix,
    ): void {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $configuration = DB::connection()->getConfig();
        $connectionName = 'purchase_provider_mutation_test_probe';
        config()->set('database.connections.'.$connectionName, $configuration);
        DB::purge($connectionName);

        try {
            $probe = DB::connection($connectionName);
            if (! $probe instanceof Connection) {
                throw new RuntimeException('Provider mutation test probe connection is unavailable.');
            }

            $attempts = $probe->table('purchase_provider_mutation_attempts')
                ->where('provider_code', $providerCode)
                ->where('mutation_key', 'like', $mutationKeyPrefix.'%')
                ->whereIn('state', ['prepared', 'external_started', 'reconciliation_required'])
                ->get(['state', 'mutation_key']);

            self::assertCount(1, $attempts);
            $attempt = $attempts->first();
            self::assertNotNull($attempt);
            self::assertSame('external_started', $attempt->state);
            self::assertStringStartsWith($mutationKeyPrefix, (string) $attempt->mutation_key);
        } finally {
            DB::purge($connectionName);
        }
    }
}
