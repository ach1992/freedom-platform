<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Panels\Application\PanelApprovalGate;
use App\Modules\Panels\Application\PanelChangeContext;
use App\Modules\Panels\Application\PanelInventoryService;
use App\Modules\Panels\Domain\PanelTargetKind;
use App\Modules\Panels\Domain\ProtocolProfileDefinition;
use App\Modules\Panels\Domain\SalesServerVisibility;
use App\Modules\Panels\Domain\ServiceTargetConfiguration;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\PanelsAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CAT-002 CAT-004 PRV-001 ACL-002 ACL-003 SEC-001 SEC-002 DAT-003 QUA-001 */
final class PanelInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private RecordingPanelInventoryApprovalGate $approvalGate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(PanelsAccessFoundationSeeder::class);
        $this->approvalGate = new RecordingPanelInventoryApprovalGate;
        $this->app->instance(PanelApprovalGate::class, $this->approvalGate);
    }

    public function test_inventory_lifecycle_is_replay_safe_encrypted_and_audited_without_sensitive_configuration(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(PanelInventoryService::class);
        $definition = new ProtocolProfileDefinition(
            'vless',
            'ws',
            'tls',
            'profile.example.com',
            null,
            '/vpn',
            443,
            null,
        );
        $profileContext = $this->context($ownerId, 'panel-inventory-profile-create-0001');

        $profile = $service->createProtocolProfile(
            'vless-ws',
            'پروفایل وی‌لس',
            'VLESS WebSocket',
            $definition,
            $profileContext,
        );
        $profileReplay = $service->createProtocolProfile(
            'vless-ws',
            'پروفایل وی‌لس',
            'VLESS WebSocket',
            $definition,
            $profileContext,
        );
        self::assertTrue($profileReplay->replayed);
        self::assertSame($profile->targetId, $profileReplay->targetId);

        $profileId = (int) $profile->targetId;
        $service->activateProtocolProfile(
            $profileId,
            1,
            $this->context($ownerId, 'panel-inventory-profile-activate-001'),
        );

        $connectionId = $this->connection('inventory-core');
        $targetContext = $this->context($ownerId, 'panel-inventory-target-create-0001');
        $approvalId = (string) Str::ulid();
        $configuration = new ServiceTargetConfiguration(
            'inbound-42',
            'edge.internal.example',
            'sni.example.com',
            '/service',
            8443,
            null,
            'ws',
        );

        $target = $service->createServiceTarget(
            $approvalId,
            $connectionId,
            'tehran-inbound',
            PanelTargetKind::Inbound,
            'ورودی تهران',
            'Tehran Inbound',
            $configuration,
            1,
            ['create_service', 'fetch_status'],
            [$profileId],
            [$profileId],
            $targetContext,
        );
        $targetReplay = $service->createServiceTarget(
            $approvalId,
            $connectionId,
            'tehran-inbound',
            PanelTargetKind::Inbound,
            'ورودی تهران',
            'Tehran Inbound',
            $configuration,
            1,
            ['fetch_status', 'create_service'],
            [$profileId],
            [$profileId],
            $targetContext,
        );

        self::assertTrue($targetReplay->replayed);
        self::assertSame($target->targetId, $targetReplay->targetId);
        self::assertCount(1, $this->approvalGate->calls);

        try {
            $service->createServiceTarget(
                $approvalId,
                $connectionId,
                'tehran-inbound',
                PanelTargetKind::Inbound,
                'ورودی تهران',
                'Tehran Inbound',
                new ServiceTargetConfiguration('inbound-conflict', null, null, null, null, null, null),
                1,
                ['create_service', 'fetch_status'],
                [$profileId],
                [$profileId],
                $targetContext,
            );
            self::fail('Expected target mutation fingerprint conflict.');
        } catch (RuntimeException $exception) {
            self::assertSame('Panel mutation fingerprint conflict.', $exception->getMessage());
        }

        $targetId = (int) $target->targetId;
        $stored = DB::table('panel_service_targets')->where('id', $targetId)->first();
        self::assertNotNull($stored);
        self::assertStringNotContainsString('inbound-42', (string) $stored->encrypted_configuration);
        self::assertSame(
            $configuration->toArray(),
            json_decode(
                Crypt::decryptString((string) $stored->encrypted_configuration),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        );
        self::assertSame('declared', $stored->capability_status);
        self::assertSame(2, DB::table('panel_target_capabilities')->where('panel_service_target_id', $targetId)->count());
        self::assertTrue((bool) DB::table('panel_target_protocol_profiles')
            ->where('panel_service_target_id', $targetId)
            ->where('panel_protocol_profile_id', $profileId)
            ->value('customer_selectable'));

        $audit = DB::table('audit_logs')
            ->where('target_type', 'panel_service_target')
            ->where('target_id', (string) $targetId)
            ->get(['before_safe_data', 'after_safe_data'])
            ->map(static fn (object $row): string => (string) $row->before_safe_data.(string) $row->after_safe_data)
            ->implode('\n');
        foreach (['inbound-42', 'edge.internal.example', 'sni.example.com', '/service', 'ورودی تهران'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $audit);
        }

        $server = $service->createSalesServer(
            'tehran-visible',
            'تهران',
            'Tehran',
            'سرور فروش تهران',
            'Tehran sales server',
            10,
            $this->context($ownerId, 'panel-inventory-server-create-0001'),
        );
        $serverId = (int) $server->targetId;
        $service->activateSalesServer($serverId, 1, $this->context($ownerId, 'panel-inventory-server-activate-001'));
        $service->setSalesServerVisibility(
            $serverId,
            2,
            SalesServerVisibility::Listed,
            $this->context($ownerId, 'panel-inventory-server-list-000001'),
        );
        self::assertSame('listed', DB::table('sales_servers')->where('id', $serverId)->value('visibility'));
        $service->setSalesServerVisibility(
            $serverId,
            3,
            SalesServerVisibility::Hidden,
            $this->context($ownerId, 'panel-inventory-server-hide-000001'),
        );
        $service->disableSalesServer($serverId, 4, $this->context($ownerId, 'panel-inventory-server-disable-001'));
        $service->archiveSalesServer($serverId, 5, $this->context($ownerId, 'panel-inventory-server-archive-001'));

        $service->archiveServiceTarget($targetId, 1, $this->context($ownerId, 'panel-inventory-target-archive-0001'));
        $service->disableProtocolProfile($profileId, 2, $this->context($ownerId, 'panel-inventory-profile-disable-001'));
        $service->archiveProtocolProfile($profileId, 3, $this->context($ownerId, 'panel-inventory-profile-archive-001'));

        self::assertSame('archived', DB::table('sales_servers')->where('id', $serverId)->value('state'));
        self::assertSame('archived', DB::table('panel_service_targets')->where('id', $targetId)->value('state'));
        self::assertSame('archived', DB::table('panel_protocol_profiles')->where('id', $profileId)->value('state'));
    }

    public function test_technical_role_can_manage_public_inventory_but_cannot_write_encrypted_target_configuration(): void
    {
        $ownerId = $this->administrator(true);
        $technicalId = $this->administrator(false);
        $this->assignRole($technicalId, 'technical', $ownerId);
        $service = $this->app->make(PanelInventoryService::class);

        $profile = $service->createProtocolProfile(
            'technical-profile',
            'پروفایل فنی',
            null,
            new ProtocolProfileDefinition('vless', null, null, null, null, null, null, null),
            $this->context($technicalId, 'panel-inventory-technical-profile'),
        );
        self::assertTrue($profile->changed);

        $server = $service->createSalesServer(
            'technical-server',
            'سرور فنی',
            null,
            null,
            null,
            0,
            $this->context($technicalId, 'panel-inventory-technical-server0'),
        );
        self::assertTrue($server->changed);

        try {
            $service->createServiceTarget(
                (string) Str::ulid(),
                $this->connection('technical-connection'),
                'technical-secret-target',
                PanelTargetKind::Inbound,
                'هدف محرمانه',
                null,
                new ServiceTargetConfiguration('remote-secret', null, null, null, null, null, null),
                1,
                ['create_service'],
                [(int) $profile->targetId],
                [],
                $this->context($technicalId, 'panel-inventory-technical-target0'),
            );
            self::fail('Expected target secret permission denial.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('panel_service_targets')->where('code', 'technical-secret-target')->count());
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

    private function connection(string $code): int
    {
        $now = now('UTC');

        return (int) DB::table('panel_connections')->insertGetId([
            'code' => $code,
            'provider_type' => 'fake',
            'name_fa' => 'پنل',
            'name_en' => null,
            'base_url' => 'https://panel.example.com',
            'encrypted_credentials' => Crypt::encryptString('{"token":"test"}'),
            'credential_key_version' => 1,
            'tls_policy' => 'system_ca',
            'custom_ca_disk' => null,
            'custom_ca_path' => null,
            'certificate_pin_sha256' => null,
            'network_policy' => 'public_only',
            'state' => 'disabled',
            'last_test_status' => null,
            'last_panel_version' => null,
            'last_capabilities_hash' => null,
            'last_tested_at' => null,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): PanelChangeContext
    {
        return new PanelChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'panel_inventory_change',
            'Panel inventory foundation test change.',
            $administratorId,
        );
    }
}

final class RecordingPanelInventoryApprovalGate implements PanelApprovalGate
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
