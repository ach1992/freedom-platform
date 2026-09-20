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
        'status' => "Agent Menu\n\nAgent status: :status\nMember since: :joined_at\nApproved at: :approved_at\nServices purchased as Agent: :purchase_count\n\nChoose an available Agent action below.",
    ],
    'purchase' => [
        'single_button' => 'Single-service purchase',
        'agent_price_note' => 'The listed amount is the base price. Your final Agent price is resolved and snapshotted when the quote is created.',
        'benefit_code_unavailable' => 'Benefit codes are not available for Agent purchases in this flow.',
    ],
    'bulk' => [
        'button' => 'Bulk purchase',
        'select' => "Bulk Purchase\n\nChoose already-paid Agent purchases that have not yet been materialized as Orders.\nSelected: :selected of :total\nPage: :page/:pages",
        'empty' => 'There are no eligible paid Agent purchases waiting for Order materialization.',
        'review_button' => 'Review :count selected',
        'cancel_button' => 'Cancel',
        'review' => "Review Bulk Purchase\n\nSelected: :count\nTotal captured amount: :total IRR\n\n:items\n\nConfirm to materialize these accepted purchase settlements through the canonical bulk Order authority.",
        'more_items' => '... and :count more selected purchases',
        'confirm_button' => 'Confirm bulk purchase',
        'result' => "Bulk Purchase Result\n\nBulk Order: :bulk_order\nSucceeded: :succeeded\nFailed: :failed\nReplay/recovery: :replayed",
        'yes' => 'Yes',
        'no' => 'No',
        'retry_button' => 'Retry failed items',
        'done_button' => 'Back to Agent menu',
        'notice' => [
            'selection_limit' => 'A bulk request can contain at most 50 purchases.',
            'selection_stale' => 'One or more selected purchases are no longer eligible. The selection was refreshed.',
            'selection_empty' => 'Select at least one eligible purchase before review.',
            'retry_unavailable' => 'The failed items could not be retried in the current account state. Successful items were preserved.',
        ],
    ],
    'services' => [
        'button' => 'Purchased services',
    ],
    'report' => [
        'button' => 'Agent report',
        'summary' => "Agent Report\n\nPeriod: :period\nPurchases: :purchase_count\nGross paid: :spending_irr IRR\nMaterialized sales/orders: :sales_count\nGross materialized order value: :sales_irr IRR\nPurchased services: :service_count\nRecent offerings: :recent",
        'period' => [
            'today' => 'Today',
            '7d' => 'Last 7 days',
            '30d' => 'Last 30 days',
            'all' => 'All time',
        ],
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
