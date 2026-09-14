<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Localization\Application\LocalizationChangeContext;
use App\Modules\Localization\Application\LocalizationOverrideService;
use App\Modules\Localization\Application\LocalizationResolver;
use App\Modules\Localization\Application\LocalizationTemplateCatalog;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Database\Seeders\LocalizationAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
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

        self::assertSame(
            $resolver->resolve('identity.otp.sms_message', ['code' => '123456'], 'fa'),
            $resolver->resolve('identity.otp.sms_message', ['code' => '123456']),
        );

        $translator->addLines(['localization_probe.runtime_only' => 'Runtime-only :name'], 'en');
        self::assertSame(
            '[localization_probe.runtime_only]',
            $resolver->resolve('localization_probe.runtime_only', ['name' => 'Ada'], 'fa'),
        );
        self::assertSame('[localization_probe.missing]', $resolver->resolve('localization_probe.missing', [], 'fa'));

        try {
            $resolver->resolve('identity.otp.sms_message', ['code' => '123456'], 'de');
            self::fail('Explicit unsupported resolver locale must fail closed.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        $catalog = $this->app->make(LocalizationTemplateCatalog::class);
        $faFiles = array_map('basename', glob(resource_path('lang/fa/*.php')) ?: []);
        $enFiles = array_map('basename', glob(resource_path('lang/en/*.php')) ?: []);
        sort($faFiles);
        sort($enFiles);
        self::assertSame($faFiles, $enFiles);

        foreach ($faFiles as $file) {
            $fa = $this->flatten(require resource_path('lang/fa/'.$file));
            $en = $this->flatten(require resource_path('lang/en/'.$file));
            self::assertSame(array_keys($fa), array_keys($en), 'Translation-key parity failed for '.$file);
            $group = pathinfo($file, PATHINFO_FILENAME);

            foreach ($fa as $key => $value) {
                $qualifiedKey = $group.'.'.$key;
                self::assertSame($value, $catalog->template($qualifiedKey, 'fa'));
                self::assertSame($en[$key], $catalog->template($qualifiedKey, 'en'));
                self::assertSame(
                    $catalog->placeholders($value),
                    $catalog->placeholders($en[$key]),
                    'Placeholder parity failed for '.$file.':'.$key,
                );
            }
        }
    }

    public function test_file_catalog_uses_application_resources_for_english_fallback_and_ignores_runtime_translator_lines(): void
    {
        $root = storage_path('framework/testing/localization-catalog-'.Str::lower((string) Str::ulid()));
        $files = $this->app->make(Filesystem::class);
        $files->ensureDirectoryExists($root.'/fa');
        $files->ensureDirectoryExists($root.'/en');
        $files->put($root.'/fa/probe.php', "<?php\nreturn ['fa_only' => 'فارسی :name', 'section' => ['leaf' => 'برگ :name']];\n");
        $files->put($root.'/en/probe.php', "<?php\nreturn ['fa_only' => 'English :name', 'english_only' => 'English fallback :name', 'section' => ['leaf' => 'Leaf :name']];\n");

        try {
            $catalog = new LocalizationTemplateCatalog($files, $root);
            $resolver = new LocalizationResolver($this->app->make(DatabaseManager::class), $catalog);

            self::assertSame('فارسی Ada', $resolver->resolve('probe.fa_only', ['name' => 'Ada'], 'fa'));
            self::assertSame('English fallback Ada', $resolver->resolve('probe.english_only', ['name' => 'Ada'], 'fa'));
            self::assertSame('[probe.missing]', $resolver->resolve('probe.missing', [], 'fa'));
            self::assertNull($catalog->templateOrNull('probe.section', 'fa'));
            self::assertSame('برگ Ada', $resolver->resolve('probe.section.leaf', ['name' => 'Ada'], 'fa'));
            self::assertSame(['name'], $catalog->placeholders('Visit https://example.com — :name'));
            self::assertSame('Visit https://example.com — Ada', $catalog->render('Visit https://example.com — :name', ['name' => 'Ada']));
        } finally {
            $files->deleteDirectory($root);
        }
    }

    public function test_placeholder_grammar_rejects_malformed_forms_before_persistence_and_renders_supported_case_forms(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(LocalizationOverrideService::class);
        $resolver = $this->app->make(LocalizationResolver::class);
        $key = 'identity.otp.sms_message';

        foreach (['Broken ::code', 'Broken :::code', 'Broken :cOdE'] as $invalidValue) {
            try {
                $service->set(
                    $key,
                    'en',
                    $invalidValue,
                    null,
                    $this->context($ownerId, 'localization-malformed-'.substr(hash('sha256', $invalidValue), 0, 20)),
                );
                self::fail('Malformed placeholder syntax must fail before persistence.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('localization_overrides')->count());
                self::assertSame(0, DB::table('localization_override_versions')->count());
            }
        }

        $receipt = $service->set(
            $key,
            'en',
            'Repeated :code / :Code / :CODE / :code',
            null,
            $this->context($ownerId, 'localization-placeholder-valid-0001'),
        );

        self::assertSame(1, $receipt->version);
        self::assertSame(
            'Repeated abc / Abc / ABC / abc',
            $resolver->resolve($key, ['code' => 'abc'], 'en'),
        );
    }

    public function test_runtime_added_translation_is_not_eligible_for_override_or_preview(): void
    {
        $ownerId = $this->administrator(true);
        $service = $this->app->make(LocalizationOverrideService::class);
        $translator = $this->app->make('translator');
        $translator->addLines(['localization_probe.runtime_only' => 'Runtime-only :name'], 'en');

        foreach (['preview', 'set'] as $operation) {
            try {
                if ($operation === 'preview') {
                    $service->preview($ownerId, 'localization_probe.runtime_only', 'en', 'Override :name');
                } else {
                    $service->set(
                        'localization_probe.runtime_only',
                        'en',
                        'Override :name',
                        null,
                        $this->context($ownerId, 'localization-runtime-only-0001'),
                    );
                }
                self::fail('Runtime-added translations must not establish file-backed override eligibility.');
            } catch (InvalidArgumentException) {
                self::assertSame(0, DB::table('localization_overrides')->count());
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

        try {
            $service->set(
                $key,
                'en',
                'New code: :code',
                null,
                $this->context($unauthorizedId, 'localization-unauth-set-0001'),
            );
            self::fail('Unauthorized administrator must not mutate localization overrides.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('localization_overrides')->count());
        }

        $unauthorizedMalformedMutations = [
            fn () => $service->set(
                'runtime.only',
                'de',
                'Broken ::code',
                null,
                $this->context($unauthorizedId, 'localization-unauth-invalid-set-0001'),
            ),
            fn () => $service->reset(
                'runtime.only',
                'de',
                1,
                $this->context($unauthorizedId, 'localization-unauth-invalid-reset-0001'),
            ),
            fn () => $service->restore(
                'runtime.only',
                'de',
                1,
                1,
                $this->context($unauthorizedId, 'localization-unauth-invalid-restore-0001'),
            ),
        ];
        foreach ($unauthorizedMalformedMutations as $mutation) {
            try {
                $mutation();
                self::fail('Authorization must precede localization mutation payload validation.');
            } catch (AuthorizationException) {
                self::assertSame(0, DB::table('localization_overrides')->count());
            }
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

        $now = now('UTC');
        $invalidRows = [
            ['translation_key' => 'bad key', 'locale' => 'en', 'override_value' => 'Invalid key'],
            ['translation_key' => 'schema.locale', 'locale' => 'de', 'override_value' => 'Invalid locale'],
            ['translation_key' => 'schema.empty', 'locale' => 'en', 'override_value' => ''],
            ['translation_key' => 'schema.too_long', 'locale' => 'en', 'override_value' => str_repeat('x', 4097)],
        ];

        foreach ($invalidRows as $invalidRow) {
            try {
                DB::table('localization_overrides')->insert($invalidRow + [
                    'version' => 1,
                    'updated_by_administrator_id' => $ownerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                self::fail('MariaDB localization schema constraints must fail closed.');
            } catch (QueryException) {
                self::assertSame(0, DB::table('localization_overrides')->where('translation_key', $invalidRow['translation_key'])->count());
            }
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
