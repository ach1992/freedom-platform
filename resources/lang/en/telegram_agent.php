<?php

declare(strict_types=1);

return [
    'not_available' => 'Not available',
    'application' => [
        'not_submitted' => "Cooperation Request\n\nNo cooperation request has been submitted yet. Use the button below to submit one.",
        'status' => "Cooperation Request\n\nCurrent status: :status",
        'submit_button' => 'Submit cooperation request',
        'unavailable_notice' => 'The request could not be submitted in the current state. Your account and application state were rechecked.',
    ],
    'agent' => [
        'status' => "Agent Menu\n\nAgent status: :status\nApproved at: :approved_at\n\nThis section currently shows your Agent status. Additional Agent journeys remain separate steps.",
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
