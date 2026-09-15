<?php

declare(strict_types=1);

return [
    'parse_mode' => 'plain_text',
    'message_max_length' => 4096,
    'button_max_length' => 64,
    'families' => [
        'onboarding' => [
            'welcome', 'start', 'membership.required', 'blocked', 'maintenance', 'phone.request',
            'verification.success', 'verification.failure',
        ],
        'identity' => [
            'telegram_contact.request', 'telegram_contact.invalid', 'telegram_contact.verified',
            'otp.request', 'otp.sent', 'otp.verified', 'otp.failed',
            'bank_card.pending', 'bank_card.verified', 'bank_card.rejected',
            'national_id.pending', 'national_id.verified', 'national_id.rejected',
            'status.pending', 'status.verified', 'status.rejected', 'reverification.required',
        ],
        'menu' => ['customer', 'agent', 'administrator', 'back', 'cancel', 'confirm'],
        'catalog' => [
            'categories', 'products', 'servers', 'custom_plans', 'trial', 'capacity.unavailable', 'unavailable',
        ],
        'quote' => ['base_price', 'discount', 'exact_adjustment', 'final_price', 'expires_at', 'confirm'],
        'payment' => [
            'wallet.title', 'wallet.confirm',
            'card.title', 'card.instructions',
            'gift_card.title', 'gift_card.instructions',
            'usdt.title', 'usdt.instructions',
            'zarinpal.title', 'zarinpal.instructions',
            'nowpayments.title', 'nowpayments.instructions',
        ],
        'order' => [
            'created', 'awaiting_payment', 'paid', 'provisioning', 'delayed', 'completed', 'canceled', 'review_required',
        ],
        'service' => [
            'details', 'usage', 'expiry', 'qr', 'link_delivery', 'renew', 'addons', 'state_change',
            'plan_change', 'location_change', 'protocol_change', 'delete', 'import', 'transfer', 'sync',
        ],
        'agent' => ['application', 'review', 'approval', 'rejection', 'pricing', 'single_purchase', 'bulk_purchase', 'reports'],
        'wallet' => ['top_up', 'balance', 'transfer', 'hold', 'debit', 'credit', 'refund', 'correction'],
        'promotion' => ['discount', 'gift_code', 'referral', 'reward.pending', 'reward.released', 'reward.reversed'],
        'ticket' => ['create', 'category', 'assignment', 'reply', 'close', 'reopen', 'rating'],
        'broadcast' => ['preview', 'schedule', 'progress', 'pause', 'resume', 'cancel', 'edit', 'pin', 'unpin', 'delete'],
        'admin' => ['user_search', 'receipt_review', 'service_repair', 'batch_grant', 'reports', 'configuration'],
        'alert' => ['customer_impacting', 'financial', 'security', 'integration', 'queue', 'backup', 'update'],
        'installer' => ['title', 'preflight', 'passed', 'failed', 'locked'],
        'updater' => ['available', 'preflight', 'backup', 'apply', 'rollback', 'completed'],
        'backup' => ['started', 'completed', 'failed', 'restore', 'verified'],
        'error' => ['generic', 'unauthorized', 'forbidden', 'validation', 'expired', 'unavailable'],
    ],
    'button_keys' => [
        'menu.customer', 'menu.agent', 'menu.administrator', 'menu.back', 'menu.cancel', 'menu.confirm',
        'quote.confirm',
    ],
    'media_caption_keys' => [
        'broadcast.preview',
    ],
];
