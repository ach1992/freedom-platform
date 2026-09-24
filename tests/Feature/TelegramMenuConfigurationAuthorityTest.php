<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Telegram\Application\TelegramConfigurationChangeContext;
use App\Modules\Telegram\Application\TelegramInlineButtonStyle;
use App\Modules\Telegram\Application\TelegramMenuConfigurationDefinition;
use App\Modules\Telegram\Application\TelegramMenuConfigurationService;
use App\Modules\Telegram\Application\TelegramMenuItemDefinition;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\TelegramMenuAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement CNT-002 CNT-003 ACL-001 ACL-002 SEC-002 QUA-001 QUA-004 */
final class TelegramMenuConfigurationAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Telegram menu configuration authority verification requires MariaDB/MySQL.');
        }

        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(TelegramMenuAccessFoundationSeeder::class);
    }

    public function test_version_publish_rollback_and_replay_are_immutable_audited_and_generation_guarded(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(TelegramMenuConfigurationService::class);

        $v1 = $service->createVersion(
            'home',
            $this->definition('SUPPORT-V1', TelegramInlineButtonStyle::Primary),
            $this->context($ownerId, 'menu-create-v1-request'),
        );
        $v1Replay = $service->createVersion(
            'home',
            $this->definition('SUPPORT-V1', TelegramInlineButtonStyle::Primary),
            $this->context($ownerId, 'menu-create-v1-request'),
        );

        self::assertSame(1, $v1->version);
        self::assertSame($v1->id, $v1Replay->id);
        self::assertTrue($v1Replay->replayed);
        self::assertSame(1, DB::table('telegram_menu_configuration_versions')->count());
        self::assertSame([
            'id' => 1,
            'menu_key' => 'home',
            'active_version_id' => null,
            'generation' => 0,
            'next_version' => 2,
        ], $service->headSnapshot('home'));

        $publishedV1 = $service->publish(
            $v1->publicId,
            0,
            $this->context($ownerId, 'menu-publish-v1-request'),
        );
        self::assertTrue($publishedV1->changed);
        self::assertSame($v1->id, $service->active('home')?->id);
        self::assertSame(1, $service->headSnapshot('home')['generation']);

        $v2 = $service->createVersion(
            'home',
            $this->definition('SUPPORT-V2', TelegramInlineButtonStyle::Success),
            $this->context($ownerId, 'menu-create-v2-request'),
        );
        self::assertSame(2, $v2->version);
        $service->publish(
            $v2->publicId,
            1,
            $this->context($ownerId, 'menu-publish-v2-request'),
        );
        self::assertSame($v2->id, $service->active('home')?->id);
        self::assertSame(2, $service->headSnapshot('home')['generation']);

        $rollback = $service->rollback(
            'home',
            1,
            2,
            $this->context($ownerId, 'menu-rollback-v1-request'),
        );
        self::assertTrue($rollback->changed);
        self::assertSame($v1->id, $service->active('home')?->id);
        self::assertSame(3, $service->headSnapshot('home')['generation']);
        self::assertSame([2, 1], array_map(
            static fn ($version): int => $version->version,
            $service->history('home'),
        ));

        self::assertSame(2, DB::table('audit_logs')->where('action', 'telegram.menu.version.create')->count());
        self::assertSame(2, DB::table('audit_logs')->where('action', 'telegram.menu.publish')->count());
        self::assertSame(1, DB::table('audit_logs')->where('action', 'telegram.menu.rollback')->count());

        try {
            DB::table('telegram_menu_configuration_versions')
                ->where('id', $v1->id)
                ->update(['definition_hash' => str_repeat('a', 64)]);
            self::fail('Immutable Telegram menu version must reject direct updates.');
        } catch (QueryException) {
            self::assertSame($v1->definition->hash(), DB::table('telegram_menu_configuration_versions')
                ->where('id', $v1->id)
                ->value('definition_hash'));
        }

        try {
            DB::table('telegram_menu_configuration_heads')
                ->where('menu_key', 'home')
                ->update(['generation' => 99]);
            self::fail('Telegram menu head must reject invalid generation jumps.');
        } catch (QueryException) {
            self::assertSame(3, (int) DB::table('telegram_menu_configuration_heads')
                ->where('menu_key', 'home')
                ->value('generation'));
        }
    }

    public function test_permission_and_system_action_boundaries_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $salesId = $this->administrator();
        $unauthorizedId = $this->administrator();
        $this->assignRole($ownerId, $salesId, 'sales_content');

        $service = $this->app->make(TelegramMenuConfigurationService::class);

        try {
            $service->createVersion(
                'home',
                $this->definition('UNAUTHORIZED', TelegramInlineButtonStyle::Primary),
                $this->context($unauthorizedId, 'menu-unauthorized-request'),
            );
            self::fail('Unauthorized administrator must not create Telegram menu versions.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('telegram_menu_configuration_versions')->count());
        }

        $created = $service->createVersion(
            'home',
            $this->definition('AUTHORIZED', TelegramInlineButtonStyle::Primary),
            $this->context($salesId, 'menu-sales-content-request'),
        );
        self::assertSame(1, $created->version);

        $rebound = new TelegramMenuConfigurationDefinition([
            $this->system('my_account', 'purchase', 0),
            $this->system('my_services', 'my_services', 1),
        ]);
        try {
            $service->createVersion(
                'home',
                $rebound,
                $this->context($salesId, 'menu-rebind-request'),
            );
            self::fail('Protected Telegram system actions must not be rebound.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram system menu item cannot be rebound.', $exception->getMessage());
        }

        $missingEssential = new TelegramMenuConfigurationDefinition([
            $this->system('my_account', 'my_account', 0),
        ]);
        try {
            $service->createVersion(
                'home',
                $missingEssential,
                $this->context($salesId, 'menu-missing-essential-request'),
            );
            self::fail('Essential Telegram home navigation must remain enabled.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('essential navigation', $exception->getMessage());
        }
    }

    public function test_publish_requires_published_submenus_and_configuration_cannot_consume_reserved_row_or_condition_essential_navigation(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(TelegramMenuConfigurationService::class);

        foreach ([
            new TelegramMenuConfigurationDefinition([
                $this->system('my_account', 'my_account', 0),
                $this->system('my_services', 'my_services', 1),
                new TelegramMenuItemDefinition(
                    key: 'reserved',
                    kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                    actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                    actionKey: null,
                    labelFa: 'رزرو',
                    labelEn: 'Reserved',
                    normalEmoji: null,
                    premiumEmojiId: null,
                    style: null,
                    row: 9,
                    order: 0,
                    copyText: 'reserved',
                ),
            ]),
            new TelegramMenuConfigurationDefinition([
                new TelegramMenuItemDefinition(
                    key: 'my_account',
                    kind: TelegramMenuItemDefinition::KIND_SYSTEM,
                    actionType: TelegramMenuItemDefinition::ACTION_REGISTERED,
                    actionKey: 'my_account',
                    labelFa: null,
                    labelEn: null,
                    normalEmoji: null,
                    premiumEmojiId: null,
                    style: TelegramInlineButtonStyle::Primary,
                    row: 0,
                    order: 0,
                    language: 'fa',
                ),
                $this->system('my_services', 'my_services', 1),
            ]),
        ] as $definition) {
            try {
                $service->createVersion(
                    'home',
                    $definition,
                    $this->context($ownerId, 'menu-invalid-'.$definition->hash()),
                );
                self::fail('Reserved-row or conditional essential navigation must fail closed.');
            } catch (RuntimeException) {
                self::assertSame(0, DB::table('telegram_menu_configuration_versions')->count());
            }
        }

        $submenu = $service->createVersion(
            'submenu.help',
            new TelegramMenuConfigurationDefinition([
                new TelegramMenuItemDefinition(
                    key: 'copy_help',
                    kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                    actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                    actionKey: null,
                    labelFa: 'کپی راهنما',
                    labelEn: 'Copy help',
                    normalEmoji: '📋',
                    premiumEmojiId: null,
                    style: TelegramInlineButtonStyle::Primary,
                    row: 0,
                    order: 0,
                    copyText: 'HELP',
                ),
            ]),
            $this->context($ownerId, 'menu-submenu-help-create'),
        );
        $home = $service->createVersion(
            'home',
            new TelegramMenuConfigurationDefinition([
                $this->system('my_account', 'my_account', 0),
                $this->system('my_services', 'my_services', 1),
                new TelegramMenuItemDefinition(
                    key: 'help',
                    kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                    actionType: TelegramMenuItemDefinition::ACTION_SUBMENU,
                    actionKey: null,
                    labelFa: 'راهنما',
                    labelEn: 'Help',
                    normalEmoji: 'ℹ️',
                    premiumEmojiId: null,
                    style: TelegramInlineButtonStyle::Primary,
                    row: 2,
                    order: 0,
                    submenuKey: 'submenu.help',
                ),
            ]),
            $this->context($ownerId, 'menu-home-with-submenu-create'),
        );

        try {
            $service->publish(
                $home->publicId,
                0,
                $this->context($ownerId, 'menu-home-with-unpublished-submenu'),
            );
            self::fail('A home menu must not publish while a referenced submenu is unpublished.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram menu references an unpublished submenu.', $exception->getMessage());
        }

        self::assertSame(0, $service->headSnapshot('home')['generation']);
        $service->publish(
            $submenu->publicId,
            0,
            $this->context($ownerId, 'menu-submenu-help-publish'),
        );
        $service->publish(
            $home->publicId,
            0,
            $this->context($ownerId, 'menu-home-with-published-submenu'),
        );

        self::assertSame($home->id, $service->active('home')?->id);
        self::assertSame($submenu->id, $service->active('submenu.help')?->id);
    }

    public function test_database_rejects_cross_menu_active_version_pointer(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(TelegramMenuConfigurationService::class);
        $home = $service->createVersion(
            'home',
            $this->definition('HOME', TelegramInlineButtonStyle::Primary),
            $this->context($ownerId, 'menu-cross-home-create'),
        );
        $service->publish($home->publicId, 0, $this->context($ownerId, 'menu-cross-home-publish'));

        $submenu = $service->createVersion(
            'submenu.help',
            new TelegramMenuConfigurationDefinition([
                new TelegramMenuItemDefinition(
                    key: 'copy_help',
                    kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                    actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                    actionKey: null,
                    labelFa: 'کپی راهنما',
                    labelEn: 'Copy help',
                    normalEmoji: null,
                    premiumEmojiId: null,
                    style: null,
                    row: 0,
                    order: 0,
                    copyText: 'HELP',
                ),
            ]),
            $this->context($ownerId, 'menu-cross-submenu-create'),
        );

        try {
            DB::table('telegram_menu_configuration_heads')
                ->where('menu_key', 'home')
                ->update([
                    'active_version_id' => $submenu->id,
                    'generation' => 2,
                    'updated_by_administrator_id' => $ownerId,
                ]);
            self::fail('Cross-menu active version pointer must fail at the database boundary.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('same menu', $exception->getMessage());
        }

        self::assertSame($home->id, $service->active('home')?->id);
        self::assertSame(1, $service->headSnapshot('home')['generation']);
    }

    public function test_stale_publication_generation_cannot_overwrite_newer_active_menu(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(TelegramMenuConfigurationService::class);
        $v1 = $service->createVersion(
            'home',
            $this->definition('V1', TelegramInlineButtonStyle::Primary),
            $this->context($ownerId, 'menu-stale-create-v1'),
        );
        $v2 = $service->createVersion(
            'home',
            $this->definition('V2', TelegramInlineButtonStyle::Success),
            $this->context($ownerId, 'menu-stale-create-v2'),
        );

        $service->publish($v1->publicId, 0, $this->context($ownerId, 'menu-stale-publish-v1'));

        try {
            $service->publish($v2->publicId, 0, $this->context($ownerId, 'menu-stale-publish-v2'));
            self::fail('Stale Telegram menu publication generation must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Telegram menu publication generation conflict.', $exception->getMessage());
        }

        self::assertSame($v1->id, $service->active('home')?->id);
        self::assertSame(1, $service->headSnapshot('home')['generation']);
    }

    private function definition(string $copyText, TelegramInlineButtonStyle $style): TelegramMenuConfigurationDefinition
    {
        return new TelegramMenuConfigurationDefinition([
            $this->system('my_account', 'my_account', 0),
            $this->system('my_services', 'my_services', 1),
            new TelegramMenuItemDefinition(
                key: 'copy_support_code',
                kind: TelegramMenuItemDefinition::KIND_CUSTOM,
                actionType: TelegramMenuItemDefinition::ACTION_COPY_TEXT,
                actionKey: null,
                labelFa: 'کپی کد پشتیبانی',
                labelEn: 'Copy support code',
                normalEmoji: '📋',
                premiumEmojiId: '5368324170671202286',
                style: $style,
                row: 2,
                order: 0,
                copyText: $copyText,
            ),
        ]);
    }

    private function system(string $key, string $action, int $row): TelegramMenuItemDefinition
    {
        return new TelegramMenuItemDefinition(
            key: $key,
            kind: TelegramMenuItemDefinition::KIND_SYSTEM,
            actionType: TelegramMenuItemDefinition::ACTION_REGISTERED,
            actionKey: $action,
            labelFa: null,
            labelEn: null,
            normalEmoji: null,
            premiumEmojiId: null,
            style: TelegramInlineButtonStyle::Primary,
            row: $row,
            order: 0,
        );
    }

    private function administrator(bool $owner = false): int
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

    private function assignRole(int $ownerId, int $administratorId, string $roleCode): void
    {
        $roleId = (int) DB::table('roles')->where('code', $roleCode)->value('id');
        $now = now('UTC');
        DB::table('administrator_role_assignments')->insert([
            'administrator_id' => $administratorId,
            'role_id' => $roleId,
            'granted_by_administrator_id' => $ownerId,
            'granted_at' => $now,
            'revoked_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function context(int $administratorId, string $fingerprint): TelegramConfigurationChangeContext
    {
        return new TelegramConfigurationChangeContext(
            $fingerprint,
            'menu:'.substr(hash('sha256', $fingerprint), 0, 32),
            'telegram_menu_configuration',
            'Telegram menu configuration authority test change.',
            $administratorId,
        );
    }
}
