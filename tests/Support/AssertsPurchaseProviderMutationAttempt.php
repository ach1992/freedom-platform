<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
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
        $this->assertObservedExternalProviderMutationAttempt(
            $providerCode,
            static fn (Builder $query): Builder => $query->where('mutation_key', 'like', $mutationKeyPrefix.'%'),
            static fn (string $actualMutationKey): bool => str_starts_with($actualMutationKey, $mutationKeyPrefix),
        );
    }

    /**
     * @param  non-empty-string  $mutationKey
     */
    protected function assertExactExternalProviderMutationAttempt(
        string $providerCode,
        string $mutationKey,
    ): void {
        $this->assertObservedExternalProviderMutationAttempt(
            $providerCode,
            static fn (Builder $query): Builder => $query->where('mutation_key', $mutationKey),
            static fn (string $actualMutationKey): bool => hash_equals($mutationKey, $actualMutationKey),
        );
    }

    /**
     * @param  \Closure(Builder):Builder  $scope
     * @param  \Closure(string):bool  $matchesMutationKey
     */
    private function assertObservedExternalProviderMutationAttempt(
        string $providerCode,
        \Closure $scope,
        \Closure $matchesMutationKey,
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

            $query = $probe->table('purchase_provider_mutation_attempts')
                ->where('provider_code', $providerCode)
                ->whereIn('state', ['prepared', 'external_started', 'reconciliation_required']);
            $attempts = $scope($query)->get(['state', 'mutation_key']);

            self::assertCount(1, $attempts);
            $attempt = $attempts->first();
            self::assertNotNull($attempt);
            self::assertSame('external_started', $attempt->state);
            self::assertTrue($matchesMutationKey((string) $attempt->mutation_key));
        } finally {
            DB::purge($connectionName);
        }
    }
}
