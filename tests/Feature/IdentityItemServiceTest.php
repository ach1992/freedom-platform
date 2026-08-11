<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Application\IdentityChangeContext;
use App\Modules\Identity\Application\IdentityItemService;
use App\Modules\Identity\Domain\IdentityItemType;
use Database\Seeders\IdentityAccessFoundationSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** @requirement USR-001 ACL-002 SEC-003 DAT-003 QUA-001 */
final class IdentityItemServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IdentityAccessFoundationSeeder::class);
    }

    public function test_sensitive_values_are_encrypted_masked_replay_safe_and_aggregate_after_review(): void
    {
        $userId = $this->user();
        $ownerId = $this->administrator(true);
        $service = $this->app->make(IdentityItemService::class);
        $nationalId = $this->nationalId('123456789');
        $submitNationalContext = $this->userContext($userId, 'identity-submit-national-0001');

        $submitted = $service->submit(
            $userId,
            IdentityItemType::NationalId,
            $nationalId,
            false,
            $submitNationalContext,
        );
        $replay = $service->submit(
            $userId,
            IdentityItemType::NationalId,
            $nationalId,
            false,
            $submitNationalContext,
        );
        $row = DB::table('identity_items')
            ->where('user_id', $userId)
            ->where('type', IdentityItemType::NationalId->value)
            ->first();

        self::assertNotNull($row);
        self::assertTrue($submitted->changed);
        self::assertTrue($replay->replayed);
        self::assertNotSame($nationalId, $row->encrypted_value);
        self::assertStringNotContainsString($nationalId, (string) $row->encrypted_value);
        self::assertSame('******'.substr($nationalId, -4), $row->masked_value);
        self::assertSame(64, strlen((string) $row->lookup_hash));
        self::assertSame('pending', $row->state);
        self::assertSame('pending', DB::table('customer_profiles')->where('user_id', $userId)->value('identity_verification_status'));

        $service->submit(
            $userId,
            IdentityItemType::FullName,
            'علی رضایی',
            false,
            $this->userContext($userId, 'identity-submit-fullname-0001'),
        );
        $service->verify(
            $userId,
            IdentityItemType::NationalId,
            $this->adminContext($ownerId, 'identity-verify-national-0001'),
        );
        $service->verify(
            $userId,
            IdentityItemType::FullName,
            $this->adminContext($ownerId, 'identity-verify-fullname-0001'),
        );

        self::assertSame('verified', DB::table('customer_profiles')->where('user_id', $userId)->value('identity_verification_status'));
        self::assertSame(4, DB::table('identity_item_histories')->count());
        self::assertSame(4, DB::table('audit_logs')->where('target_type', 'identity_item')->count());
        self::assertStringNotContainsString($nationalId, (string) DB::table('audit_logs')->where('action', 'identity.item.submit')->value('after_safe_data'));
        self::assertSame([
            [
                'type' => 'full_name',
                'masked_value' => 'ع***ی',
                'state' => 'verified',
                'ownership_check_status' => 'not_required',
                'version' => 2,
            ],
            [
                'type' => 'national_id',
                'masked_value' => '******'.substr($nationalId, -4),
                'state' => 'verified',
                'ownership_check_status' => 'not_required',
                'version' => 2,
            ],
        ], $service->summaryForUser($userId));
    }

    public function test_global_sensitive_identity_uniqueness_does_not_apply_to_full_name(): void
    {
        $firstUserId = $this->user();
        $secondUserId = $this->user();
        $service = $this->app->make(IdentityItemService::class);
        $nationalId = $this->nationalId('223456789');

        $service->submit(
            $firstUserId,
            IdentityItemType::NationalId,
            $nationalId,
            false,
            $this->userContext($firstUserId, 'identity-submit-unique-0001'),
        );

        try {
            $service->submit(
                $secondUserId,
                IdentityItemType::NationalId,
                $nationalId,
                false,
                $this->userContext($secondUserId, 'identity-submit-unique-0002'),
            );
            self::fail('Expected global sensitive identity uniqueness failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Identity value is already assigned.', $exception->getMessage());
        }

        $service->submit(
            $firstUserId,
            IdentityItemType::FullName,
            'محمد محمدی',
            false,
            $this->userContext($firstUserId, 'identity-submit-name-0001'),
        );
        $service->submit(
            $secondUserId,
            IdentityItemType::FullName,
            'محمد محمدی',
            false,
            $this->userContext($secondUserId, 'identity-submit-name-0002'),
        );

        self::assertSame(2, DB::table('identity_items')->where('type', 'full_name')->count());
        self::assertSame(0, DB::table('identity_items')->where('type', 'full_name')->whereNotNull('active_lookup_hash')->count());
    }

    public function test_required_ownership_match_blocks_verification_and_mismatch_requires_resubmission(): void
    {
        $userId = $this->user();
        $ownerId = $this->administrator(true);
        $service = $this->app->make(IdentityItemService::class);
        $card = $this->bankCard('603799751547777');

        $service->submit(
            $userId,
            IdentityItemType::BankCard,
            $card,
            true,
            $this->userContext($userId, 'identity-submit-card-0001'),
        );

        try {
            $service->verify(
                $userId,
                IdentityItemType::BankCard,
                $this->adminContext($ownerId, 'identity-verify-card-0001'),
            );
            self::fail('Expected ownership-check gate.');
        } catch (RuntimeException $exception) {
            self::assertSame('Identity ownership check must match before verification.', $exception->getMessage());
        }

        $service->recordOwnershipCheck(
            $userId,
            IdentityItemType::BankCard,
            false,
            $this->adminContext($ownerId, 'identity-ownership-card-0001'),
        );
        self::assertSame('rejected', DB::table('identity_items')->where('user_id', $userId)->where('type', 'bank_card')->value('state'));

        $service->submit(
            $userId,
            IdentityItemType::BankCard,
            $card,
            true,
            $this->userContext($userId, 'identity-submit-card-0002'),
        );
        $service->recordOwnershipCheck(
            $userId,
            IdentityItemType::BankCard,
            true,
            $this->adminContext($ownerId, 'identity-ownership-card-0002'),
        );
        $verified = $service->verify(
            $userId,
            IdentityItemType::BankCard,
            $this->adminContext($ownerId, 'identity-verify-card-0002'),
        );

        self::assertTrue($verified->changed);
        self::assertDatabaseHas('identity_items', [
            'user_id' => $userId,
            'type' => 'bank_card',
            'state' => 'verified',
            'ownership_check_status' => 'matched',
            'version' => 5,
        ]);
    }

    public function test_unauthorized_administrator_cannot_review_identity_items(): void
    {
        $userId = $this->user();
        $administratorId = $this->administrator();
        $service = $this->app->make(IdentityItemService::class);
        $service->submit(
            $userId,
            IdentityItemType::FullName,
            'سارا احمدی',
            false,
            $this->userContext($userId, 'identity-submit-denied-0001'),
        );

        try {
            $service->verify(
                $userId,
                IdentityItemType::FullName,
                $this->adminContext($administratorId, 'identity-verify-denied-0001'),
            );
            self::fail('Expected identity review authorization failure.');
        } catch (AuthorizationException) {
            self::assertSame('pending', DB::table('identity_items')->where('user_id', $userId)->value('state'));
            self::assertSame(0, DB::table('audit_logs')->where('action', 'identity.item.verify')->count());
        }
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

    private function userContext(int $userId, string $fingerprint): IdentityChangeContext
    {
        return new IdentityChangeContext(
            $fingerprint,
            str_replace('submit', 'correlation', $fingerprint),
            'user_submission',
            actorUserId: $userId,
        );
    }

    private function adminContext(int $administratorId, string $fingerprint): IdentityChangeContext
    {
        return new IdentityChangeContext(
            $fingerprint,
            str_replace(['verify', 'ownership'], 'correlation', $fingerprint),
            'identity_review',
            'Identity item reviewed under test policy.',
            actorAdministratorId: $administratorId,
        );
    }

    private function nationalId(string $firstNineDigits): string
    {
        self::assertMatchesRegularExpression('/\A[0-9]{9}\z/', $firstNineDigits);
        $sum = 0;
        for ($index = 0; $index < 9; $index++) {
            $sum += (int) $firstNineDigits[$index] * (10 - $index);
        }
        $remainder = $sum % 11;

        return $firstNineDigits.($remainder < 2 ? $remainder : 11 - $remainder);
    }

    private function bankCard(string $firstFifteenDigits): string
    {
        self::assertMatchesRegularExpression('/\A[0-9]{15}\z/', $firstFifteenDigits);
        for ($check = 0; $check <= 9; $check++) {
            $candidate = $firstFifteenDigits.$check;
            $sum = 0;
            for ($index = 0; $index < 16; $index++) {
                $product = (int) $candidate[$index] * ($index % 2 === 0 ? 2 : 1);
                $sum += $product > 9 ? $product - 9 : $product;
            }
            if ($sum % 10 === 0) {
                return $candidate;
            }
        }

        self::fail('Could not generate a valid bank card number.');
    }
}
