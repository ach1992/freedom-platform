<?php

declare(strict_types=1);

return [
    'not_available' => 'Not available',
    'application' => [
        'not_submitted' => "Cooperation Request\n\nBefore submitting:\n- The request is reviewed manually using the account information already held by the platform, including account age, successful purchases, refund history, identity status, risk signals and support history where authorized.\n- Submitting starts a review. It does not guarantee approval, pricing, discounts or other commercial terms.\n- Only one active request is allowed. A later request after a terminal decision follows the configured cooldown or manual-release policy.\n\nThis initial request does not require additional Agent-specific form fields.",
        'status' => "Cooperation Request\n\nCurrent status: :status",
        'submit_button' => 'Submit cooperation request',
        'unavailable_notice' => 'The request could not be submitted in the current state. Your account and application state were rechecked.',
    ],
    'agent' => [
        'status' => "Agent Menu\n\nAgent status: :status\nMember since: :joined_at\nApproved at: :approved_at\n\nChoose an available Agent action below.",
    ],
    'purchase' => [
        'single_button' => 'Single-service purchase',
        'agent_price_note' => 'The listed amount is the base price. Your final Agent price is resolved and snapshotted when the quote is created.',
        'benefit_code_unavailable' => 'Benefit codes are not available for Agent purchases in this flow.',
    ],
    'services' => [
        'button' => 'Purchased services',
    ],
    'states' => [
        'application' => [
            'submitted' => 'Submitted',
            'under_review' => 'Under review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'withdrawn' => 'Withdrawn',
        ],
        'agent' => [
            'active' => 'Active',
            'limited' => 'Limited',
            'suspended' => 'Suspended',
        ],
        'unknown' => 'Unknown',
    ],
];
