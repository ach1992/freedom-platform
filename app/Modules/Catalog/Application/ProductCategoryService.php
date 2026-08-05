<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\CatalogCode;
use App\Modules\Catalog\Domain\CatalogState;
use App\Shared\Application\Clock;
use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;

final readonly class ProductCategoryService
{
    private const TARGET_TYPE = 'catalog_category';

    public function __construct(
        private CatalogMutationExecutor $executor,
        private CatalogMutationAudit $audit,
        private Clock $clock,
    ) {}

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function create(
        string $code,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        ?int $parentId,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        $normalizedCode = CatalogCode::fromInput($code)->value;
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedParentId = CatalogInput::positiveId($parentId, 'Category parent ID', true);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'code' => $normalizedCode,
            'content' => $content,
            'parent_id' => $normalizedParentId,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.category.create';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            null,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $normalizedCode,
                $content,
                $normalizedParentId,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $this->assertParentChain($connection, $normalizedParentId, null, false);

                if ($connection->table('product_categories')->where('code', $normalizedCode)->lockForUpdate()->exists()) {
                    throw new RuntimeException('Category code already exists.');
                }

                $now = $this->timestamp();
                $categoryId = (int) $connection->table('product_categories')->insertGetId([
                    'parent_id' => $normalizedParentId,
                    'code' => $normalizedCode,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'state' => CatalogState::Draft->value,
                    'sort_order' => $normalizedSortOrder,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $after = $this->safeState(
                    $categoryId,
                    $normalizedCode,
                    $normalizedParentId,
                    CatalogState::Draft,
                    $normalizedSortOrder,
                    1,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $categoryId, 1, $action, null, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $categoryId,
                    $context,
                    ['request_payload_hash' => $payloadHash],
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function update(
        int $categoryId,
        int $expectedVersion,
        string $nameFa,
        ?string $nameEn,
        ?string $descriptionFa,
        ?string $descriptionEn,
        ?int $parentId,
        int $sortOrder,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $content = CatalogInput::localized($nameFa, $nameEn, $descriptionFa, $descriptionEn);
        $normalizedParentId = CatalogInput::positiveId($parentId, 'Category parent ID', true);
        $normalizedSortOrder = CatalogInput::sortOrder($sortOrder);
        $payloadHash = CatalogPayloadHash::make([
            'category_id' => $categoryId,
            'expected_version' => $normalizedExpectedVersion,
            'content' => $content,
            'parent_id' => $normalizedParentId,
            'sort_order' => $normalizedSortOrder,
        ]);
        $action = 'catalog.category.update';

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $categoryId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $categoryId,
                $normalizedExpectedVersion,
                $content,
                $normalizedParentId,
                $normalizedSortOrder,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $category = $this->lockedCategory($connection, $categoryId);
                $this->assertVersion((int) $category->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $category->state);
                if ($state === CatalogState::Archived) {
                    throw new DomainException('Archived categories are immutable.');
                }

                $this->assertParentChain(
                    $connection,
                    $normalizedParentId,
                    $categoryId,
                    $state === CatalogState::Active,
                );

                $beforeContentHash = $this->contentHash($category);
                $before = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $category->parent_id === null ? null : (int) $category->parent_id,
                    $state,
                    (int) $category->sort_order,
                    (int) $category->version,
                    $beforeContentHash,
                    $payloadHash,
                );
                $changed = $beforeContentHash !== $content['content_hash']
                    || ($category->parent_id === null ? null : (int) $category->parent_id) !== $normalizedParentId
                    || (int) $category->sort_order !== $normalizedSortOrder;

                if (! $changed) {
                    return $this->audit->record(
                        $connection,
                        $action,
                        self::TARGET_TYPE,
                        $categoryId,
                        $context,
                        $before,
                        $before,
                        false,
                    );
                }

                $nextVersion = (int) $category->version + 1;
                $connection->table('product_categories')->where('id', $categoryId)->update([
                    'parent_id' => $normalizedParentId,
                    'name_fa' => $content['name_fa'],
                    'name_en' => $content['name_en'],
                    'description_fa' => $content['description_fa'],
                    'description_en' => $content['description_en'],
                    'sort_order' => $normalizedSortOrder,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);

                $after = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $normalizedParentId,
                    $state,
                    $normalizedSortOrder,
                    $nextVersion,
                    $content['content_hash'],
                    $payloadHash,
                );
                $this->history($connection, $categoryId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $categoryId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function activate(int $categoryId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($categoryId, $expectedVersion, CatalogState::Active, 'catalog.category.activate', $context);
    }

    /** @requirement CAT-001 ACL-002 SEC-002 DAT-003 QUA-001 */
    public function archive(int $categoryId, int $expectedVersion, CatalogChangeContext $context): CatalogMutationReceipt
    {
        return $this->transition($categoryId, $expectedVersion, CatalogState::Archived, 'catalog.category.archive', $context);
    }

    private function transition(
        int $categoryId,
        int $expectedVersion,
        CatalogState $target,
        string $action,
        CatalogChangeContext $context,
    ): CatalogMutationReceipt {
        CatalogInput::positiveId($categoryId, 'Category ID');
        $normalizedExpectedVersion = CatalogInput::expectedVersion($expectedVersion);
        $payloadHash = CatalogPayloadHash::make([
            'category_id' => $categoryId,
            'expected_version' => $normalizedExpectedVersion,
            'target_state' => $target->value,
        ]);

        return $this->executor->execute(
            $action,
            self::TARGET_TYPE,
            $categoryId,
            $payloadHash,
            $context,
            function (Connection $connection) use (
                $action,
                $categoryId,
                $normalizedExpectedVersion,
                $target,
                $payloadHash,
                $context,
            ): CatalogMutationReceipt {
                $category = $this->lockedCategory($connection, $categoryId);
                $this->assertVersion((int) $category->version, $normalizedExpectedVersion);
                $state = $this->storedState((string) $category->state);
                $state->assertCanTransitionTo($target);
                $parentId = $category->parent_id === null ? null : (int) $category->parent_id;

                if ($target === CatalogState::Active) {
                    $this->assertParentChain($connection, $parentId, $categoryId, true);
                } else {
                    $this->assertArchiveDependencies($connection, $categoryId);
                }

                $contentHash = $this->contentHash($category);
                $before = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $parentId,
                    $state,
                    (int) $category->sort_order,
                    (int) $category->version,
                    $contentHash,
                    $payloadHash,
                );
                $nextVersion = (int) $category->version + 1;
                $connection->table('product_categories')->where('id', $categoryId)->update([
                    'state' => $target->value,
                    'version' => $nextVersion,
                    'updated_at' => $this->timestamp(),
                ]);
                $after = $this->safeState(
                    $categoryId,
                    (string) $category->code,
                    $parentId,
                    $target,
                    (int) $category->sort_order,
                    $nextVersion,
                    $contentHash,
                    $payloadHash,
                );
                $this->history($connection, $categoryId, $nextVersion, $action, $before, $after, $context);

                return $this->audit->record(
                    $connection,
                    $action,
                    self::TARGET_TYPE,
                    $categoryId,
                    $context,
                    $before,
                    $after,
                    true,
                );
            },
        );
    }

    /** @return object{id: int|string, parent_id: int|string|null, code: string, name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string, state: string, sort_order: int|string, version: int|string} */
    private function lockedCategory(Connection $connection, int $categoryId): object
    {
        $category = $connection->table('product_categories')
            ->where('id', $categoryId)
            ->lockForUpdate()
            ->first([
                'id', 'parent_id', 'code', 'name_fa', 'name_en', 'description_fa', 'description_en',
                'state', 'sort_order', 'version',
            ]);

        if ($category === null) {
            throw new RuntimeException('Category does not exist.');
        }

        return $category;
    }

    private function assertParentChain(
        Connection $connection,
        ?int $parentId,
        ?int $movingCategoryId,
        bool $requireActive,
    ): void {
        $seen = [];
        $currentId = $parentId;

        while ($currentId !== null) {
            if ($movingCategoryId !== null && $currentId === $movingCategoryId) {
                throw new DomainException('Category hierarchy cycle is not allowed.');
            }
            if (isset($seen[$currentId])) {
                throw new RuntimeException('Stored category hierarchy is invalid.');
            }
            $seen[$currentId] = true;

            /** @var object{id: int|string, parent_id: int|string|null, state: string}|null $parent */
            $parent = $connection->table('product_categories')
                ->where('id', $currentId)
                ->lockForUpdate()
                ->first(['id', 'parent_id', 'state']);
            if ($parent === null) {
                throw new RuntimeException('Category parent does not exist.');
            }

            $parentState = $this->storedState($parent->state);
            if ($parentState === CatalogState::Archived || ($requireActive && $parentState !== CatalogState::Active)) {
                throw new DomainException('Category parent state does not allow this operation.');
            }

            $currentId = $parent->parent_id === null ? null : (int) $parent->parent_id;
        }
    }

    private function assertArchiveDependencies(Connection $connection, int $categoryId): void
    {
        $child = $connection->table('product_categories')
            ->where('parent_id', $categoryId)
            ->where('state', '<>', CatalogState::Archived->value)
            ->lockForUpdate()
            ->first(['id']);
        if ($child !== null) {
            throw new DomainException('Category with non-archived child categories cannot be archived.');
        }

        $product = $connection->table('products')
            ->where('category_id', $categoryId)
            ->where('state', '<>', CatalogState::Archived->value)
            ->lockForUpdate()
            ->first(['id']);
        if ($product !== null) {
            throw new DomainException('Category with non-archived products cannot be archived.');
        }
    }

    private function assertVersion(int $currentVersion, int $expectedVersion): void
    {
        if ($currentVersion !== $expectedVersion) {
            throw new RuntimeException('Catalog version conflict.');
        }
    }

    private function storedState(string $state): CatalogState
    {
        return CatalogState::tryFrom($state) ?? throw new RuntimeException('Stored category state is invalid.');
    }

    /** @param object{name_fa: string, name_en: ?string, description_fa: ?string, description_en: ?string} $category */
    private function contentHash(object $category): string
    {
        return CatalogPayloadHash::make([
            'name_fa' => $category->name_fa,
            'name_en' => $category->name_en,
            'description_fa' => $category->description_fa,
            'description_en' => $category->description_en,
        ]);
    }

    /** @return array<string, bool|int|string|null> */
    private function safeState(
        int $categoryId,
        string $code,
        ?int $parentId,
        CatalogState $state,
        int $sortOrder,
        int $version,
        string $contentHash,
        string $payloadHash,
    ): array {
        return [
            'request_payload_hash' => $payloadHash,
            'category_id' => $categoryId,
            'code' => $code,
            'parent_id' => $parentId,
            'state' => $state->value,
            'sort_order' => $sortOrder,
            'version' => $version,
            'content_hash' => $contentHash,
        ];
    }

    /**
     * @param array<string, bool|int|string|null>|null $before
     * @param array<string, bool|int|string|null> $after
     */
    private function history(
        Connection $connection,
        int $categoryId,
        int $version,
        string $action,
        ?array $before,
        array $after,
        CatalogChangeContext $context,
    ): void {
        $connection->table('product_category_histories')->insert([
            'category_id' => $categoryId,
            'version' => $version,
            'action' => $action,
            'from_state' => $before['state'] ?? null,
            'to_state' => $after['state'],
            'from_parent_id' => $before['parent_id'] ?? null,
            'to_parent_id' => $after['parent_id'],
            'from_sort_order' => $before['sort_order'] ?? null,
            'to_sort_order' => $after['sort_order'],
            'from_content_hash' => $before['content_hash'] ?? null,
            'to_content_hash' => $after['content_hash'],
            'before_safe_data' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_safe_data' => json_encode($after, JSON_THROW_ON_ERROR),
            'actor_administrator_id' => $context->actorAdministratorId,
            'reason_code' => $context->reasonCode,
            'reason' => $context->requireReason(),
            'correlation_id' => $context->correlationId,
            'created_at' => $this->timestamp(),
        ]);
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
