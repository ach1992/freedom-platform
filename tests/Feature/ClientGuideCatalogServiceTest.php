<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Catalog\Application\CatalogChangeContext;
use App\Modules\Catalog\Application\ClientGuideCatalogService;
use App\Modules\Catalog\Application\ClientGuideResourceDefinition;
use App\Modules\Catalog\Application\ClientGuideResourceView;
use Database\Seeders\CatalogAccessFoundationSeeder;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/** @requirement CAT-007 ACL-002 SEC-002 SEC-003 DAT-002 DAT-003 QUA-001 */
final class ClientGuideCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
        $this->seed(CatalogAccessFoundationSeeder::class);
    }

    public function test_catalogue_is_versioned_audited_sorted_and_policy_filtered_without_fetching_remote_content(): void
    {
        [$administratorId, $administratorUserId] = $this->administrator(true);
        $service = $this->app->make(ClientGuideCatalogService::class);
        $tagId = $this->tag('trusted');
        $normal = $this->customer('normal-customer', 'normal', 'fa');
        $vip = $this->customer('vip-customer', 'vip', 'fa', $tagId);
        $english = $this->customer('english-customer', 'normal', 'en');
        $agent = $this->customer('agent-account', 'normal', 'fa', null, 'agent');

        $all = $service->create(
            $this->definition('android', 20, 'all', 'any', 'https://apps.example.com/android?channel=official'),
            $this->context($administratorId, 'client-guide-create-all-01'),
        );
        $vipOnly = $service->create(
            $this->definition('vip-guide', 10, 'customers', 'fa', 'https://docs.example.org/vip', 'vip'),
            $this->context($administratorId, 'client-guide-create-vip-01'),
        );
        $tagOnly = $service->create(
            $this->definition('trusted-guide', 15, 'customers', 'fa', 'https://docs.example.org/trusted', null, 'trusted'),
            $this->context($administratorId, 'client-guide-create-tag-01'),
        );
        $agentOnly = $service->create(
            $this->definition('agent-guide', 5, 'agents', 'any', 'https://docs.example.org/agents'),
            $this->context($administratorId, 'client-guide-create-agent-01'),
        );
        $englishOnly = $service->create(
            $this->definition('english-guide', 30, 'all', 'en', 'https://docs.example.org/en'),
            $this->context($administratorId, 'client-guide-create-en-01'),
        );
        $disabled = $service->create(
            $this->definition('disabled-guide', 1, 'all', 'any', 'https://docs.example.org/disabled', state: 'disabled'),
            $this->context($administratorId, 'client-guide-create-disabled'),
        );

        self::assertSame(['android'], $this->codes($service->pageForSelf($normal, $normal, 1, 8)->items));
        self::assertSame(['vip-guide', 'trusted-guide', 'android'], $this->codes($service->pageForSelf($vip, $vip, 1, 8)->items));
        self::assertSame(['android', 'english-guide'], $this->codes($service->pageForSelf($english, $english, 1, 8)->items));
        self::assertSame(['agent-guide', 'android'], $this->codes($service->pageForSelf($agent, $agent, 1, 8)->items));

        $allPublicId = (string) DB::table('client_guide_resources')->where('id', $all->targetId)->value('public_id');
        $updated = $service->updateByPublicId(
            $allPublicId,
            1,
            $this->definition('android', 2, 'all', 'any', 'https://apps.example.com/android?channel=stable', state: 'disabled'),
            $this->context($administratorId, 'client-guide-update-all-001'),
        );
        self::assertTrue($updated->changed);
        self::assertSame(2, DB::table('client_guide_resources')->where('id', $all->targetId)->value('version'));
        self::assertSame('disabled', DB::table('client_guide_resources')->where('id', $all->targetId)->value('state'));
        self::assertSame($administratorId, (int) DB::table('client_guide_resources')->where('id', $all->targetId)->value('last_validated_by_administrator_id'));
        self::assertSame([], $this->codes($service->pageForSelf($normal, $normal, 1, 8)->items));

        $adminPage = $service->administratorPageForUser($administratorUserId, 1, 8);
        self::assertSame(6, $adminPage->totalItems);
        self::assertContains('disabled-guide', $this->codes($adminPage->items));
        self::assertContains('android', $this->codes($adminPage->items));
        self::assertSame(7, DB::table('audit_logs')->whereIn('action', ['catalog.client_guide.create', 'catalog.client_guide.update'])->count());
        self::assertSame(0, DB::table('client_guide_resources')->where('id', $disabled->targetId)->where('state', 'active')->count());
        self::assertSame(1, DB::table('client_guide_resources')->where('id', $vipOnly->targetId)->count());
        self::assertSame(1, DB::table('client_guide_resources')->where('id', $tagOnly->targetId)->count());
        self::assertSame(1, DB::table('client_guide_resources')->where('id', $agentOnly->targetId)->count());
        self::assertSame(1, DB::table('client_guide_resources')->where('id', $englishOnly->targetId)->count());
    }

    public function test_management_reauthorizes_and_urls_fail_closed(): void
    {
        [$ownerId] = $this->administrator(true);
        [$unauthorizedId] = $this->administrator(false);
        $service = $this->app->make(ClientGuideCatalogService::class);

        try {
            $service->create(
                $this->definition('denied', 1, 'all', 'any', 'https://docs.example.org/denied'),
                $this->context($unauthorizedId, 'client-guide-denied-create'),
            );
            self::fail('Expected client-guide management authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame(0, DB::table('client_guide_resources')->count());
        }

        foreach ([
            'http://docs.example.org/guide',
            'https://localhost/guide',
            'https://127.0.0.1/guide',
            'https://user@docs.example.org/guide',
            'https://DOCS.example.org/guide',
        ] as $url) {
            try {
                $this->definition('unsafe-'.substr(hash('sha256', $url), 0, 8), 1, 'all', 'any', $url);
                self::fail('Expected unsafe client-guide URL rejection.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString($url, $exception->getMessage());
            }
        }

        $created = $service->create(
            $this->definition('safe', 1, 'all', 'any', 'https://docs.example.org/guide?platform=ios#install'),
            $this->context($ownerId, 'client-guide-safe-create01'),
        );
        $publicId = (string) DB::table('client_guide_resources')->where('id', $created->targetId)->value('public_id');
        $service->updateByPublicId(
            $publicId,
            1,
            $this->definition('safe', 99, 'all', 'any', 'https://docs.example.org/guide?platform=ios#install'),
            $this->context($ownerId, 'client-guide-safe-update01'),
        );
        self::assertSame(99, (int) DB::table('client_guide_resources')->where('id', $created->targetId)->value('sort_order'));
    }

    /** @return array{0:int,1:int} */
    private function administrator(bool $owner): array
    {
        $userId = $this->user('admin-'.Str::lower((string) Str::ulid()), 'customer', 'fa');
        $now = now('UTC');
        $administratorId = (int) DB::table('administrators')->insertGetId([
            'user_id' => $userId,
            'status' => 'active',
            'is_owner' => $owner,
            'permission_version' => 1,
            'last_authenticated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$administratorId, $userId];
    }

    private function customer(string $suffix, string $tier, string $locale, ?int $tagId = null, string $accountType = 'customer'): int
    {
        $userId = $this->user($suffix, $accountType, $locale);
        $now = now('UTC');
        $tierId = (int) DB::table('customer_tiers')->where('code', $tier)->value('id');
        DB::table('customer_profiles')->insert([
            'user_id' => $userId,
            'current_tier_id' => $tierId,
            'tier_locked' => false,
            'tier_lock_reason_code' => null,
            'phone_verification_status' => 'unverified',
            'identity_verification_status' => 'unverified',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($tagId !== null) {
            DB::table('customer_tag_assignments')->insert([
                'user_id' => $userId,
                'tag_id' => $tagId,
                'assigned_by_administrator_id' => null,
                'assigned_at' => $now,
                'removed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $userId;
    }

    private function user(string $suffix, string $accountType, string $locale): int
    {
        $now = now('UTC');

        return (int) DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'account_type' => $accountType,
            'account_status' => 'active',
            'locale' => $locale,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function tag(string $code): int
    {
        $now = now('UTC');

        return (int) DB::table('customer_tags')->insertGetId([
            'code' => $code,
            'name_translation_key' => 'customer_tags.'.$code,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function definition(
        string $code,
        int $sortOrder,
        string $audience,
        string $language,
        string $url,
        ?string $tier = null,
        ?string $tag = null,
        string $state = 'active',
    ): ClientGuideResourceDefinition {
        return new ClientGuideResourceDefinition(
            $code,
            'راهنمای '.$code,
            'Guide '.$code,
            'توضیح '.$code,
            'Description '.$code,
            'android',
            $language,
            $audience,
            $tier,
            $tag,
            $url,
            'آموزش '.$code,
            'Tutorial '.$code,
            '📱',
            null,
            $sortOrder,
            $state,
        );
    }

    private function context(int $administratorId, string $fingerprint): CatalogChangeContext
    {
        return new CatalogChangeContext(
            $fingerprint,
            'correlation-'.substr(hash('sha256', $fingerprint), 0, 24),
            'client_guide_management',
            'Client-guide catalogue management.',
            $administratorId,
        );
    }

    /** @param list<ClientGuideResourceView> $items
     * @return list<string>
     */
    private function codes(array $items): array
    {
        return array_map(static fn ($item): string => $item->code, $items);
    }
}
