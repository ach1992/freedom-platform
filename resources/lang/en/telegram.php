<?php

declare(strict_types=1);

return [
    'navigation' => [
        'home' => "Welcome to Freedom Platform.\n\nMain menu\nChoose an option below.\n/cancel — Close the current session",
        'buttons' => [
            'my_account' => 'My Account',
            'back' => 'Back',
        ],
        'account' => [
            'view' => "My Account\n\nAccount ID: :public_id\nType: :account_type\nStatus: :account_status\nTier: :tier\nPhone verification: :phone_verification\nIdentity verification: :identity_verification\nIdentity items:\n:identity_items\nJoined: :joined_at\nLast seen: :last_seen_at\n\nWallet\nCash available: :cash_available IRR\nCash on hold: :cash_holds IRR\nPromotional available: :promotional_available IRR\n\nReferral\nYour referral code: :referral_token\nInviter set: :has_inviter\nReferral locked: :referral_locked",
            'identity_item' => '• :type — :masked (:state)',
            'identity_none' => '• None',
            'not_available' => 'Not available',
            'yes' => 'Yes',
            'no' => 'No',
            'values' => [
                'account_type' => [
                    'customer' => 'Customer',
                    'agent' => 'Agent',
                ],
                'account_status' => [
                    'active' => 'Active',
                    'limited' => 'Limited',
                    'suspended' => 'Suspended',
                    'blocked' => 'Blocked',
                ],
                'tier' => [
                    'new' => 'New',
                    'normal' => 'Normal',
                    'loyal' => 'Loyal',
                    'vip' => 'VIP',
                ],
                'verification' => [
                    'unverified' => 'Unverified',
                    'pending' => 'Pending',
                    'verified' => 'Verified',
                    'rejected' => 'Rejected',
                ],
                'identity_type' => [
                    'national_id' => 'National ID',
                    'bank_card' => 'Bank card',
                    'full_name' => 'Full name',
                ],
            ],
        ],
    ],
];
