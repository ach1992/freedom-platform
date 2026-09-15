<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Catalog;

use App\Modules\Catalog\Application\CatalogPayloadHash;
use App\Modules\Catalog\Domain\CatalogCode;
use App\Modules\Catalog\Domain\CatalogState;
use App\Modules\Catalog\Domain\CatalogText;
use App\Modules\Catalog\Domain\ProductSku;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** @requirement CAT-001 CAT-002 QUA-001 */
final class CatalogDomainTest extends TestCase
{
    public function test_catalog_state_machine_is_explicit_and_fail_closed(): void
    {
        CatalogState::Draft->assertCanTransitionTo(CatalogState::Active);
        CatalogState::Draft->assertCanTransitionTo(CatalogState::Archived);
        CatalogState::Active->assertCanTransitionTo(CatalogState::Archived);

        $this->expectException(DomainException::class);
        CatalogState::Active->assertCanTransitionTo(CatalogState::Draft);
    }

    public function test_archived_state_is_terminal(): void
    {
        $this->expectException(DomainException::class);
        CatalogState::Archived->assertCanTransitionTo(CatalogState::Active);
    }

    public function test_codes_skus_and_localized_text_are_normalized_and_validated(): void
    {
        self::assertSame('vpn.shared', CatalogCode::fromInput(' VPN.Shared ')->value);
        self::assertSame('VPN-IR:001', ProductSku::fromInput(' VPN-IR:001 ')->value);
        self::assertSame('نام محصول', CatalogText::requiredName('  نام محصول  '));
        self::assertNull(CatalogText::optionalDescription('   '));

        $this->expectException(InvalidArgumentException::class);
        CatalogCode::fromInput('invalid code');
    }

    public function test_payload_hash_is_stable_for_associative_key_order_and_sensitive_to_values(): void
    {
        $first = CatalogPayloadHash::make(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]);
        $second = CatalogPayloadHash::make(['a' => ['x' => 1, 'y' => 2], 'b' => 2]);
        $different = CatalogPayloadHash::make(['a' => ['x' => 1, 'y' => 3], 'b' => 2]);

        self::assertSame($first, $second);
        self::assertNotSame($first, $different);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first);
    }
}
