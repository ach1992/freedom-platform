<?php

declare(strict_types=1);

namespace {
    use App\Modules\AccessControl\Application\AccessChangeContext;
    use App\Modules\AccessControl\Application\AdministratorAccessService;
    use App\Modules\AccessControl\Domain\PermissionEffect;
    use App\Modules\Agents\Application\AgentApplicationService;
    use App\Modules\Agents\Application\AgentChangeContext;
    use App\Modules\Agents\Application\AgentProfileService;
    use App\Modules\Agents\Domain\AgentStatus;
    use App\Modules\Identity\Application\IdentityChangeContext;
    use App\Modules\Identity\Application\IdentityItemService;
    use App\Modules\Identity\Domain\IdentityItemType;
    use Illuminate\Contracts\Console\Kernel;
    use Illuminate\Database\DatabaseManager;

    $mode = $argv[1] ?? null;
    if (PHP_SAPI === 'cli' && in_array($mode, [
        '--transactional-auth-revoke-worker',
        '--transactional-auth-mutation-worker',
    ], true)) {
        require dirname(__DIR__, 2).'/vendor/autoload.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        try {
            $encoded = $argv[2] ?? '';
            if (! is_string($encoded)) {
                throw new RuntimeException('Transactional authorization worker payload is missing.');
            }

            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                throw new RuntimeException('Transactional authorization worker payload is invalid.');
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Transactional authorization worker bootstrap failed: '.$exception->getMessage()."\n");
            exit(2);
        }

        /** @var DatabaseManager $database */
        $database = $app->make(DatabaseManager::class);
        $connection = $database->connection();
        $connection->statement('SET SESSION innodb_lock_wait_timeout = 5');

        if ($mode === '--transactional-auth-revoke-worker') {
            $connection->beginTransaction();

            try {
                $reviewerId = (int) ($payload['reviewer_id'] ?? 0);
                $ownerId = (int) ($payload['owner_id'] ?? 0);
                $permissionCode = $payload['permission_code'] ?? null;
                $revokeFingerprint = $payload['revoke_fingerprint'] ?? null;
                $revokeCorrelation = $payload['revoke_correlation'] ?? null;

                if ($reviewerId < 1
                    || $ownerId < 1
                    || ! is_string($permissionCode)
                    || ! is_string($revokeFingerprint)
                    || ! is_string($revokeCorrelation)) {
                    throw new RuntimeException('Transactional authorization revoke worker payload is incomplete.');
                }

                $reviewer = $connection->table('administrators')
                    ->where('id', $reviewerId)
                    ->lockForUpdate()
                    ->first(['id']);
                if ($reviewer === null) {
                    throw new RuntimeException('Transactional authorization reviewer is missing.');
                }

                echo "LOCKED\n";
                flush();

                if (trim((string) fgets(STDIN)) !== 'STAGE') {
                    throw new RuntimeException('Transactional authorization revoke stage command is invalid.');
                }

                $app->make(AdministratorAccessService::class)->setPermissionOverride(
                    $reviewerId,
                    $permissionCode,
                    PermissionEffect::Deny,
                    new AccessChangeContext(
                        $revokeFingerprint,
                        $revokeCorrelation,
                        'transactional_revoke',
                        'Transactional authorization regression test revocation.',
                        $ownerId,
                    ),
                );

                echo "DENY_STAGED\n";
                flush();

                if (trim((string) fgets(STDIN)) !== 'COMMIT') {
                    throw new RuntimeException('Transactional authorization revoke commit command is invalid.');
                }

                $connection->commit();
                echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
            } catch (Throwable $exception) {
                while ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }

                echo json_encode([
                    'ok' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR)."\n";
            }

            exit(0);
        }

        echo "READY\n";
        flush();

        if (trim((string) fgets(STDIN)) !== 'GO') {
            fwrite(STDERR, "Transactional authorization mutation worker barrier was not released.\n");
            exit(2);
        }

        try {
            $action = $payload['action'] ?? null;
            $reviewerId = (int) ($payload['reviewer_id'] ?? 0);
            $requestFingerprint = $payload['request_fingerprint'] ?? null;
            $correlationId = $payload['correlation_id'] ?? null;

            if (! is_string($action)
                || $reviewerId < 1
                || ! is_string($requestFingerprint)
                || ! is_string($correlationId)) {
                throw new RuntimeException('Transactional authorization mutation worker payload is incomplete.');
            }

            match ($action) {
                'identity_verify' => $app->make(IdentityItemService::class)->verify(
                    (int) ($payload['user_id'] ?? 0),
                    IdentityItemType::FullName,
                    new IdentityChangeContext(
                        $requestFingerprint,
                        $correlationId,
                        'transactional_review',
                        'Transactional identity review.',
                        actorAdministratorId: $reviewerId,
                    ),
                ),
                'agent_claim' => $app->make(AgentApplicationService::class)->claim(
                    (int) ($payload['application_id'] ?? 0),
                    new AgentChangeContext(
                        $requestFingerprint,
                        $correlationId,
                        'transactional_review',
                        'Transactional agent review.',
                        actorAdministratorId: $reviewerId,
                    ),
                ),
                'agent_suspend' => $app->make(AgentProfileService::class)->transitionStatus(
                    (int) ($payload['user_id'] ?? 0),
                    AgentStatus::Suspended,
                    new AgentChangeContext(
                        $requestFingerprint,
                        $correlationId,
                        'transactional_review',
                        'Transactional agent status review.',
                        actorAdministratorId: $reviewerId,
                    ),
                ),
                default => throw new RuntimeException('Transactional authorization mutation action is invalid.'),
            };

            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR)."\n";
        } catch (Throwable $exception) {
            echo json_encode([
                'ok' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR)."\n";
        }

        exit(0);
    }
}

namespace Tests\Feature {
    use App\Modules\Agents\Application\AgentApplicationService;
    use App\Modules\Agents\Application\AgentChangeContext;
    use App\Modules\Identity\Application\IdentityChangeContext;
    use App\Modules\Identity\Application\IdentityItemService;
    use App\Modules\Identity\Domain\IdentityItemType;
    use Database\Seeders\IdentityAccessFoundationSeeder;
    use Illuminate\Auth\Access\AuthorizationException;
    use Illuminate\Foundation\Testing\DatabaseTruncation;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Str;
    use RuntimeException;
    use Tests\TestCase;

    /** @requirement ACL-002 ADM-002 AGT-002 SEC-003 QUA-001 */
    final class TransactionalAdministratorAuthorizationTest extends TestCase
    {
        use DatabaseTruncation;

        private const WORKER_TIMEOUT_SECONDS = 15;

        protected function setUp(): void
        {
            parent::setUp();
            $this->seed(IdentityAccessFoundationSeeder::class);
        }

        protected function tearDown(): void
        {
            try {
                $this->truncateDatabaseTables();
            } finally {
                parent::tearDown();
            }
        }

        public function test_committed_deny_blocks_identity_verification_after_preflight_authorization(): void
        {
            $ownerId = $this->administrator(true);
            $reviewerId = $this->administrator();
            $this->grantPermission($reviewerId, 'identity.verifications.manage');
            $userId = $this->user();
            $service = $this->app->make(IdentityItemService::class);
            $service->submit(
                $userId,
                IdentityItemType::FullName,
                'سارا احمدی',
                false,
                new IdentityChangeContext(
                    'transactional-identity-submit-0001',
                    'transactional-identity-correlation-0001',
                    'user_submission',
                    actorUserId: $userId,
                ),
            );

            $this->assertCommittedDenyWins($ownerId, $reviewerId, 'identity.verifications.manage', [
                'action' => 'identity_verify',
                'user_id' => $userId,
                'request_fingerprint' => 'transactional-identity-verify-0001',
                'correlation_id' => 'transactional-identity-verify-corr-0001',
            ]);

            self::assertSame(
                'pending',
                DB::table('identity_items')->where('user_id', $userId)->where('type', 'full_name')->value('state'),
            );
            self::assertSame(1, DB::table('identity_item_histories')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'identity.item.verify')->count());
        }

        public function test_committed_deny_blocks_agent_application_claim_after_preflight_authorization(): void
        {
            $ownerId = $this->administrator(true);
            $reviewerId = $this->administrator();
            $this->grantPermission($reviewerId, 'agents.applications.review');
            $customerId = $this->user();
            $service = $this->app->make(AgentApplicationService::class);
            $service->submit(
                $customerId,
                new AgentChangeContext(
                    'transactional-agent-submit-0001',
                    'transactional-agent-submit-corr-0001',
                    'customer_request',
                    actorUserId: $customerId,
                ),
            );
            $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');

            $this->assertCommittedDenyWins($ownerId, $reviewerId, 'agents.applications.review', [
                'action' => 'agent_claim',
                'application_id' => $applicationId,
                'request_fingerprint' => 'transactional-agent-claim-0001',
                'correlation_id' => 'transactional-agent-claim-corr-0001',
            ]);

            self::assertSame(
                'submitted',
                DB::table('agent_applications')->where('id', $applicationId)->value('state'),
            );
            self::assertSame(1, DB::table('agent_application_histories')->where('application_id', $applicationId)->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'agent.application.claim')->count());
        }

        public function test_committed_deny_blocks_agent_profile_transition_after_preflight_authorization(): void
        {
            $ownerId = $this->administrator(true);
            $reviewerId = $this->administrator();
            $this->grantPermission($reviewerId, 'agents.accounts.manage');
            $customerId = $this->user();
            $applicationService = $this->app->make(AgentApplicationService::class);
            $applicationService->submit(
                $customerId,
                new AgentChangeContext(
                    'transactional-profile-submit-0001',
                    'transactional-profile-submit-corr-0001',
                    'customer_request',
                    actorUserId: $customerId,
                ),
            );
            $applicationId = (int) DB::table('agent_applications')->where('customer_id', $customerId)->value('id');
            $applicationService->claim(
                $applicationId,
                new AgentChangeContext(
                    'transactional-profile-claim-0001',
                    'transactional-profile-claim-corr-0001',
                    'review_action',
                    'Owner claim for transactional authorization setup.',
                    actorAdministratorId: $ownerId,
                ),
            );
            $applicationService->approve(
                $applicationId,
                'default',
                new AgentChangeContext(
                    'transactional-profile-approve-0001',
                    'transactional-profile-approve-corr-0001',
                    'approved',
                    'Owner approval for transactional authorization setup.',
                    actorAdministratorId: $ownerId,
                ),
            );

            $this->assertCommittedDenyWins($ownerId, $reviewerId, 'agents.accounts.manage', [
                'action' => 'agent_suspend',
                'user_id' => $customerId,
                'request_fingerprint' => 'transactional-agent-suspend-0001',
                'correlation_id' => 'transactional-agent-suspend-corr-0001',
            ]);

            self::assertSame(
                'active',
                DB::table('agent_profiles')->where('user_id', $customerId)->value('status'),
            );
            self::assertSame(0, DB::table('agent_status_histories')->count());
            self::assertSame(0, DB::table('audit_logs')->where('action', 'agent.profile.status')->count());
        }

        /**
         * @param  array{
         *     action: 'identity_verify'|'agent_claim'|'agent_suspend',
         *     application_id?: int,
         *     user_id?: int,
         *     request_fingerprint: string,
         *     correlation_id: string
         * }  $mutationPayload
         */
        private function assertCommittedDenyWins(
            int $ownerId,
            int $reviewerId,
            string $permissionCode,
            array $mutationPayload,
        ): void {
            $revoker = $this->startWorker('--transactional-auth-revoke-worker', [
                'owner_id' => $ownerId,
                'reviewer_id' => $reviewerId,
                'permission_code' => $permissionCode,
                'revoke_fingerprint' => 'transactional-revoke-'.$mutationPayload['action'].'-0001',
                'revoke_correlation' => 'transactional-revoke-'.$mutationPayload['action'].'-corr-0001',
            ]);
            $mutation = null;

            try {
                self::assertSame("LOCKED\n", $this->readLine($revoker, 'revoker administrator lock'));
                $this->sendCommand($revoker, 'STAGE');
                self::assertSame("DENY_STAGED\n", $this->readLine($revoker, 'staged permission deny'));

                $mutation = $this->startWorker(
                    '--transactional-auth-mutation-worker',
                    $mutationPayload + ['reviewer_id' => $reviewerId],
                );
                self::assertSame("READY\n", $this->readLine($mutation, 'mutation worker readiness'));
                $this->sendCommand($mutation, 'GO');

                $this->assertWorkerRemainsBlocked($mutation);

                $this->sendCommand($revoker, 'COMMIT');
                $revokerResult = $this->readJsonResult($revoker, 'revoker commit result');
                self::assertTrue((bool) ($revokerResult['ok'] ?? false), $this->diagnostic($revokerResult));

                $mutationResult = $this->readJsonResult($mutation, 'mutation worker result');
                self::assertFalse((bool) ($mutationResult['ok'] ?? false), $this->diagnostic($mutationResult));
                self::assertSame(AuthorizationException::class, $mutationResult['exception'] ?? null);
            } finally {
                $this->closeWorker($revoker);
                if ($mutation !== null) {
                    $this->closeWorker($mutation);
                }
            }
        }

        /** @param  array<string, mixed> $payload
         * @return array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}
         */
        private function startWorker(string $mode, array $payload): array
        {
            $pipes = [];
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'pcov.enabled=0',
                __FILE__,
                $mode,
                base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
            ], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());

            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start transactional authorization worker.');
            }

            /** @var array{0: resource, 1: resource, 2: resource} $pipes */
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            return ['process' => $process, 'pipes' => $pipes];
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         */
        private function sendCommand(array $worker, string $command): void
        {
            fwrite($worker['pipes'][0], $command."\n");
            fflush($worker['pipes'][0]);
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         */
        private function assertWorkerRemainsBlocked(array $worker): void
        {
            usleep(500_000);
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            $status = proc_get_status($worker['process']);

            self::assertSame('', trim((string) $stdout), 'Mutation completed before the staged revoke committed.');
            self::assertSame('', trim((string) $stderr), 'Mutation worker wrote an unexpected error before revoke commit.');
            self::assertTrue((bool) $status['running'], 'Mutation worker exited before the staged revoke committed.');
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         * @return array<string, mixed>
         */
        private function readJsonResult(array $worker, string $phase): array
        {
            /** @var array<string, mixed> $result */
            $result = json_decode($this->readLine($worker, $phase), true, flags: JSON_THROW_ON_ERROR);

            return $result;
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         */
        private function closeWorker(array $worker): void
        {
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (! is_resource($worker['process'])) {
                return;
            }

            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            proc_close($worker['process']);
        }

        /**
         * @param  array{process: resource, pipes: array{0: resource, 1: resource, 2: resource}}  $worker
         */
        private function readLine(array $worker, string $phase): string
        {
            $deadline = microtime(true) + self::WORKER_TIMEOUT_SECONDS;
            $stderr = '';

            while (microtime(true) < $deadline) {
                $read = [$worker['pipes'][1], $worker['pipes'][2]];
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200_000);
                if ($selected === false) {
                    throw new RuntimeException('Unable to wait for transactional authorization worker output.');
                }

                foreach ($read as $stream) {
                    if ($stream === $worker['pipes'][2]) {
                        $stderr .= stream_get_contents($stream);

                        continue;
                    }

                    $line = fgets($stream);
                    if ($line !== false) {
                        return $line;
                    }
                }

                $status = proc_get_status($worker['process']);
                if (! $status['running'] && feof($worker['pipes'][1])) {
                    $stderr .= stream_get_contents($worker['pipes'][2]);
                    throw new RuntimeException(
                        'Transactional authorization worker exited before '.$phase.' output: '.trim($stderr),
                    );
                }
            }

            throw new RuntimeException('Transactional authorization worker timed out during '.$phase.': '.trim($stderr));
        }

        /** @param  array<string, mixed> $result */
        private function diagnostic(array $result): string
        {
            return json_encode($result, JSON_THROW_ON_ERROR);
        }

        private function grantPermission(int $administratorId, string $permissionCode): void
        {
            $now = now('UTC');
            $roleId = (int) DB::table('roles')->where('code', 'support')->value('id');
            $permissionId = (int) DB::table('permissions')->where('code', $permissionCode)->value('id');

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('administrator_role_assignments')->insert([
                'administrator_id' => $administratorId,
                'role_id' => $roleId,
                'granted_by_administrator_id' => null,
                'granted_at' => $now,
                'revoked_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function administrator(bool $owner = false): int
        {
            $now = now('UTC');

            return (int) DB::table('administrators')->insertGetId([
                'user_id' => $this->user(),
                'status' => 'active',
                'is_owner' => $owner,
                'permission_version' => 1,
                'last_authenticated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        private function user(): int
        {
            $now = now('UTC');

            return (int) DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
