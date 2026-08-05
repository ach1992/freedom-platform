<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\PanelApprovalGate;
use App\Modules\Panels\Application\PanelChangeContext;
use App\Modules\Panels\Application\PanelConnectionService;
use App\Modules\Panels\Application\SensitivePanelApprovalGate;
use App\Modules\Panels\Domain\PanelNetworkPolicy;
use App\Modules\Panels\Domain\PanelProviderType;
use App\Modules\Panels\Domain\TlsConfiguration;
use App\Modules\Panels\Domain\TlsPolicy;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001 */
final class PanelConnectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecordingPanelApprovalGate $approvalGate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);

        self::assertInstanceOf(
            SensitivePanelApprovalGate::class,
            $this->app->make(PanelApprovalGate::class),
        );

        $this->approvalGate = new RecordingPanelApprovalGate();
        $this->app->instance(PanelApprovalGate::class, $this->approvalGate);
    }

    public function test_connection_configuration_is_encrypted_replay_safe_and_audited_without_secret_leakage(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(PanelConnectionService::class);
        $systemTls = new TlsConfiguration(TlsPolicy::SystemCa, null, null, null);
        $createContext = $this->context($ownerId, 'panels-connection-create-0001');
        $createApprovalId = (string) Str::ulid();

        $created = $service->create(
            $createApprovalId,
            'core-panel',
            PanelProviderType::Marzban,
            'پنل اصلی',
            'Core Panel',
            'https://panel.example.com/api',
            ['token' => 'first-secret-token', 'username' => 'service-user'],
            1,
            $systemTls,
            PanelNetworkPolicy::PublicOnly,
            $createContext,
        );
        $replay = $service->create(
            $createApprovalId,
            'core-panel',
            PanelProviderType::Marzban,
            'پنل اصلی',
            'Core Panel',
            'https://panel.example.com/api',
            ['token' => 'first-secret-token', 'username' => 'service-user'],
            1,
            $systemTls,
            PanelNetworkPolicy::PublicOnly,
            $createContext,
        );

        self::assertTrue($created->changed);
        self::assertTrue($replay->replayed);
        self::assertSame($created->targetId, $replay->targetId);
        self::assertCount(1, $this->approvalGate->calls);

        try {
            $service->create(
                $createApprovalId,
                'core-panel',
                PanelProviderType::Marzban,
                'پنل اصلی',
                'Core Panel',
                'https://panel.example.com/api',
                ['token' => 'conflicting-secret'],
                1,
                $systemTls,
                PanelNetworkPolicy::PublicOnly,
                $createContext,
            );
            self::fail('Expected panel mutation fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Panel mutation fingerprint conflict.', $exception->getMessage());
        }
        self::assertCount(1, $this->approvalGate->calls);

        $connectionId = (int) $created->targetId;
        $stored = DB::table('panel_connections')->where('id', $connectionId)->first();
        self::assertNotNull($stored);
        self::assertStringNotContainsString('first-secret-token', (string) $stored->encrypted_credentials);
        self::assertSame(
            ['token' => 'first-secret-token', 'username' => 'service-user'],
            json_decode(
                Crypt::decryptString((string) $stored->encrypted_credentials),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame('disabled', $stored->state);
        self::assertNull($stored->last_tested_at);

        $service->rotateCredentials(
            (string) Str::ulid(),
            $connectionId,
            1,
            ['token' => 'second-secret-token'],
            2,
            $this->context($ownerId, 'panels-connection-rotate-0001'),
        );

        $customTls = new TlsConfiguration(
            TlsPolicy::CustomCa,
            'private',
            'panel-cas/core.pem',
            null,
        );
        $service->reconfigure(
            (string) Str::ulid(),
            $connectionId,
            2,
            'پنل اصلی جدید',
            'Updated Core Panel',
            'https://10.20.30.40:8443/api',
            $customTls,
            PanelNetworkPolicy::PrivateAllowed,
            $this->context($ownerId, 'panels-connection-config-0001'),
        );

        $stored = DB::table('panel_connections')->where('id', $connectionId)->first();
        self::assertNotNull($stored);
        self::assertSame(
            ['token' => 'second-secret-token'],
            json_decode(
                Crypt::decryptString((string) $stored->encrypted_credentials),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame(2, (int) $stored->credential_key_version);
        self::assertSame('custom_ca', $stored->tls_policy);
        self::assertSame('private_allowed', $stored->network_policy);
        self::assertSame('disabled', $stored->state);
        self::assertNull($stored->last_test_status);

        DB::table('panel_connections')->where('id', $connectionId)->update([
            'state' => 'active',
            'last_test_status' => 'success',
            'last_panel_version' => 'test-1.0.0',
            'last_capabilities_hash' => hash('sha256', 'test-capabilities'),
            'last_tested_at' => now('UTC'),
        ]);

        $service->disable(
            $connectionId,
            3,
            $this->context($ownerId, 'panels-connection-disable-001'),
        );
        $service->archive(
            $connectionId,
            4,
            $this->context($ownerId, 'panels-connection-archive-001'),
        );

        self::assertSame('archived', DB::table('panel_connections')
            ->where('id', $connectionId)
            ->value('state'));
        self::assertSame(5, DB::table('panel_connection_histories')
            ->where('panel_connection_id', $connectionId)
            ->count());
        self::assertSame(5, DB::table('audit_logs')
            ->where('target_type', 'panel_connection')
            ->where('target_id', (string) $connectionId)
            ->count());
        self::assertCount(3, $this->approvalGate->calls);

        $audit = DB::table('audit_logs')
            ->where('target_type', 'panel_connection')
            ->where('target_id', (string) $connectionId)
            ->get(['before_safe_data', 'after_safe_data'])
            ->map(static fn (object $row): string => (string) $row->before_safe_data.(string) $row->after_safe_data)
            ->implode('\n');

        foreach ([
            'first-secret-token',
            'second-secret-token',
            'panel.example.com',
            '10.20.30.40',
            'panel-cas/core.pem',
            'پنل اصلی',
        ] as $sensitiveValue) {
            self::assertStringNotContainsString($sensitiveValue, $audit);
        }
    }

    public function test_technical_role_can_manage_lifecycle_but_cannot_configure_credentials(): void
    {
        $ownerId = $this->administrator(true);
        $technicalId = $this->administrator(false);
        $this->assignRole($technicalId, 'technical', $ownerId);
        $service = $this->app->make(PanelConnectionService::class);

        $created = $service->create(
            (string) Str::ulid(),
            'technical-managed',
            PanelProviderType::Fake,
            'پنل فنی',
            null,
            'https://panel.example.com',
            ['token' => 'owner-configured-secret'],
            1,
            new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
            PanelNetworkPolicy::PublicOnly,
            $this->context($ownerId, 'panels-owner-create-technical'),
        );

        $service->archive(
            (int) $created->targetId,
            1,
            $this->context($technicalId, 'panels-technical-archive-001'),
        );
        self::assertSame('archived', DB::table('panel_connections')
            ->where('id', (int) $created->targetId)
            ->value('state'));

        try {
            $service->create(
                (string) Str::ulid(),
                'technical-secret-denied',
                PanelProviderType::Fake,
                'پنل غیرمجاز',
                null,
                'https://denied.example.com',
                ['token' => 'not-authorized'],
                1,
                new TlsConfiguration(TlsPolicy::SystemCa, null, null, null),
                PanelNetworkPolicy::PublicOnly,
                $this->context($technicalId, 'panels-technical-secret-denied'),
            );
            self::fail('Expected panel secret permission denial.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('panel_connections')
                ->where('code', 'technical-secret-denied')
                ->count());
        }
    }

    private function administrator(bool $owner): int
    {
        $now = now('UTC');

        return (int) DB::table('administrators')->insertGetId([
            'user_id' => DB::table('users')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'account_type' => 'customer',
                'account_status' => 'active',
                'locale' => 'fa',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assignRole(int $administratorId, string $roleCode, int $grantedBy): void
    {
        $now = now('UTC');
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');

        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $grantedBy,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): PanelChangeContext
    {
        return new PanelChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'panel_test_change',
            'Panel connection foundation test change.',
            $administratorId,
        );
    }
}

final class RecordingPanelApprovalGate implements PanelApprovalGate
{
    /** @var list<array{approval_id: string, action: string, target_type: string, target_id: string}> */
    public array $calls = [];

    public function consume(
        string $approvalId,
        string $action,
        string $targetType,
        string $targetId,
        PanelChangeContext $context,
    ): void {
        $this->calls[] = [
            'approval_id' => $approvalId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
        ];
    }
}
