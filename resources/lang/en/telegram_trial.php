<?php

declare(strict_types=1);

return [
    'list' => "Trial Service\n\n:items\n\nPage :page of :total_pages — :total_items available option(s)\n\nBrowsing does not reserve capacity or consume your Trial entitlement.",
    'list_item' => '#:number — :plan\nTrial data: :data\nTrial duration: :duration days',
    'empty' => "Trial Service\n\nNo Trial option is currently available for your account.\n\nEligibility, availability and capacity are checked again when a Trial is actually requested.",
    'offering_button' => 'Trial option #:number',
    'previous' => 'Previous',
    'next' => 'Next',
    'detail' => "Trial Service Option\n\nCategory: :category\nPlan: :plan\nMode: :mode\nTrial data: :data\nTrial duration: :duration days\nPhone verification: :phone\nChannel membership: :membership\n\nNo capacity reservation, Trial-entitlement consumption, Order or provisioning effect occurs until you press “Confirm Trial” on the final confirmation screen.",
    'start_claim' => 'Continue Trial request',
    'route_prompt' => "Choose Trial server\n\nSelect an option. Choices are revalidated at final confirmation and no capacity has been reserved yet.",
    'route_auto' => 'Automatic server selection',
    'protocol_prompt' => "Choose Trial protocol\n\nSelect a protocol. The choice is revalidated at final confirmation.",
    'protocol_auto' => 'Automatic protocol selection',
    'confirm' => "Confirm Trial\n\nPlan: :plan\nData: :data\nDuration: :duration days\nServer: :server\nProtocol: :protocol\nFallback: :fallback\n\nPressing Confirm rechecks current eligibility and required membership, consumes the Trial entitlement/capacity, creates the zero-cost Order and queues provisioning.",
    'confirm_button' => 'Confirm Trial',
    'claim_unavailable' => "The Trial request cannot be completed under the current conditions.\n\nEligibility, membership, route and capacity were rechecked. The current Trial list is shown so you can choose again.",
    'queued' => "Your Trial was accepted and queued for provisioning.\n\nService ID: :service\nData: :data\nDuration: :duration days\nFinal server: :server\nProtocol: :protocol\nFallback: :fallback\n\nProvider creation is performed separately through the protected provisioning queue. Connection details will be delivered through the protected delivery path after the service is ready. Use Check status to read the current local provisioning state without starting another provider action.",
    'status_check' => 'Check status',
    'status' => [
        'succeeded' => "Your Trial service is ready.\n\nService ID: :service\nData: :data\nDuration: :duration days\n\nOpen My Services for the canonical service view and protected delivery actions.",
        'failed_final' => "Trial provisioning could not be completed.\n\nService ID: :service\n\nNo provider retry was started from Telegram. Return to the main menu and use the normal support path if you need help.",
        'needs_review' => "Trial provisioning needs manual review before a trustworthy result can be shown.\n\nService ID: :service\n\nNo provider retry was started from Telegram. Return to the main menu and use the normal support path if needed.",
        'unavailable' => "The current Trial provisioning status cannot be safely resolved.\n\nService ID: :service\n\nNo provider action was started. Return to the main menu and try again later or use the normal support path.",
        'my_services' => 'Open My Services',
    ],
    'fallback' => [
        'not_allowed' => 'Fallback is not enabled for this Trial.',
        'allowed' => 'If the primary route is unavailable, policy allows an authorized fallback route.',
        'allowed_with_details' => 'An authorized fallback may be used when needed: :details',
        'used' => 'An authorized fallback route was used.',
        'not_used' => 'Not used.',
    ],
    'phone' => [
        'none' => 'Not required',
        'telegram_contact_only' => 'Verified Telegram contact required',
        'sms_otp_only' => 'Verified SMS OTP required',
        'either' => 'Verified Telegram contact or SMS OTP required',
        'both' => 'Both verified Telegram contact and SMS OTP required',
    ],
    'membership' => [
        'required' => 'Required when the Trial is requested',
        'not_required' => 'Not required by this Trial policy',
    ],
];
