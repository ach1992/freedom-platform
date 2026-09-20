<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\AccessControl\Application\AdministratorUserPermissionAuthorizer;
use App\Modules\Customers\Application\CustomerAccountSummary;
use App\Modules\Customers\Application\CustomerAccountSummaryService;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Versioned/audited client application and connection-guide catalogue.
 * Registered URLs are stored and presented only; this service never fetches remote content.
 *
 * @phpstan-type ClientGuideRow object{id:int|string,public_id:string,code:string,title_fa:string,title_en:?string,description_fa:?string,description_en:?string,platform:string,language:string,audience:string,tier_code:?string,customer_tag_code:?string,resource_url:string,tutorial_fa:?string,tutorial_en:?string,normal_emoji:?string,premium_emoji_id:?string,sort_order:int|string,state:string,version:int|string,last_validated_at:string,last_validated_by_administrator_id:int|string}
 */
final readonly class ClientGuideCatalogService
{
    public const PERMISSION = 'catalog.manage';

    private const TARGET_TYPE = 'client_guide_resource';

    private const MAXIMUM_PAGE_SIZE = 8;

    public function __construct(
        private DatabaseManager $database,
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private CustomerAccountSummaryService $customers,
        private AdministratorUserPermissionAuthorizer $administratorUsers,
        private Clock $clock,
    ) {}

    /** @requirement CAT-007 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 */
    public function create(ClientGuideResourceDefinition $definition, CatalogChangeContext $context): CatalogMutationReceipt
    {
        $payloadHash = CatalogPayloadHash::make($definition->payload());
        $action = 'catalog.client_guide.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use ($definition, $context, $payloadHash, $action): CatalogMutationReceipt {
                if ($connection->table('client_guide_resources')->where('code', $definition->code)->lockForUpdate()->exists()) {
                    throw new DomainException('Client-guide code already exists.');
                }
                [$tierCode, $tagId] = $this->resolvePolicyReferences($connection, $definition);
                $now = $this->timestamp();
                $resourceId = (int) $connection->table('client_guide_resources')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'code' => $definition->code,
                    'title_fa' => $definition->titleFa,
                    'title_en' => $definition->titleEn,
                    'description_fa' => $definition->descriptionFa,
                    'description_en' => $definition->descriptionEn,
                    'platform' => $definition->platform,
                    'language' => $definition->language,
                    'audience' => $definition->audience,
                    'tier_code' => $tierCode,
                    'customer_tag_id' => $tagId,
                    'resource_url' => $definition->resourceUrl,
                    'tutorial_fa' => $definition->tutorialFa,
                    'tutorial_en' => $definition->tutorialEn,
                    'normal_emoji' => $definition->normalEmoji,
                    'premium_emoji_id' => $definition->premiumEmojiId,
                    'sort_order' => $definition->sortOrder,
                    'state' => $definition->state,
                    'version' => 1,
                    'last_validated_at' => $now,
                    'last_validated_by_administrator_id' => $context->actorAdministratorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $row = $this->lockedRow($connection, $resourceId);
                $after = $this->safeState($row, $payloadHash);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $resourceId,
                    $context,
                    ['request_payload_hash' => $payloadHash],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-007 ACL-002 SEC-002 DAT-002 DAT-003 QUA-001 */
    public function updateByPublicId(
        string $publicId,
        int $expectedVersion,
        ClientGuideResourceDefinition $definition,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        $this->assertUlid($publicId);
        if ($expectedVersion < 1) {
            throw new DomainException('Client-guide expected version is invalid.');
        }
        $payloadHash = CatalogPayloadHash::make([
            'definition' => $definition->payload(),
            'expected_version' => $expectedVersion,
            'public_id' => $publicId,
        ]);
        $action = 'catalog.client_guide.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $publicId,
                $expectedVersion,
                $definition,
                $context,
                $payloadHash,
                $action,
            ): CatalogMutationReceipt {
                $row = $this->lockedRowByPublicId($connection, $publicId);
                $resourceId = (int) $row->id;
                if ((int) $row->version !== $expectedVersion) {
                    throw new DomainException('Client-guide resource version changed.');
                }
                if (! hash_equals((string) $row->code, $definition->code)) {
                    throw new DomainException('Client-guide code is immutable.');
                }
                [$tierCode, $tagId] = $this->resolvePolicyReferences($connection, $definition);
                $before = $this->safeState($row, $payloadHash);
                $nextVersion = $expectedVersion + 1;
                $now = $this->timestamp();
                $connection->table('client_guide_resources')->where('id', $resourceId)->update([
                    'title_fa' => $definition->titleFa,
                    'title_en' => $definition->titleEn,
                    'description_fa' => $definition->descriptionFa,
                    'description_en' => $definition->descriptionEn,
                    'platform' => $definition->platform,
                    'language' => $definition->language,
                    'audience' => $definition->audience,
                    'tier_code' => $tierCode,
                    'customer_tag_id' => $tagId,
                    'resource_url' => $definition->resourceUrl,
                    'tutorial_fa' => $definition->tutorialFa,
                    'tutorial_en' => $definition->tutorialEn,
                    'normal_emoji' => $definition->normalEmoji,
                    'premium_emoji_id' => $definition->premiumEmojiId,
                    'sort_order' => $definition->sortOrder,
                    'state' => $definition->state,
                    'version' => $nextVersion,
                    'last_validated_at' => $now,
                    'last_validated_by_administrator_id' => $context->actorAdministratorId,
                    'updated_at' => $now,
                ]);
                $after = $this->safeState($this->lockedRow($connection, $resourceId), $payloadHash);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $resourceId,
                    $context,
                    $before,
                    $after,
                    $before !== $after,
                );
            },
        );
    }

    /** @requirement CAT-007 SEC-003 DAT-002 QUA-001 */
    public function pageForSelf(int $actorUserId, int $subjectUserId, int $page, int $pageSize): ClientGuideCatalogPage
    {
        if ($actorUserId < 1 || $subjectUserId < 1 || $actorUserId !== $subjectUserId) {
            throw new AuthorizationException('Client-guide catalogue is self-only.');
        }
        $this->assertPage($page, $pageSize);
        $subject = $this->customers->forSelf($subjectUserId, $actorUserId);
        if (! in_array($subject->accountType, ['customer', 'agent'], true)) {
            throw new AuthorizationException('Client-guide catalogue account type is unsupported.');
        }

        $query = $this->customerQuery($subject);
        $totalItems = (int) (clone $query)->count('resource.id');
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);
        /** @var list<ClientGuideRow> $rows */
        $rows = (clone $query)
            ->orderBy('resource.sort_order')
            ->orderBy('resource.id')
            ->forPage($effectivePage, $pageSize)
            ->get($this->projectionColumns())
            ->all();

        return new ClientGuideCatalogPage(
            array_values(array_map(fn (object $row): ClientGuideResourceView => $this->view($row), $rows)),
            $effectivePage,
            $totalPages,
            $totalItems,
        );
    }

    public function administratorPageForUser(int $userId, int $page, int $pageSize): ClientGuideCatalogPage
    {
        $this->administratorUsers->authorizeUser($userId, self::PERMISSION);
        $this->assertPage($page, $pageSize);
        $query = $this->baseQuery();
        $totalItems = (int) (clone $query)->count('resource.id');
        $totalPages = max(1, (int) ceil($totalItems / $pageSize));
        $effectivePage = min($page, $totalPages);
        /** @var list<ClientGuideRow> $rows */
        $rows = (clone $query)
            ->orderBy('resource.sort_order')
            ->orderBy('resource.id')
            ->forPage($effectivePage, $pageSize)
            ->get($this->projectionColumns())
            ->all();

        return new ClientGuideCatalogPage(
            array_values(array_map(fn (object $row): ClientGuideResourceView => $this->view($row), $rows)),
            $effectivePage,
            $totalPages,
            $totalItems,
        );
    }

    public function administratorResourceForUser(int $userId, string $publicId): ClientGuideResourceView
    {
        $this->administratorUsers->authorizeUser($userId, self::PERMISSION);
        $this->assertUlid($publicId);
        /** @var ClientGuideRow|null $row */
        $row = $this->baseQuery()->where('resource.public_id', $publicId)->first($this->projectionColumns());
        if ($row === null) {
            throw new DomainException('Client-guide resource does not exist.');
        }

        return $this->view($row);
    }

    public function administratorIdForUser(int $userId): int
    {
        return $this->administratorUsers->authorizeUser($userId, self::PERMISSION);
    }

    public function availableForAdministratorUser(int $userId): bool
    {
        return $this->administratorUsers->allowsUser($userId, self::PERMISSION);
    }

    private function customerQuery(CustomerAccountSummary $subject): Builder
    {
        $locale = $subject->locale === 'en' ? 'en' : 'fa';
        $query = $this->baseQuery()
            ->where('resource.state', 'active')
            ->where(function (Builder $language) use ($locale): void {
                $language->where('resource.language', 'any')->orWhere('resource.language', $locale);
            });

        if ($subject->accountType === 'agent') {
            return $query->whereIn('resource.audience', ['all', 'agents']);
        }

        return $query->where(function (Builder $audience) use ($subject): void {
            $audience->where('resource.audience', 'all')
                ->orWhere(function (Builder $customer) use ($subject): void {
                    $customer->where('resource.audience', 'customers')
                        ->where(function (Builder $tier) use ($subject): void {
                            $tier->whereNull('resource.tier_code');
                            if ($subject->tierCode !== null) {
                                $tier->orWhere('resource.tier_code', $subject->tierCode);
                            }
                        })
                        ->where(function (Builder $tag) use ($subject): void {
                            $tag->whereNull('resource.customer_tag_id');
                            if ($subject->tags !== []) {
                                $tag->orWhereIn('tag.code', $subject->tags);
                            }
                        });
                });
        });
    }

    private function baseQuery(): Builder
    {
        return $this->database->connection()->table('client_guide_resources as resource')
            ->leftJoin('customer_tags as tag', 'tag.id', '=', 'resource.customer_tag_id');
    }

    /** @return list<string> */
    private function projectionColumns(): array
    {
        return [
            'resource.id', 'resource.public_id', 'resource.code', 'resource.title_fa', 'resource.title_en',
            'resource.description_fa', 'resource.description_en', 'resource.platform', 'resource.language',
            'resource.audience', 'resource.tier_code', 'tag.code as customer_tag_code', 'resource.resource_url',
            'resource.tutorial_fa', 'resource.tutorial_en', 'resource.normal_emoji', 'resource.premium_emoji_id',
            'resource.sort_order', 'resource.state', 'resource.version', 'resource.last_validated_at',
            'resource.last_validated_by_administrator_id',
        ];
    }

    /** @param ClientGuideRow $row */
    private function view(object $row): ClientGuideResourceView
    {
        return new ClientGuideResourceView(
            (int) $row->id,
            (string) $row->public_id,
            (string) $row->code,
            (string) $row->title_fa,
            $row->title_en === null ? null : (string) $row->title_en,
            $row->description_fa === null ? null : (string) $row->description_fa,
            $row->description_en === null ? null : (string) $row->description_en,
            (string) $row->platform,
            (string) $row->language,
            (string) $row->audience,
            $row->tier_code === null ? null : (string) $row->tier_code,
            $row->customer_tag_code === null ? null : (string) $row->customer_tag_code,
            (string) $row->resource_url,
            $row->tutorial_fa === null ? null : (string) $row->tutorial_fa,
            $row->tutorial_en === null ? null : (string) $row->tutorial_en,
            $row->normal_emoji === null ? null : (string) $row->normal_emoji,
            $row->premium_emoji_id === null ? null : (string) $row->premium_emoji_id,
            (int) $row->sort_order,
            (string) $row->state,
            (int) $row->version,
            (string) $row->last_validated_at,
            (int) $row->last_validated_by_administrator_id,
        );
    }

    /** @return array{0:?string,1:?int} */
    private function resolvePolicyReferences(Connection $connection, ClientGuideResourceDefinition $definition): array
    {
        if ($definition->tierCode !== null
            && ! $connection->table('customer_tiers')->where('code', $definition->tierCode)->where('is_active', true)->exists()) {
            throw new DomainException('Client-guide tier does not exist or is inactive.');
        }

        $tagId = null;
        if ($definition->customerTagCode !== null) {
            $stored = $connection->table('customer_tags')
                ->where('code', $definition->customerTagCode)
                ->where('is_active', true)
                ->value('id');
            if (! is_int($stored) && ! is_string($stored)) {
                throw new DomainException('Client-guide customer tag does not exist or is inactive.');
            }
            $tagId = (int) $stored;
        }

        return [$definition->tierCode, $tagId];
    }

    /** @return ClientGuideRow */
    private function lockedRowByPublicId(Connection $connection, string $publicId): object
    {
        /** @var ClientGuideRow|null $row */
        $row = $connection->table('client_guide_resources as resource')
            ->leftJoin('customer_tags as tag', 'tag.id', '=', 'resource.customer_tag_id')
            ->where('resource.public_id', $publicId)
            ->lockForUpdate()
            ->first($this->projectionColumns());
        if ($row === null) {
            throw new DomainException('Client-guide resource does not exist.');
        }

        return $row;
    }

    /** @return ClientGuideRow */
    private function lockedRow(Connection $connection, int $resourceId): object
    {
        /** @var ClientGuideRow|null $row */
        $row = $connection->table('client_guide_resources as resource')
            ->leftJoin('customer_tags as tag', 'tag.id', '=', 'resource.customer_tag_id')
            ->where('resource.id', $resourceId)
            ->lockForUpdate()
            ->first($this->projectionColumns());
        if ($row === null) {
            throw new DomainException('Client-guide resource does not exist.');
        }

        return $row;
    }

    /**
     * @param  ClientGuideRow  $row
     * @return array<string,bool|int|string|null>
     */
    private function safeState(object $row, string $payloadHash): array
    {
        return [
            'audience' => (string) $row->audience,
            'code' => (string) $row->code,
            'customer_tag_code' => $row->customer_tag_code === null ? null : (string) $row->customer_tag_code,
            'description_en' => $row->description_en === null ? null : (string) $row->description_en,
            'description_fa' => $row->description_fa === null ? null : (string) $row->description_fa,
            'language' => (string) $row->language,
            'last_validated_at' => (string) $row->last_validated_at,
            'last_validated_by_administrator_id' => (int) $row->last_validated_by_administrator_id,
            'normal_emoji' => $row->normal_emoji === null ? null : (string) $row->normal_emoji,
            'platform' => (string) $row->platform,
            'premium_emoji_id' => $row->premium_emoji_id === null ? null : (string) $row->premium_emoji_id,
            'public_id' => (string) $row->public_id,
            'request_payload_hash' => $payloadHash,
            'resource_url' => (string) $row->resource_url,
            'sort_order' => (int) $row->sort_order,
            'state' => (string) $row->state,
            'tier_code' => $row->tier_code === null ? null : (string) $row->tier_code,
            'title_en' => $row->title_en === null ? null : (string) $row->title_en,
            'title_fa' => (string) $row->title_fa,
            'tutorial_en' => $row->tutorial_en === null ? null : (string) $row->tutorial_en,
            'tutorial_fa' => $row->tutorial_fa === null ? null : (string) $row->tutorial_fa,
            'version' => (int) $row->version,
        ];
    }

    private function assertPage(int $page, int $pageSize): void
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > self::MAXIMUM_PAGE_SIZE) {
            throw new DomainException('Client-guide page request is invalid.');
        }
    }

    private function assertUlid(string $publicId): void
    {
        if (preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $publicId) !== 1) {
            throw new DomainException('Client-guide public ID is invalid.');
        }
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
