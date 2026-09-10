<?php

declare(strict_types=1);

return [
    'not_available' => 'ثبت نشده',
    'application' => [
        'not_submitted' => "درخواست همکاری\n\nهنوز درخواست همکاری ثبت نشده است. برای ثبت درخواست، دکمه زیر را انتخاب کنید.",
        'status' => "درخواست همکاری\n\nوضعیت فعلی: :status",
        'submit_button' => 'ثبت درخواست همکاری',
        'unavailable_notice' => 'ثبت درخواست در وضعیت فعلی امکان‌پذیر نیست. وضعیت حساب و درخواست دوباره بررسی شد.',
    ],
    'agent' => [
        'status' => "منوی نماینده\n\nوضعیت نمایندگی: :status\nتاریخ تأیید: :approved_at\n\nدر این بخش فعلاً وضعیت نمایندگی نمایش داده می‌شود. سایر مسیرهای نماینده در مراحل جداگانه تکمیل می‌شوند.",
    ],
    'states' => [
        'application' => [
            'submitted' => 'ثبت‌شده',
            'under_review' => 'در حال بررسی',
            'approved' => 'تأییدشده',
            'rejected' => 'ردشده',
            'withdrawn' => 'پس‌گرفته‌شده',
        ],
        'agent' => [
            'active' => 'فعال',
            'limited' => 'محدود',
            'suspended' => 'تعلیق‌شده',
        ],
        'unknown' => 'نامشخص',
    ],
];
