<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\Identity\Domain\VerificationStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

final readonly class CustomerAccountSummaryService
{
    public function __construct(private DatabaseManager $database) {}

    /** @requirement USR-001 USR-002 USR-003 SEC-003 */
    public function forSelf(int $userId, int $actorUserId): CustomerAccountSummary
    {
        if ($userId < 1 || $actorUserId !== $userId) {
            throw new AuthorizationException('Customer account summary access denied.');
        }

        $connection = $this->database->connection();

        /** @var object{public_id: string, account_type: string, account_status: string, locale: string, first_seen_at: string, last_seen_at: ?string}|null $user */
        $user = $connection->table('users')
            ->where('id', $userId)
            ->first([
                'public_id',
                'account_type',
                'account_status',
                'locale',
                'first_seen_at',
                'last_seen_at',
            ]);
        if ($user === null || $user->account_status === 'deleted') {
            throw new RuntimeException('Customer account summary is unavailable.');
        }

        /** @var object{tier_code: ?string, tier_locked: int|bool, phone_verification_status: string, identity_verification_status: string}|null $profile */
        $profile = $connection->table('customer_profiles as profiles')
            ->leftJoin('customer_tiers as tiers', 'tiers.id', '=', 'profiles.current_tier_id')
            ->where('profiles.user_id', $userId)
            ->first([
                'tiers.code as tier_code',
                'profiles.tier_locked',
                'profiles.phone_verification_status',
                'profiles.identity_verification_status',
            ]);

        /** @var object{verification_method: ?string, status: string, verified_at: ?string}|null $phone */
        $phone = $connection->table('phone_numbers')
            ->where('user_id', $userId)
            ->whereNotNull('active_lookup_hash')
            ->orderByDesc('id')
            ->first(['verification_method', 'status', 'verified_at']);

        /** @var iterable<int, object{type: string, masked_value: string, state: string, ownership_check_status: string, version: int|string}> $identityRows */
        $identityRows = $connection->table('identity_items')
            ->where('user_id', $userId)
            ->orderBy('type')
            ->get(['type', 'masked_value', 'state', 'ownership_check_status', 'version']);
        $identityItems = [];
        foreach ($identityRows as $row) {
            $identityItems[] = [
                'type' => $row->type,
                'masked_value' => $row->masked_value,
                'state' => $row->state,
                'ownership_check_status' => $row->ownership_check_status,
                'version' => (int) $row->version,
            ];
        }

        /** @var list<string> $tags */
        $tags = $connection->table('customer_tag_assignments as assignments')
            ->join('customer_tags as tags', 'tags.id', '=', 'assignments.tag_id')
            ->where('assignments.user_id', $userId)
            ->whereNull('assignments.removed_at')
            ->where('tags.is_active', true)
            ->orderBy('tags.code')
            ->pluck('tags.code')
            ->all();

        /** @var object{status: string, approved_at: string}|null $agent */
        $agent = $connection->table('agent_profiles')
            ->where('user_id', $userId)
            ->first(['status', 'approved_at']);

        /** @var object{state: string}|null $application */
        $application = $connection->table('agent_applications')
            ->where('customer_id', $userId)
            ->orderByDesc('application_version')
            ->first(['state']);

        /** @var object{status: string, is_owner: int|bool}|null $administrator */
        $administrator = $connection->table('administrators')
            ->where('user_id', $userId)
            ->first(['status', 'is_owner']);

        return new CustomerAccountSummary(
            $user->public_id,
            $user->account_type,
            $user->account_status,
            $user->locale,
            $user->first_seen_at,
            $user->last_seen_at,
            $profile?->tier_code,
            (bool) ($profile?->tier_locked ?? false),
            $profile?->phone_verification_status ?? VerificationStatus::Unverified->value,
            $phone?->verification_method,
            $phone?->verified_at,
            $profile?->identity_verification_status ?? VerificationStatus::Unverified->value,
            $identityItems,
            $tags,
            $agent?->status,
            $agent?->approved_at,
            $application?->state,
            $administrator?->status,
            (bool) ($administrator?->is_owner ?? false),
        );
    }
}
