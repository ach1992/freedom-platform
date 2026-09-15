<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Provisioning\Application\ServiceBatchGrantService;
use App\Modules\Provisioning\Application\ServiceOperationalContext;
use App\Modules\Provisioning\Application\ServiceOperationalDatabaseCapability;
use App\Shared\Application\Clock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\CreatesBenefitCodeFixtures;
use Tests\TestCase;

/** @requirement SVC-011 SVC-012 ARCH-003 ARCH-004 DAT-003 SEC-002 SEC-008 QUA-004 */
final class ServiceOperationalSessionSafetyTest extends TestCase
{
    use CreatesBenefitCodeFixtures;
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_08_19_000140_enable_service_operational_authority.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        try {
            $this->truncateTablesForAllConnections();
        } finally {
            parent::tearDown();
        }
    }

    public function test_batch_claim_lease_and_pause_share_database_clock_even_when_application_clock_is_skewed(): void
    {
        $offering = $this->activeBenefitOffering('batch-database-clock');
        $ownerId = $this->benefitOwner();
        $userId = $this->benefitUser();
        $context = $this->context('batch-database-clock', $ownerId);
        $created = $this->app->make(ServiceBatchGrantService::class)->create($context, [[
            'user_id' => $userId,
            'plan_offering_id' => $offering['id'],
        ]]);

        $this->app->instance(Clock::class, new class implements Clock
        {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2099-01-01T00:00:00+00:00');
            }
        });
        $service = $this->app->make(ServiceBatchGrantService::class);
        $connection = DB::connection();
        $postClaimInterrupted = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$postClaimInterrupted): void {
            unset($bindings, $db);
            if ($postClaimInterrupted) {
                return;
            }
            $sql = strtolower($query);
            if (! str_contains($sql, 'service_batch_grants')
                || ! str_contains($sql, 'reason_code')
                || ! str_contains($sql, 'items_committed_at')) {
                return;
            }

            $postClaimInterrupted = true;
            throw new RuntimeException('Injected post-claim interruption.');
        });

        try {
            $service->resume($created->batchPublicId, $context);
            self::fail('Injected post-claim interruption must escape before downstream authority.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected post-claim interruption.', $exception->getMessage());
        }
        self::assertTrue($postClaimInterrupted);

        $item = DB::table('service_batch_grant_items')
            ->where('service_batch_grant_id', $created->batchId)
            ->first(['id', 'state', 'attempt_count', 'claim_expires_at']);
        self::assertNotNull($item);
        self::assertSame('processing', $item->state);
        self::assertSame(1, (int) $item->attempt_count);
        self::assertNotNull($item->claim_expires_at);

        $lease = DB::selectOne(
            'SELECT claim_expires_at > CURRENT_TIMESTAMP(6) AS is_live, claim_expires_at <= DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL 6 MINUTE) AS bounded FROM service_batch_grant_items WHERE id = ?',
            [(int) $item->id],
        );
        self::assertNotNull($lease);
        self::assertSame(1, (int) $lease->is_live);
        self::assertSame(1, (int) $lease->bounded);
        self::assertSame(0, DB::table('order_source_authorizations')->count());

        try {
            $service->pause($created->batchPublicId, $context);
            self::fail('Application pause must use database-time lease liveness despite a skewed application Clock.');
        } catch (DomainException $exception) {
            self::assertSame('Service batch grant cannot pause while an item claim is active.', $exception->getMessage());
        }
        self::assertSame('active', DB::table('service_batch_grants')->where('id', $created->batchId)->value('state'));

        $this->setBatchAuthorityWithCapability();
        try {
            try {
                DB::table('service_batch_grants')->where('id', $created->batchId)->update([
                    'state' => 'paused',
                    'updated_at' => DB::raw('CURRENT_TIMESTAMP(6)'),
                ]);
                self::fail('Direct DB pause must agree that the database-time claim is live.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('Service batch grant update authority is invalid.', $exception->getMessage());
            }
        } finally {
            $this->app->make(ServiceOperationalDatabaseCapability::class)->clear(DB::connection());
        }
    }

    public function test_operational_capability_cleanup_failure_disconnects_the_privileged_database_session(): void
    {
        $connection = DB::connection();
        $capability = $this->app->make(ServiceOperationalDatabaseCapability::class);
        $capability->apply($connection);
        $connection->statement("SET @app_service_batch_authority = 'service_batch_grant_v1'");

        $before = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id');
        self::assertNotNull($before);
        $beforeConnectionId = (int) $before->connection_id;

        $cleanupInterrupted = false;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $db) use (&$cleanupInterrupted): void {
            unset($bindings, $db);
            if ($cleanupInterrupted || ! str_contains(strtolower($query), '@app_service_operational_capability = null')) {
                return;
            }

            $cleanupInterrupted = true;
            throw new RuntimeException('Injected operational capability clear failure.');
        });

        try {
            $capability->clear($connection);
            self::fail('Capability cleanup fault must propagate after invalidating the privileged connection.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected operational capability clear failure.', $exception->getMessage());
        }
        self::assertTrue($cleanupInterrupted);

        $after = $connection->selectOne('SELECT CONNECTION_ID() AS connection_id, @app_service_operational_capability AS capability, @app_service_batch_authority AS batch_authority');
        self::assertNotNull($after);
        self::assertNotSame($beforeConnectionId, (int) $after->connection_id);
        self::assertNull($after->capability);
        self::assertNull($after->batch_authority);
    }

    private function context(string $suffix, int $ownerId): ServiceOperationalContext
    {
        return new ServiceOperationalContext(
            'service-operational-'.$suffix,
            'svc-op-'.substr(hash('sha256', 'correlation:'.$suffix), 0, 32),
            'service_operational_test',
            'Service operational session safety test reason.',
            $ownerId,
        );
    }

    private function setBatchAuthorityWithCapability(): void
    {
        $connection = DB::connection();
        $this->app->make(ServiceOperationalDatabaseCapability::class)->apply($connection);
        $connection->statement("SET @app_service_batch_authority = 'service_batch_grant_v1'");
    }
}
