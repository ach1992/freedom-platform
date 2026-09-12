<?php

declare(strict_types=1);

return [
    'list' => "Trial Service\n\n:items\n\nPage :page of :total_pages — :total_items available option(s)\n\nBrowsing does not reserve capacity or consume your Trial entitlement.",
    'list_item' => '#:number — :plan\nTrial data: :data\nTrial duration: :duration days',
    'empty' => "Trial Service\n\nNo Trial option is currently available for your account.\n\nEligibility, availability and capacity are checked again when a Trial is actually requested.",
    'offering_button' => 'Trial option #:number',
    'previous' => 'Previous',
    'next' => 'Next',
    'detail' => "Trial Service Option\n\nCategory: :category\nPlan: :plan\nMode: :mode\nTrial data: :data\nTrial duration: :duration days\nPhone verification: :phone\nChannel membership: :membership\n\nThis screen is read-only. No Trial reservation, capacity hold, Order, provisioning or membership-provider lookup has been created.",
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
