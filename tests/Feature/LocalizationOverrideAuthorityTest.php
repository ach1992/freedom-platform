<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationChangeContext;
use App\Modules\Localization\Application\LocalizationOverrideService;
use App\Modules\Localization\Application\LocalizationResolver;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\LocalizationAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** @requirement LOC-001 CNT-001 SEC-002 QUA-001 QUA-003 QUA-004 */
final class LocalizationOverrideAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(LocalizationAccessFoundationSeeder::class);
    }

    public function test_file_defaults_and_current_fa_en_resources_have_exact_key_and_placeholder_parity(): void
    {
        $resolver = $this->app->make(LocalizationResolver::class);
        $translator = $this->app->make('translator');

        self::assertSame(
            $translator->get('identity.otp.sms_message', ['code' => '123456'], 'fa'),
            $resolver->resolve('identity.otp.sms_message', ['code' => '123456'], 'fa'),
        );
        self::assertSame(
            $translator->get('identity.otp.sms_message', ['code' => '123456'], 'en'),
            $resolver->resolve('identity.otp.sms_message', ['code' => '123456'], 'en'),
        );

        $translator->addLines(['localization_probe.english_only' => 'English fallback :name'], 'en');
        self::assertSame(
            'English fallback Ada',
            $resolver->resolve('localization_probe.english_only', ['name' => 'Ada'], 'fa'),
        );
        self::assertSame('[localization_probe.missing]', $resolver->resolve('localization_probe.missing', [], 'fa'));

        $faFiles = array_map('basename', glob(resource_path('lang/fa/*.php')) ?: []);
        $enFiles = array_map('basename', glob(resource_path('lang/en/*.php')) ?: []);
        sort($faFiles);
        sort($enFiles);
        self::assertSame($faFiles, $enFiles);

        foreach ($faFiles as $file) {
            $fa = $this->flatten(require resource_path('lang/fa/'.$file));
            $en = $this->flatten(require resource_path('lang/en/'.$file));
            self::assertSame(array_keys($fa), array_keys($en), 'Translation-key parity failed for '.$file);

            foreach ($fa as $key => $value) {
                self::assertSame(
                    $this->placeholders($value),
                    $this->placeholders($en[$key]),
                    'Placeholder parity failed for '.$file.':'.$key,
                );
            }
        }
    }

    public function test_override_edit_reset_restore_and_replay_are_versioned_and_audited_without_plaintext(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(LocalizationOverrideService::class);
        $resolver = $this->app->make(LocalizationResolver::class);
        $key = 'identity.otp.sms_message';
        $firstValue = 'کد تایید جدید شما: :code';
        $secondValue = 'کد یک‌بارمصرف شما: :code';

        $created = $service->set($key, 'fa', $firstValue, null, $this->context($ownerId, 'localization-set-request-0001'));
        $replayed = $service->set($key, 'fa', $firstValue, null, $this->context($ownerId, 'localization-set-request-0001'));
        self::assertTrue($created->changed);
        self::assertSame(1, $created->version);
        self::assertTrue($replayed->replayed);
        self::assertSame($created->overrideId, $replayed->overrideId);
        self::assertSame('کد تایید جدید شما: 654321', $resolver->resolve($key, ['code' => '654321'], 'fa'));

        $updated = $service->set($key, 'fa', $secondValue, 1, $this->context($ownerId, 'localization-set-request-0002'));
        self::assertSame(2, $updated->version);
        self::assertSame('کد یک‌بارمصرف شما: 654321', $resolver->resolve($key, ['code' => '654321'], 'fa'));

        $reset = $service->reset($key, 'fa', 2, $this->context($ownerId, 'localization-reset-req-0001'));
        self::assertSame(3, $reset->version);
        self::assertNull(DB::table('localization_overrides')->where('id', $created->overrideId)->value('override_value'));
        self::assertSame(
            $this->app->make('translator')->get($key, ['code' => '654321'], 'fa'),
            $resolver->resolve($key, ['code' => '654321'], 'fa'),
        );

        $restored = $service->restore($key, 'fa', 1, 3, $this->context($ownerId, 'localization-restore-0001'));
        self::assertSame(4, $restored->version);
        self::assertSame('کد تایید جدید شما: 654321', $resolver->resolve($key, ['code' => '654321'], 'fa'));

        self::assertSame(1, DB::table('localization_overrides')->where('translation_key', $key)->where('locale', 'fa')->count());
        self::assertSame(4, DB::table('localization_override_versions')->where('localization_override_id', $created->overrideId)->count());
        self::assertSame([4, 3, 2, 1], array_column($service->history($ownerId, $key, 'fa'), 'version'));

        $auditText = DB::table('audit_logs')
            ->where('target_type', 'localization_override')
            ->orderBy('id')
            ->get(['before_safe_data', 'after_safe_data'])
            ->map(static fn (object $row): string => (string) $row->before_safe_data.(string) $row->after_safe_data)
            ->implode('\n');
        self::assertStringNotContainsString($firstValue, $auditText);
        self::assertStringNotContainsString($secondValue, $auditText);
        self::assertStringContainsString(hash('sha256', $firstValue), $auditText);
    }

    public function test_authorization_placeholder_and_optimistic_version_validation_fail_closed(): void
    {
        $ownerId = $this->administrator(true);
        $salesId = $this->administrator();
        $unauthorizedId = $this->administrator();
        $this->assignRole($ownerId, $salesId, 'sales_content');
        $service = $this->app->make(LocalizationOverrideService::class);
        $key = 'identity.otp.sms_message';

        self::assertSame(1, DB::table('permissions')->where('code', 'localization.manage')->count());
        self::assertSame('code', implode(',', $service->preview($salesId, $key, 'en', 'New code: :code')['placeholders']));

        try {
            $service->preview($unauthorizedId, $key, 'en', 'New code: :code');
            self::fail('Unauthorized administrator must not preview localization overrides.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('localization_overrides')->count());
        }

        foreach (['Missing placeholder', 'Unknown :code and :other'] as $invalidValue) {
            try {
                $service->set($key, 'en', $invalidValue, null, $this->context($salesId, 'localization-invalid-'.substr(hash('sha256', $invalidValue), 0, 20)));
                self::fail('Invalid placeholder sets must fail closed.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('localization_overrides')->count());
            }
        }

        try {
            $service->view($salesId, $key, 'de');
            self::fail('Unsupported localization locale must fail closed.');
        } catch (InvalidArgumentException) {
            self::assertSame(0, DB::table('localization_overrides')->count());
        }

        $created = $service->set($key, 'en', 'Verification code: :code', null, $this->context($salesId, 'localization-valid-set-0001'));
        $service->set($key, 'en', 'Your secure code: :code', 1, $this->context($salesId, 'localization-valid-set-0002'));
        self::assertSame(1, $created->version);

        try {
            $service->set($key, 'en', 'Another code: :code', 1, $this->context($salesId, 'localization-stale-set-0001'));
            self::fail('Stale localization version must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Localization override version conflict.', $exception->getMessage());
            self::assertSame(2, (int) DB::table('localization_overrides')->where('id', $created->overrideId)->value('version'));
        }
    }

    public function test_mariadb_guards_current_version_and_immutable_history(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MariaDB direct authority verification requires mysql driver.');
        }

        $ownerId = $this->administrator(true);
        $service = $this->app->make(LocalizationOverrideService::class);
        $receipt = $service->set(
            'identity.otp.sms_message',
            'en',
            'Verification code: :code',
            null,
            $this->context($ownerId, 'localization-guard-set-0001'),
        );

        try {
            DB::table('localization_overrides')->where('id', $receipt->overrideId)->update([
                'override_value' => 'Direct bypass: :code',
                'updated_at' => now('UTC'),
            ]);
            self::fail('MariaDB must reject an update without exact version advance.');
        } catch (QueryException) {
            self::assertSame(1, (int) DB::table('localization_overrides')->where('id', $receipt->overrideId)->value('version'));
        }

        $historyId = (int) DB::table('localization_override_versions')->where('localization_override_id', $receipt->overrideId)->value('id');
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    DB::table('localization_override_versions')->where('id', $historyId)->update(['action' => 'reset']);
                } else {
                    DB::table('localization_override_versions')->where('id', $historyId)->delete();
                }
                self::fail('Localization history must be immutable.');
            } catch (QueryException) {
                self::assertSame(1, DB::table('localization_override_versions')->where('id', $historyId)->count());
            }
        }

        try {
            DB::table('localization_overrides')->where('id', $receipt->overrideId)->delete();
            self::fail('Current localization override must be reset instead of deleted.');
        } catch (QueryException) {
            self::assertSame(1, DB::table('localization_overrides')->where('id', $receipt->overrideId)->count());
        }
    }

    /** @param array<string, mixed> $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $key => $value) {
            $qualified = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flat += $this->flatten($value, $qualified);
            } elseif (is_string($value)) {
                $flat[$qualified] = $value;
            }
        }
        ksort($flat);

        return $flat;
    }

    /** @return list<string> */
    private function placeholders(string $template): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $template, $matches);
        $placeholders = array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
        sort($placeholders);

        return $placeholders;
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

    private function context(int $administratorId, string $fingerprint): LocalizationChangeContext
    {
        return new LocalizationChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'localization_configuration',
            'Localization authority test change.',
            $administratorId,
        );
    }
}
